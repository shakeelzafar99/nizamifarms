<?php

namespace App\Services\FIN;

use App\Models\FIN\LedgerModel;
use App\Models\Request\RequestCategoryModel;
use App\Models\SysAdmin\RoleApprovalLevelModel;

/**
 * "Could this person have approved it themselves anyway?"
 *
 * Aug-2026, owner ruling. An ONLINE account transfer entered by Taimur sat in the L1 queue for
 * days with the money in neither account, because LedgerController::storeTransfer decided the
 * approval status from the MODE alone:
 *
 *     $approvalStatus = $mode === 'online' ? STATUS_PENDING : STATUS_APPROVED;
 *
 * — the creator was never consulted. Taimur is the only Level-2 approver in the system, and
 * `account_transfer` needs exactly Level 2, so the one person who could clear the queue was the
 * one filling it. The rule the owner actually wants is the one the internal-request flow has had
 * all along (RequestController::store auto-approves a request whose creator holds approval
 * rights): an approver does not queue their own work.
 *
 * THE RULE, stated once: a new ledger row skips the queue when its creator holds EVERY approval
 * level that row's category requires — i.e. they could have taken it to `approved` themselves,
 * unassisted, in the approvals screen. Holding only SOME of the required levels is not enough;
 * that row still needs a second pair of eyes and stays pending.
 *
 * Deliberately data-driven, not name-driven. Nothing here knows about "Taimur" — it reads the
 * category's requires_level_1 / requires_level_2 (editable in Request Settings) against the
 * user's roles. Today `account_transfer` = L2-only, so this resolves to Taimur alone. If a
 * second L2 approver is ever appointed, or a category's levels are re-configured, the rule
 * follows automatically instead of drifting out of date.
 *
 * SCOPE — this answers only "may we skip the queue", never "what should the pending status be".
 * Callers keep their own pending branch untouched, so a row that is NOT self-approved lands
 * exactly where it landed before this class existed (`pending` vs `pending_l1` vs `pending_l2`
 * differ in whether balances post early, and that is not this class's decision to make).
 *
 * NOT applied to: invoice / order_payment (the customer-collection queue lives in Online
 * Approvals and is reviewed against payment proof — a second pair of eyes is the point), or
 * employee_deposit (the approval IS the receiving till confirming the cash physically arrived).
 */
class SelfApprovalPolicy
{
    /**
     * Ledger transaction type → the Request Settings category whose approval levels govern it.
     * Mirrors the switch in LedgerController::approve() — kept in sync deliberately, since that
     * is the method that later decides how many approvals the row still needs.
     *
     * Types absent from this map have no approval category and are therefore never queued by
     * the callers in the first place, so they never reach this class.
     */
    private const CATEGORY_BY_TYPE = [
        LedgerModel::TYPE_TRANSFER         => 'account_transfer',
        LedgerModel::TYPE_VENDOR_PAYMENT   => 'vendor_payment',
        LedgerModel::TYPE_EXPENSE          => 'expense',
        LedgerModel::TYPE_SALARY_ADVANCE   => 'salary_advance',
    ];

    /**
     * Types this policy refuses to self-approve regardless of who created them, because their
     * approval is a genuine second-party check rather than a rubber stamp on your own action.
     */
    private const NEVER_SELF_APPROVE = [
        LedgerModel::TYPE_INVOICE,
        LedgerModel::TYPE_ORDER_PAYMENT,
        LedgerModel::TYPE_EMPLOYEE_DEPOSIT,
    ];

    /**
     * May $userId's brand-new $transactionType row skip the approval queue?
     *
     * False whenever we cannot prove otherwise — no user, unknown type, missing category, or a
     * category that needs a level this person does not hold. The caller's existing pending
     * branch then runs unchanged, so a wrong answer here can only ever be the safe one.
     */
    public function canSelfApprove(?string $transactionType, ?int $userId): bool
    {
        if (!$transactionType || !$userId) {
            return false;
        }

        if (in_array($transactionType, self::NEVER_SELF_APPROVE, true)) {
            return false;
        }

        $categoryCode = self::CATEGORY_BY_TYPE[$transactionType] ?? null;
        if (!$categoryCode) {
            return false;
        }

        $category = RequestCategoryModel::getByCode($categoryCode);
        if (!$category) {
            return false;
        }

        // No level required at all — the caller already approves these outright (vendor_payment
        // is configured this way today). Returning false keeps the credit for that decision with
        // the caller rather than silently duplicating it here.
        $requiredLevels = $category->getRequiredApprovalLevels();
        if (empty($requiredLevels)) {
            return false;
        }

        foreach ($requiredLevels as $level) {
            if (!$this->userHoldsLevel($userId, (int) $level)) {
                return false;
            }
        }

        return true;
    }

    /**
     * The audit sentence stamped into `comments` on a self-approved row, so a reader months
     * later can see WHY it never appeared in anyone's queue. Callers append this — a row that
     * skipped approval must say so in its own record, not only in the code that made it.
     */
    public function auditNote(string $transactionType, ?int $userId): string
    {
        $code = self::CATEGORY_BY_TYPE[$transactionType] ?? $transactionType;

        return "Auto-approved on creation — creator (User ID {$userId}) holds every approval level "
             . "'{$code}' requires, so this was not queued.";
    }

    /**
     * Wrapper around RoleApprovalLevelModel::userHasApprovalLevel, which dereferences
     * UserModel::find() without a null check and fatals on a stale/deleted user id. A creation
     * path must never die because of an approval LOOKUP, so a failure here reads as "no rights"
     * and the row simply follows the normal pending route.
     */
    private function userHoldsLevel(int $userId, int $level): bool
    {
        try {
            return RoleApprovalLevelModel::userHasApprovalLevel($userId, $level);
        } catch (\Throwable $e) {
            return false;
        }
    }
}
