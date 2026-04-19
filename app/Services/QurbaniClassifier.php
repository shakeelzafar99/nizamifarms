<?php

namespace App\Services;

use App\Models\FIN\ConfigModel;
use App\Models\WhatsApp\ConversationModel;
use App\Models\WhatsApp\MessageModel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * Determines whether a WhatsApp conversation should be classified as
 * "Qurbani". Used to populate the Qurbani tab and a badge on the
 * All Messages list.
 *
 * A conversation is considered Qurbani when ANY of:
 *   1. Customer has a Qurbani order for the configured active year
 *      (definition mirrors CampaignFilterService::qurbani_year).
 *   2. At least one qurbani-flagged template (`is_qurbani_only = 1`)
 *      has been sent to this conversation.
 *   3. Any of the last N inbound messages contains one of the configured
 *      keywords (case-insensitive, word-ish match). N defaults to 5.
 *
 * Re-evaluations are cheap (few queries, short lookback).
 *
 * Settings are stored in `t_fin_config`:
 *   - whatsapp_qurbani_enabled   (string "0"/"1", default "1")
 *   - whatsapp_qurbani_keywords  (comma-separated, default below)
 *   - whatsapp_qurbani_lookback  (int, default 5)
 *   - whatsapp_qurbani_year      (int, blank = current year)
 *
 * The feature ships with a safe default keyword list; "beef" is
 * deliberately excluded because it matches regular meat orders too.
 */
class QurbaniClassifier
{
    public const CFG_ENABLED   = 'whatsapp_qurbani_enabled';
    public const CFG_KEYWORDS  = 'whatsapp_qurbani_keywords';
    public const CFG_LOOKBACK  = 'whatsapp_qurbani_lookback';
    public const CFG_YEAR      = 'whatsapp_qurbani_year';

    public const DEFAULT_KEYWORDS = 'qurbani,qurbaani,qurbany,eid,eid ul adha,bakra eid,bakra,goat,hissa,share,cow,bull';
    public const DEFAULT_LOOKBACK = 5;

    /**
     * Master switch. When disabled, classification is a no-op and the
     * front-ends hide the Qurbani tab / badge entirely.
     */
    public function isEnabled(): bool
    {
        return (string) ConfigModel::get(self::CFG_ENABLED, '1') === '1';
    }

    public function getSettings(): array
    {
        return [
            'enabled'   => $this->isEnabled(),
            'keywords'  => $this->getKeywords(),
            'lookback'  => $this->getLookback(),
            'year'      => $this->getActiveYear(),
        ];
    }

    public function updateSettings(array $input): array
    {
        if (array_key_exists('enabled', $input)) {
            ConfigModel::set(self::CFG_ENABLED, ((bool) $input['enabled']) ? '1' : '0', 'WhatsApp Qurbani tab master switch');
        }
        if (array_key_exists('keywords', $input)) {
            $kw = is_array($input['keywords'])
                ? implode(',', array_map('trim', $input['keywords']))
                : trim((string) $input['keywords']);
            ConfigModel::set(self::CFG_KEYWORDS, $kw, 'Comma-separated keywords used to auto-flag Qurbani conversations');
        }
        if (array_key_exists('lookback', $input)) {
            $n = max(1, min(20, (int) $input['lookback']));
            ConfigModel::set(self::CFG_LOOKBACK, (string) $n, 'How many recent inbound messages to scan for Qurbani keywords');
        }
        if (array_key_exists('year', $input) && $input['year'] !== '' && $input['year'] !== null) {
            $y = (int) $input['year'];
            if ($y >= 2000 && $y <= 2100) {
                ConfigModel::set(self::CFG_YEAR, (string) $y, 'Active Qurbani year for auto-classification');
            }
        }
        return $this->getSettings();
    }

    public function getKeywords(): array
    {
        $raw = (string) ConfigModel::get(self::CFG_KEYWORDS, self::DEFAULT_KEYWORDS);
        $parts = array_filter(array_map('trim', explode(',', $raw)), fn($v) => $v !== '');
        return array_values(array_unique(array_map('mb_strtolower', $parts)));
    }

    public function getLookback(): int
    {
        $n = (int) ConfigModel::get(self::CFG_LOOKBACK, self::DEFAULT_LOOKBACK);
        return $n > 0 ? min(20, $n) : self::DEFAULT_LOOKBACK;
    }

    public function getActiveYear(): int
    {
        $configured = (int) ConfigModel::get(self::CFG_YEAR, 0);
        return $configured > 0 ? $configured : (int) date('Y');
    }

