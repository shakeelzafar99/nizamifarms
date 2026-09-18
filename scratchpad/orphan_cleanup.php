<?php
// Clean up the round-3 HTTP-check leftovers: the take-out EXPENSE ledgers and their
// requests, whose take-outs the fixture teardown already removed.
// ⚠ The teardown only reversed the PURCHASE ledgers, so the expense leg was left posted —
// that is what pulled SUPPLIES_STOCK below the shelf. Reverse, then delete.
use App\Models\FIN\LedgerModel;
use App\Services\FIN\BalancePostingService;
use Illuminate\Support\Facades\DB;

$live = DB::table('t_fin_supply_takeout')->pluck('id')->all();
$orphans = DB::table('t_req_master')->whereNotNull('supply_takeout_id')
    ->whereNotIn('supply_takeout_id', $live ?: [0])->get();

$stock0 = (float) DB::table('t_fin_accounts')->where('account_code', 'SUPPLIES_STOCK')->value('current_balance');

DB::transaction(function () use ($orphans) {
    foreach ($orphans as $r) {
        if ($r->ledger_transaction_id) {
            $l = LedgerModel::find($r->ledger_transaction_id);
            if ($l) {
                if ($l->approval_status === LedgerModel::STATUS_APPROVED) {
                    (new BalancePostingService())->reverse($l);
                    echo "  reversed expense ledger #{$l->id} (Rs {$l->amount})\n";
                }
                $l->delete();
            }
        }
        DB::table('t_req_approval')->where('request_id', $r->id)->delete();
        DB::table('t_req_master')->where('id', $r->id)->delete();
        echo "  deleted {$r->request_number}\n";
    }
});

$stock1 = (float) DB::table('t_fin_accounts')->where('account_code', 'SUPPLIES_STOCK')->value('current_balance');
$shelf  = (float) DB::table('t_fin_supply_batch')->where('status', '!=', 'voided')->sum('cost_remaining');
printf("\n  SUPPLIES_STOCK %s -> %s   shelf %s   %s\n", number_format($stock0, 2), number_format($stock1, 2),
    number_format($shelf, 2), abs($stock1 - $shelf) < 0.005 ? 'AGREE ✓' : 'STILL DISAGREE');
printf("  residual supply rows — products:%d batches:%d packets:%d takeouts:%d\n",
    DB::table('t_fin_supply_product')->count(), DB::table('t_fin_supply_batch')->count(),
    DB::table('t_fin_supply_packet')->count(), DB::table('t_fin_supply_takeout')->count());
