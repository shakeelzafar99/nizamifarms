<?php

namespace App\Services\CRM;

/**
 * The ONE server-side decoder for the store scale's weight-embedded EAN-13.
 *
 * PHP port of NizamiFarmsMobile/src/utils/barcodeDecode.js. Extracted from
 * OvernightStorageController (which now calls this) so Storage/Supplies does not
 * become a THIRD copy of the same 20 lines — a divergence between them would mean
 * two screens reading the same physical label as two different weights.
 *
 * Format: digit 1 = in-store flag '2', digits 2-7 = Czerlop PLU,
 *         digits 8-12 = weight in grams, digit 13 = EAN-13 check digit.
 * Example: 2000002020509 -> PLU 2, 2.050 kg.
 *
 * ⚠⚠ A VALID CHECK DIGIT IS NOT PROOF OF A CORRECT READ. Single wrong digits are
 * always caught, but paired flips whose weighted deltas cancel mod 10 are invisible
 * — a real 0.505 kg label once decoded as 9.205 kg with a valid checksum
 * (Aug-28-2026, order SH-22402). Callers that store a scanned weight MUST add their
 * own plausibility guard on top of this; the decoder cannot do it, because it only
 * ever sees one code at a time and has nothing to compare it against.
 */
class WeightBarcodeDecoder
{
    /** Every scale label is exactly this long. */
    public const LENGTH = 13;

    /** Digit 1 of an in-store weight barcode. A retail EAN starts with anything else. */
    public const IN_STORE_FLAG = '2';

    /**
     * Decode a raw scanned string.
     *
     * ⭐ `units` is the SAME five digits as `weight_kg`, read as a plain integer
     * instead of as grams. The barcode does not say which reading is meant — the
     * scale writes grams for a weighed product and a PIECE COUNT for one printed
     * in pieces mode, into the identical slot. Only the PRODUCT knows which it is
     * (`t_crm_prod_product.sell_unit`), so this decoder returns both readings and
     * lets the caller pick. Every existing caller reads `weight_kg` and is
     * unaffected by the addition.
     *
     * @return array{plu:int,weight_kg:float,units:int,raw:string}|null  null when it is
     *         not a valid in-store scale label (wrong length, wrong flag, bad check
     *         digit, or a PLU of zero).
     */
    public function decode(?string $raw): ?array
    {
        $code = preg_replace('/\D/', '', (string) $raw);

        if (strlen($code) !== self::LENGTH || $code[0] !== self::IN_STORE_FLAG) {
            return null;
        }

        if (!$this->checkDigitValid($code)) {
            return null;
        }

        $plu = (int) substr($code, 1, 6);
        if ($plu < 1) {
            return null;
        }

        $field = (int) substr($code, 7, 5);

        return [
            'plu' => $plu,
            'weight_kg' => $field / 1000,
            'units' => $field,
            'raw' => $code,
        ];
    }

    /**
     * Why the code was rejected, for a message a store user can act on.
     * Returns null when the code decodes fine.
     */
    public function rejectionReason(?string $raw): ?string
    {
        $code = preg_replace('/\D/', '', (string) $raw);

        if (strlen($code) !== self::LENGTH) {
            return 'not_13_digits';
        }
        if ($code[0] !== self::IN_STORE_FLAG) {
            return 'not_instore_flag';
        }
        if (!$this->checkDigitValid($code)) {
            return 'bad_check_digit';
        }
        if ((int) substr($code, 1, 6) < 1) {
            return 'no_plu';
        }

        return null;
    }

    /** Standard EAN-13: odd positions x1, even x3, total must round up to a multiple of 10. */
    private function checkDigitValid(string $code): bool
    {
        $sum = 0;
        for ($i = 0; $i < 12; $i++) {
            $n = (int) $code[$i];
            $sum += ($i % 2 === 0) ? $n : $n * 3;
        }

        return ((10 - ($sum % 10)) % 10) === (int) $code[12];
    }
}
