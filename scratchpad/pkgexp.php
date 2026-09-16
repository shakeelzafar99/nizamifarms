<?php
use Illuminate\Support\Facades\DB;
echo "── packaging expense accounts ──\n";
foreach (DB::table('t_fin_accounts')->where('account_name','like','%ackag%')->orWhere('account_code','like','%PACKAG%')->get() as $a)
    echo sprintf("  #%-4d %-34s %-22s cat=%-10s bal=%s\n",$a->id,$a->account_code,$a->account_name,$a->account_category,$a->current_balance);

echo "\n── approved expense REQUESTS with a packaging category ──\n";
$rows = DB::table('t_req_master as m')->leftJoin('t_sys_user as u','u.id','=','m.requester_user_id')
  ->where('m.expense_category','like','%ackag%')
  ->whereIn('m.status',['approved','pending'])
  ->select('m.id','m.request_number','m.amount','m.expense_category','m.expense_date','m.status',
           'm.ledger_transaction_id','m.settlement_transaction_id','m.supply_takeout_id','u.fullname','m.title')
  ->orderBy('m.expense_date')->get();
echo "  count=".count($rows)."\n";
foreach ($rows as $r) echo sprintf("  req#%-5d %-18s Rs %-10s %-10s %-9s led=%-6s settle=%-6s takeout=%-4s %s | %s\n",
   $r->id,$r->request_number,number_format($r->amount,0),$r->expense_date,$r->status,
   $r->ledger_transaction_id ?? '-',$r->settlement_transaction_id ?? '-',$r->supply_takeout_id ?? '-',$r->fullname,mb_substr($r->title??'',0,40));

echo "\n── their ledger rows: is balance_updated set? (decides if a delete really gives the money back) ──\n";
$ids = collect($rows)->pluck('ledger_transaction_id')->filter()->all();
if ($ids) foreach (DB::table('t_fin_ledger')->whereIn('id',$ids)->get() as $g)
  echo sprintf("  led#%-6d %s amt=%-10s from=%-4s to=%-4s status=%-9s balance_updated=%s\n",
    $g->id,$g->transaction_date,$g->amount,$g->from_account_id,$g->to_account_id,$g->approval_status,$g->balance_updated);
else echo "  (none)\n";

echo "\n── every ledger row hitting a packaging expense account ──\n";
$acc = DB::table('t_fin_accounts')->where('account_code','like','%PACKAG%')->pluck('id')->all();
if ($acc) {
  $n = DB::table('t_fin_ledger')->whereIn('to_account_id',$acc)->orWhereIn('from_account_id',$acc)->count();
  echo "  total rows = $n\n";
  foreach (DB::table('t_fin_ledger')->where(function($q)use($acc){$q->whereIn('to_account_id',$acc)->orWhereIn('from_account_id',$acc);})
      ->orderByDesc('transaction_date')->limit(15)->get() as $g)
    echo sprintf("  led#%-6d %s %-16s amt=%-10s from=%-4s to=%-4s %-9s bu=%s | %s\n",
      $g->id,$g->transaction_date,$g->transaction_type,$g->amount,$g->from_account_id,$g->to_account_id,
      $g->approval_status,$g->balance_updated,mb_substr($g->description??'',0,50));
}
