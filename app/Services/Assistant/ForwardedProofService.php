<?php

namespace App\Services\Assistant;

use App\Models\User;
use App\Services\Payments\Ocr\GeminiBankScreenshotExtractor;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Phase 5c — a customer's payment screenshot FORWARDED to the business WhatsApp
 * by a trusted staff number (Taimur), with the customer's name in the caption.
 *
 * WHY IT'S NOT THE NORMAL PROOF PATH: the forward arrives from TAIMUR'S number,
 * so WhatsApp has already stripped the customer's identity — the conversation
 * maps to him, not to the payer. Treating it as a customer chat would attach the
 * proof to the wrong person (or nobody). So we detect the trusted forwarder,
 * take the customer from the CAPTION, read the amount from the image, and raise
 * a confirmation CARD rather than writing anything.
 *
 * Nothing here records money or verifies a payment. It produces a pending card
 * that lands in the assistant chat AND in the money inbox's "waiting for your
 * confirm" list — because Taimur forwards on the move and reviews later (owner
 * ask 2026-07-19). Cards live 24 h. Verification still requires bank pairing.
 */
class ForwardedProofService
{
    public function __construct(
        private AssistantDraftService $drafts,
        private GeminiBankScreenshotExtractor $ocr,
    ) {
    }

    /**
     * @param int         $userId    The trusted forwarder (the staff member).
     * @param string      $imagePath Storage-relative path of the screenshot.
     * @param string|null $caption   Text sent with the image (should name the customer).
     */
    public function handle(int $userId, string $imagePath, ?string $caption): void
    {
        $user = User::find($userId);
        if (!$user) {
            return;
        }

        // Read the screenshot (same extractor the customer-proof pipeline uses).
        // A failed read is NOT fatal — the single-open-order rule can still fill
        // the amount, and if it can't we ask.
        $amount = null;
        $reference = null;
        try {
            $read = $this->ocr->extract($imagePath);
            $amount = $read['amount'] ?? null;
            $reference = $read['reference'] ?? null;
        } catch (\Throwable $e) {
            Log::warning('ForwardedProof: OCR failed', ['error' => $e->getMessage()]);
        }

        $customer = $this->resolveCustomerFromCaption($caption);

        if (!$customer) {
            $this->drafts->postMessageToChat($user, trim(
                '📩 You forwarded a payment screenshot'
                . ($amount ? ' for Rs ' . number_format($amount, 0) : '')
                . ", but I couldn't tell which customer it's for"
                . ($caption ? ' from "' . mb_substr(trim($caption), 0, 60) . '"' : ' (no name was sent with it)')
                . '. Reply with the customer name and I\'ll prepare the card.'
            ));
            return;
        }

        // SHOP customers take the shop-payment card (real FIFO payments, no
        // proof) — same branching rule as chat and the SMS pipeline. The
        // screenshot's amount is required there; without it, ask instead of
        // guessing. Bank unknown from a forward → the card offers the picker.
        $isShop = ($customer->customer_type ?? 'regular') === 'shop';
        if ($isShop && !$amount) {
            $this->drafts->postMessageToChat($user, '📩 Forwarded screenshot from shop '
                . trim(($customer->first_name ?? '') . ' ' . ($customer->last_name ?? ''))
                . ", but I couldn't read the amount off it. Tell me the amount and I'll prepare the shop-payment card.");
            return;
        }

        $args = ['customer_id' => $customer->id];
        if ($amount)    $args['amount'] = $amount;
        if ($reference) $args['reference'] = $reference;
        if (!$isShop)   $args['image_path'] = $imagePath;

        $res = $isShop
            ? $this->drafts->draftShopPayment($args, $user)
            : $this->drafts->draftPaymentProof($args, $user);

        if (!empty($res['draft_id'])) {
            $this->drafts->postCardToChat($user, (int) $res['draft_id'], (string) $res['summary'],
                $isShop ? '🏪 Forwarded shop payment —' : '📩 Forwarded proof —');
            Log::info('ForwardedProof: card raised', ['user' => $userId, 'customer' => $customer->id, 'draft' => $res['draft_id']]);
            return;
        }

        // Couldn't draft (several open orders and no readable amount, nothing in
        // approvals, etc.) — surface the reason as a question so it isn't lost.
        $this->drafts->postMessageToChat($user, '📩 Forwarded proof for '
            . trim(($customer->first_name ?? '') . ' ' . ($customer->last_name ?? ''))
            . ' — ' . ($res['error'] ?? 'I could not prepare a card for it.'));
    }

    /**
     * Which user (if any) is this WhatsApp number a trusted forwarder for.
     * Normalised through the same DB function the WhatsApp pipeline uses so a
     * stored 0300… and an inbound 92300… resolve to the same value.
     */
    public static function forwarderUserId(?string $waPhone): ?int
    {
        if (!$waPhone) return null;
        try {
            $norm = DB::selectOne('SELECT fn_normalize_phone(?) AS n', [$waPhone])->n ?? null;
        } catch (\Throwable $e) {
            $norm = null;
        }
        if (!$norm) return null;

        $uid = DB::table('t_ai_trusted_forwarders')
            ->where('phone', $norm)->where('is_active', 1)->value('user_id');

        return $uid ? (int) $uid : null;
    }

    /**
     * Pull a customer out of the caption. Strips the words people habitually
     * wrap a name in ("payment proof received for …") and matches what's left,
     * space-insensitively. Only an UNAMBIGUOUS single hit is accepted — if two
     * customers could match we ask rather than guess at someone's money.
     */
    private function resolveCustomerFromCaption(?string $caption)
    {
        $name = $this->cleanCaption($caption);
        if (mb_strlen($name) < 3) {
            return null;
        }
        $norm = mb_strtolower(str_replace(' ', '', $name));

        $hits = DB::table('t_crm_prod_customer')
            ->where(function ($w) use ($name, $norm) {
                $w->whereRaw("LOWER(REPLACE(CONCAT(COALESCE(first_name,''),COALESCE(last_name,'')),' ','')) LIKE ?", ['%' . $norm . '%'])
                  ->orWhere('first_name', 'like', '%' . $name . '%')
                  ->orWhere('last_name', 'like', '%' . $name . '%');
            })
            ->limit(5)
            ->get(['id', 'first_name', 'last_name', 'customer_type']);

        return $hits->count() === 1 ? $hits->first() : null;
    }

    private function cleanCaption(?string $caption): string
    {
        $t = mb_strtolower(trim((string) $caption));
        if ($t === '') return '';

        // Drop money figures, punctuation and the usual wrapper words.
        $t = preg_replace('/\b(?:rs\.?|pkr)\s*[0-9][0-9,\.]*/i', ' ', $t);
        $t = preg_replace('/[0-9,]+/', ' ', $t);
        $noise = ['payment', 'payments', 'proof', 'proofs', 'received', 'recieved', 'receipt',
                  'screenshot', 'transfer', 'transferred', 'paid', 'sent', 'send', 'from', 'for',
                  'customer', 'client', 'ka', 'ki', 'ke', 'ne', 'hai', 'please', 'plz', 'kindly',
                  'attach', 'attached', 'add', 'this', 'is', 'the', 'of'];
        $words = preg_split('/[^\p{L}]+/u', $t, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $kept = array_values(array_filter($words, fn($w) => !in_array($w, $noise, true) && mb_strlen($w) > 1));

        return trim(implode(' ', $kept));
    }
}
