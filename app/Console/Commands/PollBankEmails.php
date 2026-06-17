<?php

namespace App\Console\Commands;

use App\Models\FIN\PaymentSignal;
use App\Services\Payments\Email\BankEmailRouter;
use App\Services\Payments\Email\ImapBankEmailFetcher;
use App\Services\Payments\Signals\PaymentSignalMatcher;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Carbon\Carbon;

/**
 * Polls the support@ mailbox over IMAP for NEW bank credit-confirmation
 * emails, parses each via its bank parser, stores a signal row, and runs the
 * matcher. Scheduled every minute; self-throttles to a no-op when the feature
 * switch is off or IMAP isn't configured.
 *
 * Start-from-now: on first run for a folder we seed the cursor to the current
 * max UID so historical mail is never processed.
 */
class PollBankEmails extends Command
{
    protected $signature = 'payments:poll-bank-emails {--limit=}';
    protected $description = 'Read new bank confirmation emails over IMAP and match them to orders';

    public function handle(
        ImapBankEmailFetcher $fetcher,
        BankEmailRouter $router,
        PaymentSignalMatcher $matcher
    ): int {
        if (!config('payment_signals.enabled')) {
            return self::SUCCESS;
        }
        $imap = config('payment_signals.imap');
        if (empty($imap['host']) || empty($imap['username']) || empty($imap['password'])) {
            return self::SUCCESS; // not configured yet
        }
        if (!ImapBankEmailFetcher::extensionAvailable()) {
            Log::warning('PollBankEmails: PHP imap extension not available; skipping.');
            return self::SUCCESS;
        }

        $limit = (int) ($this->option('limit') ?: ($imap['batch_size'] ?? 25));
        $mailbox = $imap['username'];
        $folder = $imap['folder'] ?? 'INBOX';

        $conn = null;
        try {
            $conn = $fetcher->connect();

            // Resolve / seed the cursor.
            $cursor = DB::table('t_fin_imap_cursor')
                ->where('mailbox', $mailbox)->where('folder', $folder)->first();

            if (!$cursor) {
                $seed = $fetcher->currentMaxUid($conn);
                DB::table('t_fin_imap_cursor')->insert([
                    'mailbox'       => $mailbox,
                    'folder'        => $folder,
                    'last_seen_uid' => $seed,
                    'last_polled_at'=> now(),
                    'created_at'    => now(),
                ]);
                Log::info('PollBankEmails: cursor seeded (start-from-now)', [
                    'mailbox' => $mailbox, 'folder' => $folder, 'seed_uid' => $seed,
                ]);
                return self::SUCCESS; // nothing historical to process
            }

            $emails = $fetcher->fetchNewSince($conn, (int) $cursor->last_seen_uid, $limit);
            if (empty($emails)) {
                DB::table('t_fin_imap_cursor')
                    ->where('id', $cursor->id)->update(['last_polled_at' => now()]);
                return self::SUCCESS;
            }

            $maxUid = (int) $cursor->last_seen_uid;
            $created = 0;
            $skippedRoute = 0;
            $skippedParse = 0;
            $skippedDup = 0;

            foreach ($emails as $email) {
                $maxUid = max($maxUid, $email['uid']);

                $route = $router->route($email['from'], $email['subject']);
                if (!$route) {
                    $skippedRoute++;
                    Log::debug('PollBankEmails: email not from a configured bank', [
                        'uid' => $email['uid'], 'from' => $email['from'], 'subject' => $email['subject'],
                    ]);
                    continue; // not from a configured bank
                }

                $parsed = $route['parser']->parse($email['subject'], $email['body']);
                if (!$parsed) {
                    $skippedParse++;
                    Log::debug('PollBankEmails: bank email did not parse as a credit', [
                        'uid' => $email['uid'], 'from' => $email['from'], 'subject' => $email['subject'],
                    ]);
                    continue; // recognised sender but not a credit confirmation
                }

                // Idempotency: unique (folder, uid).
                $exists = PaymentSignal::query()
                    ->where('email_folder', $folder)
                    ->where('email_uid', (string) $email['uid'])
                    ->exists();
                if ($exists) {
                    $skippedDup++;
                    continue;
                }

                $signal = PaymentSignal::create([
                    'source'                          => PaymentSignal::SOURCE_EMAIL,
                    'email_uid'                       => (string) $email['uid'],
                    'email_folder'                    => $folder,
                    'email_from'                      => mb_substr($email['from'], 0, 255),
                    'email_subject'                   => mb_substr($email['subject'], 0, 500),
                    'email_received_at'               => $this->parseDate($email['date']),
                    'extracted_amount'                => $parsed['amount'],
                    'extracted_ref'                   => $parsed['reference'],
                    'extracted_sender_name'           => $parsed['sender_name'],
                    'extracted_sender_account_masked' => $parsed['sender_account_masked'],
                    'extracted_sender_bank'           => $route['short_code'],
                    'extracted_txn_datetime'          => $this->parseDate($parsed['txn_datetime'] ?? null),
                    'extraction_confidence'           => $parsed['confidence'],
                    'extraction_raw_text'             => mb_substr($email['body'], 0, 60000),
                    'extractor_version'               => $route['parser']->version(),
                    'status'                          => PaymentSignal::STATUS_NEW,
                ]);

                $matcher->match($signal->fresh());
                $created++;
            }

            DB::table('t_fin_imap_cursor')->where('id', $cursor->id)->update([
                'last_seen_uid'  => $maxUid,
                'last_polled_at' => now(),
            ]);

            Log::info('PollBankEmails: poll summary', [
                'fetched'       => count($emails),
                'created'       => $created,
                'skipped_route' => $skippedRoute,
                'skipped_parse' => $skippedParse,
                'skipped_dup'   => $skippedDup,
                'cursor_from'   => (int) $cursor->last_seen_uid,
                'cursor_to'     => $maxUid,
            ]);
        } catch (\Throwable $e) {
            Log::error('PollBankEmails failed', ['error' => $e->getMessage()]);
            return self::SUCCESS; // never crash the scheduler
        } finally {
            if ($conn) {
                @imap_close($conn);
            }
        }

        return self::SUCCESS;
    }

    private function parseDate(?string $value): ?string
    {
        if (!$value) {
            return null;
        }
        try {
            return Carbon::parse($value)->format('Y-m-d H:i:s');
        } catch (\Throwable $e) {
            return null;
        }
    }
}
