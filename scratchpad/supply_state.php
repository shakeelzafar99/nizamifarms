<?php
// Read-only look at the replica's supplies tables. Run:
// php artisan tinker --execute='require "scratchpad/supply_state.php";'
use Illuminate\Support\Facades\DB;
$t = fn($s) => "\n──────── $s ────────\n";
echo $t('PRODUCTS');
foreach (DB::table('t_fin_supply_product')->orderBy('id')->get() as $p) {
    echo sprintf("#%d %-28s mode=%-7s plu=%-8s barcode=%-15s cat=%s active=%s created_by=%s at=%s\n",
        $p->id, $p->name, $p->mode, $p->plu ?? '-', $p->barcode ?? '-', $p->expense_category_name, $p->is_active, $p->created_by, $p->created_at);
}
echo $t('BATCHES');
foreach (DB::table('t_fin_supply_batch')->orderBy('id')->get() as $b) {
    echo sprintf("#%d prod=%s pkts=%s qty=%s/%s cost=%s/%s unit=%s date=%s src_acct=%s bank=%s status=%-12s ledger=%s by=%s uuid=%s at=%s\n",
        $b->id,$b->product_id,$b->packet_count ?? '-',$b->qty_remaining,$b->qty_total,$b->cost_remaining,$b->total_cost,
        $b->unit_cost ?? '-',$b->purchase_date,$b->payment_source_account_id ?? '-',$b->receiving_account_id ?? '-',
        $b->status,$b->ledger_id ?? '-',$b->booked_by,$b->client_uuid ?? '-',$b->created_at);
}
echo $t('PACKETS');
foreach (DB::table('t_fin_supply_packet')->orderBy('id')->get() as $p) {
    echo sprintf("#%d batch=%s seq=%s bc=%-15s plu=%-8s qty=%-8s cost=%-10s %-9s takeout=%s consumed=%s\n",
        $p->id,$p->batch_id,$p->seq,$p->barcode ?? '-',$p->plu ?? '-',$p->qty,$p->cost,$p->status,$p->takeout_id ?? '-',$p->consumed_at ?? '-');
}
echo $t('TAKEOUTS');
foreach (DB::table('t_fin_supply_takeout')->orderBy('id')->get() as $k) {
    echo sprintf("#%d prod=%s qty=%s %s cost=%s src=%-7s status=%-10s req=%s by=%s at=%s settled=%s\n",
        $k->id,$k->product_id,$k->qty,$k->unit,$k->cost,$k->source,$k->status,$k->request_id ?? '-',$k->taken_by,$k->taken_at,$k->settled_at ?? '-');
}
echo $t('LOG (last 40)');
foreach (DB::table('t_fin_supply_log')->orderByDesc('id')->limit(40)->get()->reverse() as $l) {
    echo sprintf("#%d %-10s prod=%s batch=%s pkt=%s takeout=%s qty=%s %s cost=%s src=%s by=%s at=%s\n",
        $l->id,$l->action,$l->product_id,$l->batch_id ?? '-',$l->packet_id ?? '-',$l->takeout_id ?? '-',
        $l->qty,$l->unit,$l->cost,$l->source ?? '-',$l->created_by,$l->created_at);
}
echo $t('REQUESTS WITH supply_takeout_id');
foreach (DB::table('t_req_master')->whereNotNull('supply_takeout_id')->orderBy('id')->get() as $r) {
    echo sprintf("#%d %s amt=%s cat=%s expdate=%s status=%-10s L1=%-8s L2=%-8s src_acct=%s settle=%s takeout=%s requester=%s\n",
        $r->id,$r->request_number,$r->amount,$r->expense_category,$r->expense_date,$r->status,
        $r->level_1_status ?? '-',$r->level_2_status ?? '-',$r->payment_source_account_id ?? '-',
        $r->settlement_status ?? '-',$r->supply_takeout_id,$r->requester_user_id);
}
echo $t('SUPPLIES_STOCK account + supply ledger rows');
$acct = DB::table('t_fin_accounts')->where('account_code','SUPPLIES_STOCK')->first();
echo $acct ? "acct #{$acct->id} {$acct->account_name} cat={$acct->account_category} bal={$acct->current_balance}\n" : "MISSING\n";
foreach (DB::table('t_fin_ledger')->where('transaction_type','supply_purchase')->orderBy('id')->get() as $g) {
    echo sprintf("led#%d %s type=%s amt=%s from=%s to=%s recv=%s status=%s desc=%s\n",
        $g->id,$g->transaction_date,$g->transaction_type,$g->amount,$g->from_account_id,$g->to_account_id,$g->receiving_account_id ?? '-',$g->approval_status,$g->description);
}
if ($acct) {
  echo "\nledger rows touching SUPPLIES_STOCK:\n";
  foreach (DB::table('t_fin_ledger')->where('from_account_id',$acct->id)->orWhere('to_account_id',$acct->id)->orderBy('id')->get() as $g) {
    echo sprintf("led#%d %s type=%-16s amt=%-10s from=%s to=%s status=%s | %s\n",
        $g->id,$g->transaction_date,$g->transaction_type,$g->amount,$g->from_account_id,$g->to_account_id,$g->approval_status,mb_substr($g->description,0,80));
  }
}
echo $t('approval switch');
print_r(DB::table('t_fin_config')->where('config_key','LIKE','%SUPPLY%')->orWhere('config_key','LIKE','%SUPPLIES%')->get()->all());