    /**
     * Classify a single conversation NOW. Persists is_qurbani / reason
     * on the conversation row if the classification changed.
     *
     * Returns array: ['is_qurbani' => bool, 'reason' => ?string, 'changed' => bool]
     */
    public function classify(ConversationModel $conv, ?array $recentInboundOverride = null): array
    {
        if (!$this->isEnabled()) {
            return ['is_qurbani' => (bool) $conv->is_qurbani, 'reason' => $conv->qurbani_flag_reason, 'changed' => false];
        }

        [$flag, $reason] = $this->evaluate($conv, $recentInboundOverride);

        $changed = false;
        if (((bool) $conv->is_qurbani) !== $flag || ($conv->qurbani_flag_reason ?? '') !== ($reason ?? '')) {
            $changed = true;
            try {
                $conv->update([
                    'is_qurbani' => $flag,
                    'qurbani_flag_reason' => $reason,
                    'qurbani_flagged_at' => $flag ? now() : null,
                ]);
            } catch (\Throwable $e) {
                Log::debug('QurbaniClassifier: failed to persist flag', [
                    'conversation_id' => $conv->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return ['is_qurbani' => $flag, 'reason' => $reason, 'changed' => $changed];
    }

    /**
     * Run classification across every conversation. Used by the
     * "Rescan" admin button. Returns counts for the UI.
     */
    public function rescanAll(): array
    {
        $total = 0; $flagged = 0; $cleared = 0;
        if (!Schema::hasTable('t_wa_conversations')) {
            return compact('total', 'flagged', 'cleared');
        }

        ConversationModel::query()
            ->orderBy('id')
            ->chunk(200, function ($chunk) use (&$total, &$flagged, &$cleared) {
                foreach ($chunk as $conv) {
                    $before = (bool) $conv->is_qurbani;
                    $res = $this->classify($conv);
                    $total++;
                    if (!$before && $res['is_qurbani']) $flagged++;
                    if ($before && !$res['is_qurbani']) $cleared++;
                }
            });

        return compact('total', 'flagged', 'cleared');
    }

    // ───────────────────────────────────────────────────────────────────

    /**
     * @return array{0:bool,1:?string}
     */
    protected function evaluate(ConversationModel $conv, ?array $recentInboundOverride = null): array
    {
        // 1) Customer has a Qurbani order for the active year.
        if ($conv->customer_id && $this->customerHasQurbaniOrder((int) $conv->customer_id, $this->getActiveYear())) {
            return [true, 'qurbani_order'];
        }

        // 2) We sent a qurbani-only template to this conversation.
        if (Schema::hasColumn('t_wa_templates', 'is_qurbani_only')) {
            $sentQurbaniTemplate = DB::table('t_wa_messages as m')
                ->join('t_wa_templates as t', 't.name', '=', 'm.template_name')
                ->where('m.conversation_id', $conv->id)
                ->where('m.direction', 'outbound')
                ->where('t.is_qurbani_only', 1)
                ->exists();
            if ($sentQurbaniTemplate) {
                return [true, 'qurbani_template_sent'];
            }
        }

        // 3) Recent inbound message text contains a configured keyword.
        $keywords = $this->getKeywords();
        if (!empty($keywords)) {
            $inbound = $recentInboundOverride;
            if ($inbound === null) {
                $inbound = MessageModel::where('conversation_id', $conv->id)
                    ->where('direction', 'inbound')
                    ->orderByDesc('created_at')
                    ->limit($this->getLookback())
                    ->pluck('content')
                    ->filter()
                    ->map(fn($c) => (string) $c)
                    ->all();
            }
            foreach ($inbound as $text) {
                $lower = mb_strtolower((string) $text);
                foreach ($keywords as $kw) {
                    if ($kw === '') continue;
                    if (mb_strpos($lower, $kw) !== false) {
                        return [true, 'keyword:' . $kw];
                    }
                }
            }
        }

        return [false, null];
    }

    /**
     * Same definition as CampaignFilterService so the Qurbani dashboard,
     * campaign preview, and this classifier stay consistent.
     */
    protected function customerHasQurbaniOrder(int $customerId, int $year): bool
    {
        $prod = DB::table('t_crm_prod_order as o')
            ->where('o.customer_id', $customerId)
            ->whereYear('o.order_date', $year)
            ->where(function ($q) {
                $q->whereNotNull('o.qurbani_day')
                  ->orWhereExists(function ($inner) {
                      $inner->select(DB::raw(1))
                          ->from('t_crm_prod_order_line_item as li')
                          ->join('t_crm_prod_product as p', 'li.product_id', '=', 'p.id')
                          ->whereColumn('li.order_id', 'o.id')
                          ->whereRaw("LOWER(p.attribute_1) = 'qurbani'");
                  });
            })
            ->exists();

        if ($prod) return true;

        if (Schema::hasTable('t_crm_history_order') && Schema::hasTable('t_crm_history_order_line_item')) {
            return DB::table('t_crm_history_order as ho')
                ->where('ho.customer_id', $customerId)
                ->whereYear('ho.order_date', $year)
                ->whereExists(function ($inner) {
                    $inner->select(DB::raw(1))
                        ->from('t_crm_history_order_line_item as hli')
                        ->whereColumn('hli.order_id', 'ho.id')
                        ->where(function ($q) {
                            $q->whereRaw("LOWER(hli.name) LIKE '%qurbani%'")
                              ->orWhereRaw("LOWER(hli.name) LIKE '%hissa%'")
                              ->orWhereRaw("LOWER(COALESCE(hli.sku,'')) LIKE 'qur%'");
                        });
                })
                ->exists();
        }

        return false;
    }
}
