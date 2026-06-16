<?php

namespace App\Services\Payments\Email;

use App\Services\Payments\Email\Parsers\AbstractBankEmailParser;

/**
 * Given an email's From address + subject, decides which bank it belongs to
 * (from config('payment_signals.banks')) and returns the matching parser plus
 * the payer-bank short code. Returns null if no configured bank claims it.
 */
class BankEmailRouter
{
    /**
     * @return array{parser: AbstractBankEmailParser, short_code: string, bank_key: string}|null
     */
    public function route(string $from, string $subject): ?array
    {
        $from = mb_strtolower($from);
        $subject = mb_strtolower($subject);

        foreach (config('payment_signals.banks', []) as $bankKey => $cfg) {
            $fromMatch = false;
            foreach ($cfg['from_contains'] ?? [] as $needle) {
                if ($needle !== '' && str_contains($from, mb_strtolower($needle))) {
                    $fromMatch = true;
                    break;
                }
            }
            if (!$fromMatch) {
                continue;
            }

            // Optional subject gate.
            $subjectNeedles = $cfg['subject_contains'] ?? [];
            if (!empty($subjectNeedles)) {
                $subjectMatch = false;
                foreach ($subjectNeedles as $needle) {
                    if ($needle !== '' && str_contains($subject, mb_strtolower($needle))) {
                        $subjectMatch = true;
                        break;
                    }
                }
                if (!$subjectMatch) {
                    continue;
                }
            }

            $parserClass = $cfg['parser'] ?? null;
            if (!$parserClass || !class_exists($parserClass)) {
                continue;
            }

            return [
                'parser'     => app($parserClass),
                'short_code' => $cfg['short_code'] ?? strtoupper($bankKey),
                'bank_key'   => $bankKey,
            ];
        }

        return null;
    }
}
