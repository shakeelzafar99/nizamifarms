<?php

namespace App\Services\WhatsApp;

use Illuminate\Support\Facades\Schema;

/**
 * ONE definition of "an inbound message the system already answered, which must
 * never raise an unread badge" (Aug-2026).
 *
 * WHY THIS EXISTS
 * ---------------
 * When a customer taps the "Get bank details" quick-reply on their delivery
 * confirmation, the app answers instantly and automatically. That tap is not a
 * question for a human, so it must not light up the Messages badge and pull a
 * manager into a conversation that is already handled — but it MUST still
 * appear in the chat history, so it is stored like any other inbound message.
 *
 * WHY NOT THE OBVIOUS ROUTES
 *   - `t_wa_conversations.unread_count` is only a legacy fallback. The real
 *     badge is computed per-user from t_wa_messages vs
 *     t_wa_conversation_reads.last_read_at, so zeroing that counter changes
 *     nothing for anyone.
 *   - Saving our automatic answer with a non-null `sent_by` WOULD clear it (the
 *     unread queries suppress any inbound followed by a staff outbound), but
 *     that clause is per-message: it would also silently bury every OLDER
 *     unanswered customer message in the same chat. A real "where is my order?"
 *     would vanish from the unread list. Never do this.
 *
 * So the exemption is carried by the message row itself, and every unread query
 * calls exclude() below. Six queries mirror each other (web + mobile × badge,
 * inbox prefetch, per-conversation counts); keeping the column name and the
 * schema guard here means they can never drift apart.
 *
 * The column is added by database/migrations/delivery_confirmation_automation_aug2026.sql.
 * Everything is a no-op until that runs — exclude() simply adds no clause, so
 * the code is safe to deploy before the SQL.
 */
class UnreadQuery
{
    /** t_wa_messages flag: 1 = never counts toward anyone's unread badge. */
    const COLUMN = 'unread_exempt';

    /** Memoised per request — these queries run several times per page. */
    protected static ?bool $supported = null;

    /** Is the column present? Fails to FALSE so a schema problem can't hide messages. */
    public static function supported(): bool
    {
        if (self::$supported === null) {
            try {
                self::$supported = Schema::hasColumn('t_wa_messages', self::COLUMN);
            } catch (\Throwable $e) {
                self::$supported = false;
            }
        }
        return self::$supported;
    }

    /**
     * Exclude system-answered inbound rows from an unread query.
     *
     * @param  \Illuminate\Database\Query\Builder $query
     * @param  string $alias  table alias used for t_wa_messages (all six call sites use 'm')
     */
    public static function exclude($query, string $alias = 'm')
    {
        if (self::supported()) {
            $query->where($alias . '.' . self::COLUMN, 0);
        }
        return $query;
    }
}
