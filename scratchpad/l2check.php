<?php
use Illuminate\Support\Facades\DB;
echo "── approval levels by role ──\n";
foreach (DB::table('t_sys_role_approval_level as ral')->join('t_sys_role as r','r.id','=','ral.role_id')
        ->select('ral.approval_level','r.id as rid','r.urole_name')->orderBy('ral.approval_level')->orderBy('r.urole_name')->get() as $x) {
    echo sprintf("  L%d  role#%-3d %s\n", $x->approval_level, $x->rid, $x->urole_name);
}
echo "\n── who holds L2 (users) ──\n";
foreach (DB::table('t_sys_user_role as ur')->join('t_sys_role as r','r.id','=','ur.role_id')
        ->join('t_sys_role_approval_level as ral','ral.role_id','=','r.id')
        ->join('t_sys_user as u','u.id','=','ur.user_id')
        ->where('ral.approval_level',2)->select('u.id','u.fullname','r.urole_name')->distinct()->get() as $x) {
    echo sprintf("  #%-4d %-22s via %s\n", $x->id, $x->fullname, $x->urole_name);
}
echo "\n── Taimur (68) roles ──\n";
foreach (DB::table('t_sys_user_role as ur')->join('t_sys_role as r','r.id','=','ur.role_id')
        ->where('ur.user_id',68)->select('r.id','r.urole_name','r.expense_backdate_days')->get() as $x) {
    echo sprintf("  role#%-3d %-22s backdate_days=%s\n", $x->id, $x->urole_name, $x->expense_backdate_days);
}
echo "\n── userHasApprovalLevel ──\n";
foreach ([68=>'Taimur', 1=>'admin?'] as $uid=>$nm) {
    foreach ([1,2] as $lvl) {
        $has = \App\Models\SysAdmin\RoleApprovalLevelModel::userHasApprovalLevel($uid, $lvl);
        echo sprintf("  user %-4d L%d = %s\n", $uid, $lvl, var_export($has,true));
    }
}
