<?php

namespace App\Services\Payments\Email;

use Illuminate\Support\Facades\Log;

/**
 * Fetches NEW bank emails over IMAP using PHP's built-in imap_* functions
 * (no Composer package). It pulls only messages with a UID greater than the
 * stored cursor, returns lightweight arrays (from / subject / date / plain
 * body / uid), and never deletes or marks anything on the server.
 *
 * The cursor itself lives in t_fin_imap_cursor and is managed by the calling
 * command — this class is a thin transport.
 */
class ImapBankEmailFetcher
{
    /** Is the PHP imap extension loaded on this server? */
    public static function extensionAvailable(): bool
    {
        return function_exists('imap_open');
    }

    /** Build the IMAP mailbox connection string from config. */
    public function mailboxString(): string
    {
        $c = config('payment_signals.imap');
        $flags = '/imap';
        $enc = strtolower((string) ($c['encryption'] ?? 'ssl'));
        if ($enc === 'ssl') {
            $flags .= '/ssl';
        } elseif ($enc === 'tls') {
            $flags .= '/tls';
        } else {
            $flags .= '/notls';
        }
        if (!($c['validate_cert'] ?? true)) {
            $flags .= '/novalidate-cert';
        }
        return '{' . $c['host'] . ':' . $c['port'] . $flags . '}' . ($c['folder'] ?? 'INBOX');
    }

    /**
     * Open a connection. Caller MUST imap_close() the returned resource.
     * Returns the imap resource, or throws on failure.
     *
     * @return \IMAP\Connection|resource
     */
    public function connect()
    {
        if (!self::extensionAvailable()) {
            throw new \RuntimeException('PHP imap extension is not enabled on this server.');
        }
        $c = config('payment_signals.imap');
        $conn = @imap_open($this->mailboxString(), $c['username'], $c['password'], 0, 1);
        if ($conn === false) {
            throw new \RuntimeException('IMAP connect failed: ' . imap_last_error());
        }
        return $conn;
    }

    /** Highest UID currently in the mailbox (used to seed the cursor). */
    public function currentMaxUid($conn): int
    {
        $count = imap_num_msg($conn);
        if ($count < 1) {
            return 0;
        }
        $uid = imap_uid($conn, $count);
        return $uid ?: 0;
    }

    /**
     * Fetch up to $limit emails with UID > $afterUid.
     *
     * @return array<int, array{uid:int, from:string, subject:string, date:?string, body:string}>
     */
    public function fetchNewSince($conn, int $afterUid, int $limit = 25): array
    {
        // "UID N:*" returns messages with UID >= N, but IMAP quirk: it always
        // returns at least the latest message even if its UID < N. So filter.
        $searchFrom = max($afterUid + 1, 1);
        $uids = @imap_search($conn, 'UID ' . $searchFrom . ':*', SE_UID);
        if (!is_array($uids)) {
            return [];
        }

        $uids = array_values(array_filter($uids, fn ($u) => (int) $u > $afterUid));
        sort($uids, SORT_NUMERIC);
        $uids = array_slice($uids, 0, $limit);

        $out = [];
        foreach ($uids as $uid) {
            $uid = (int) $uid;
            $overview = @imap_fetch_overview($conn, (string) $uid, FT_UID);
            if (!is_array($overview) || empty($overview[0])) {
                continue;
            }
            $ov = $overview[0];
            $out[] = [
                'uid'     => $uid,
                'from'    => $this->decodeMime($ov->from ?? ''),
                'subject' => $this->decodeMime($ov->subject ?? ''),
                'date'    => isset($ov->date) ? $ov->date : null,
                'body'    => $this->plainBody($conn, $uid),
            ];
        }
        return $out;
    }

    /** Extract a plain-text body from a message (handles multipart + HTML). */
    private function plainBody($conn, int $uid): string
    {
        $structure = @imap_fetchstructure($conn, $uid, FT_UID);
        if (!$structure) {
            return (string) @imap_body($conn, $uid, FT_UID);
        }

        // Simple, non-multipart message.
        if (empty($structure->parts)) {
            $raw = (string) @imap_body($conn, $uid, FT_UID);
            return $this->decodePart($raw, $structure->encoding ?? 0, $this->subtype($structure));
        }

        // Multipart: prefer text/plain, fall back to text/html.
        $plain = $this->findPart($conn, $uid, $structure->parts, 'plain');
        if ($plain !== null) {
            return $plain;
        }
        $html = $this->findPart($conn, $uid, $structure->parts, 'html');
        return $html ?? '';
    }

    /** Recursively search multipart sections for a text/{subtype} body. */
    private function findPart($conn, int $uid, array $parts, string $wantSubtype, string $prefix = ''): ?string
    {
        foreach ($parts as $index => $part) {
            $section = $prefix === '' ? (string) ($index + 1) : $prefix . '.' . ($index + 1);

            if (!empty($part->parts)) {
                $nested = $this->findPart($conn, $uid, $part->parts, $wantSubtype, $section);
                if ($nested !== null) {
                    return $nested;
                }
                continue;
            }

            $subtype = strtolower((string) ($part->subtype ?? ''));
            if (($part->type ?? 0) == 0 && $subtype === $wantSubtype) {
                $raw = (string) @imap_fetchbody($conn, $uid, $section, FT_UID);
                return $this->decodePart($raw, $part->encoding ?? 0, $subtype);
            }
        }
        return null;
    }

    private function subtype($structure): string
    {
        return strtolower((string) ($structure->subtype ?? 'plain'));
    }

    /** Decode transfer-encoding then, for HTML, strip tags to plain text. */
    private function decodePart(string $raw, int $encoding, string $subtype): string
    {
        switch ($encoding) {
            case 3: // base64
                $raw = base64_decode($raw, true) ?: $raw;
                break;
            case 4: // quoted-printable
                $raw = quoted_printable_decode($raw);
                break;
        }
        if ($subtype === 'html') {
            $raw = preg_replace('/<(script|style)\b[^>]*>.*?<\/\1>/is', ' ', $raw);
            $raw = preg_replace('/<br\s*\/?>/i', "\n", $raw);
            $raw = preg_replace('/<\/(p|div|tr|li|h[1-6])>/i', "\n", $raw);
            $raw = strip_tags($raw);
            $raw = html_entity_decode($raw, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        }
        return trim($raw);
    }

    private function decodeMime(string $value): string
    {
        $decoded = '';
        foreach (imap_mime_header_decode($value) as $part) {
            $charset = strtolower($part->charset);
            $text = $part->text;
            if ($charset !== 'default' && $charset !== 'utf-8' && function_exists('mb_convert_encoding')) {
                $text = @mb_convert_encoding($text, 'UTF-8', $charset) ?: $text;
            }
            $decoded .= $text;
        }
        return trim($decoded);
    }
}
