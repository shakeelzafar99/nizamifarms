<?php

namespace App\Http\Controllers\Request;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\Request\RequestModel;
use App\Models\Request\RequestCategoryModel;
use App\Models\SysAdmin\RoleApprovalLevelModel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class RequestController extends Controller
{
    /**
     * Display requests index page
     */
    public function index(Request $request)
    {
        $categories = RequestCategoryModel::getActiveCategories();
        $user = auth()->user();
        
        // Check if user has approval rights
        $canApproveLevel1 = RoleApprovalLevelModel::userHasApprovalLevel($user->id, 1);
        $canApproveLevel2 = RoleApprovalLevelModel::userHasApprovalLevel($user->id, 2);
        
        return view('pages.requests.index', compact('categories', 'canApproveLevel1', 'canApproveLevel2'));
    }

    /**
     * Get requests data (AJAX)
     */
    public function data(Request $request)
    {
        $user = auth()->user();
        $view = $request->input('view', 'my'); // my, pending_approval, all
        
        $query = RequestModel::with(['category', 'requester', 'approvals.approver', 'createdBy'])
            ->orderByDesc('created_at');

        // Check if user has permission to view all requests
        $canViewAllRequests = $user->hasPermission('view_all_requests');
        
        // Filter based on view
        if ($view === 'my') {
            $query->where('requester_user_id', $user->id);
        } elseif ($view === 'pending_approval') {
            // Get requests where user can approve
            $canApproveLevel1 = RoleApprovalLevelModel::userHasApprovalLevel($user->id, 1);
            $canApproveLevel2 = RoleApprovalLevelModel::userHasApprovalLevel($user->id, 2);
            
            $query->where('status', RequestModel::STATUS_PENDING);
            
            $query->where(function($q) use ($canApproveLevel1, $canApproveLevel2) {
                if ($canApproveLevel1) {
                    $q->orWhere(function($subQ) {
                        $subQ->where('requires_level_1', 1)
                             ->where('level_1_status', RequestModel::APPROVAL_STATUS_PENDING);
                    });
                }
                if ($canApproveLevel2) {
                    $q->orWhere(function($subQ) {
                        $subQ->where('requires_level_2', 1)
                             ->where('level_2_status', RequestModel::APPROVAL_STATUS_PENDING)
                             ->where(function($approvedL1) {
                                 $approvedL1->where('requires_level_1', 0)
                                            ->orWhere('level_1_status', RequestModel::APPROVAL_STATUS_APPROVED);
                             });
                    });
                }
            });
        } elseif ($view === 'all') {
            // Only users with 'view_all_requests' permission can view all
            if (!$canViewAllRequests) {
                // Fall back to 'my requests' if they don't have permission
                $query->where('requester_user_id', $user->id);
            }
            // Otherwise, show all (no filter applied)
        }

        // Additional filters
        if ($request->filled('status')) {
            $query->where('status', $request->input('status'));
        }

        if ($request->filled('category_id')) {
            $query->where('category_id', $request->input('category_id'));
        }

        if ($request->filled('date_from')) {
            $query->whereDate('created_at', '>=', $request->input('date_from'));
        }

        if ($request->filled('date_to')) {
            $query->whereDate('created_at', '<=', $request->input('date_to'));
        }

        $requests = $query->limit(500)->get();

        return response()->json([
            'success' => true,
            'data' => $requests
        ]);
    }

    /**
     * Lightweight summary for the in-app "new leave added" banner.
     * Returns the count of pending leave requests + the newest request id (the
     * banner uses latest_id as a high-water mark so it only alerts on genuinely
     * NEW leaves, never the existing backlog) + a short title for the newest one.
     * Audience is gated on the mobile side by the 'receive_leave_alerts' permission.
     */
    public function leaveAlertSummary(Request $request)
    {
        try {
            $agg = DB::table('t_req_master as r')
                ->join('t_req_category as c', 'c.id', '=', 'r.category_id')
                ->where('c.category_code', 'leave')
                ->where('r.status', RequestModel::STATUS_PENDING)
                ->selectRaw('COUNT(*) as cnt, MAX(r.id) as latest_id')
                ->first();

            $count = (int) ($agg->cnt ?? 0);
            $latestId = (int) ($agg->latest_id ?? 0);
            $latestTitle = null;

            if ($latestId > 0) {
                $latest = DB::table('t_req_master as r')
                    ->leftJoin('t_sys_user as u', 'u.id', '=', 'r.requester_user_id')
                    ->where('r.id', $latestId)
                    ->select('u.fullname', 'r.leave_start_date', 'r.leave_end_date')
                    ->first();
                if ($latest) {
                    $name = $latest->fullname ?: 'An employee';
                    $range = '';
                    if ($latest->leave_start_date) {
                        $s = date('M j', strtotime($latest->leave_start_date));
                        $e = $latest->leave_end_date ? date('M j', strtotime($latest->leave_end_date)) : $s;
                        $range = ($s === $e) ? $s : "{$s} – {$e}";
                    }
                    $latestTitle = trim($name . ($range ? " · {$range}" : ''));
                }
            }

            return response()->json([
                'success' => true,
                'count' => $count,
                'latest_id' => $latestId,
                'latest_title' => $latestTitle,
            ]);
        } catch (\Exception $e) {
            \Log::error('leaveAlertSummary failed', ['error' => $e->getMessage()]);
            return response()->json(['success' => true, 'count' => 0, 'latest_id' => 0, 'latest_title' => null]);
        }
    }

    /**
     * Show create request form
     */
    public function create()
    {
        $categories = RequestCategoryModel::getActiveCategories();
        return view('pages.requests.create', compact('categories'));
    }

    /**
     * Store new request
     */
    public function store(Request $request)
    {
        $validated = $request->validate([
            'category_id' => 'required|exists:t_req_category,id',
            'requester_user_id' => 'nullable|exists:t_sys_user,id', // For admin/manager creating for others
            'title' => 'required|string|max:255',
            'description' => 'nullable|string',
            'amount' => 'nullable|numeric|min:0',
            'expense_category' => 'nullable|string|max:255', // For expense requests
            'expense_date' => 'nullable|date', // ⭐ For backdated expense entries
            // 🔧 Bike service capture (Jul-2026). Until now only the MOBILE form sent
            // these, so a web-filed Maintenance row had service_type = NULL and could
            // never reset the bike's service clock on approval. Whitelisted to the two
            // values the mobile picker offers; blank = not bike-related (unchanged).
            'service_type' => 'nullable|in:oil_change,repair',
            'meter_at_fill' => 'nullable|integer|min:0|max:9999999',
            'payment_source_account_id' => 'nullable|exists:t_fin_accounts,id', // Payment source selection
            'receiving_account_id' => 'nullable|integer|exists:t_fin_online_receiving_accounts,id', // Which bank an ONLINE expense is paid from
            'business_unit_id' => 'nullable|exists:t_fin_business_units,id', // ⭐ Business unit for expense
            'leave_start_date' => 'nullable|date',
            'leave_end_date' => 'nullable|date|after_or_equal:leave_start_date',
            'leave_type' => 'nullable|string',
            'priority' => 'nullable|in:low,normal,high,urgent',
            'attachment_image' => 'nullable|image|max:5120', // 5MB max for receipt/bill images
        ]);

        // Salaries are now handled exclusively by the Payroll screen. Block the legacy
        // "Staff Salaries" expense category so it can't create a duplicate salary expense
        // — even from a stale open form or a cached mobile app that still lists it.
        if (in_array(strtolower(trim((string) ($validated['expense_category'] ?? ''))), ['staff salaries', 'staff salary'], true)) {
            return response()->json([
                'success' => false,
                'message' => 'Staff salaries must be paid from the Payroll screen, not entered as an expense.'
            ], 422);
        }

        // ⭐ Validate expense_date is within allowed backdate range
        if ($request->filled('expense_date')) {
            $loggedInUser = auth()->user();
            $expenseDate = \Carbon\Carbon::parse($validated['expense_date']);
            $today = \Carbon\Carbon::today();
            
            // Get user's max backdate days from their roles
            $maxBackdateDays = DB::table('t_sys_user_role as ur')
                ->join('t_sys_role as r', 'r.id', '=', 'ur.role_id')
                ->where('ur.user_id', $loggedInUser->id)
                ->max('r.expense_backdate_days') ?? 0;
            
            // Check if date is in the future
            if ($expenseDate->gt($today)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Expense date cannot be in the future'
                ], 422);
            }
            
            // Check if date is too far in the past
            $daysDiff = $today->diffInDays($expenseDate);
            if ($daysDiff > $maxBackdateDays) {
                return response()->json([
                    'success' => false,
                    'message' => "You can only backdate expenses up to {$maxBackdateDays} days. Selected date is {$daysDiff} days ago."
                ], 422);
            }
        }

        // Server-side payment source validation: non-EXP_FUND requires permission
        if ($request->filled('payment_source_account_id')) {
            $loggedInUserForSource = auth()->user();
            $sourceAccount = \App\Models\FIN\AccountModel::find($validated['payment_source_account_id']);
            if ($sourceAccount && $sourceAccount->account_code !== 'EXP_FUND') {
                $buId = $validated['business_unit_id'] ?? null;
                $canUseAllSources = $loggedInUserForSource->hasMobilePermission('expense_all_payment_sources')
                    || ($buId && $loggedInUserForSource->hasMobilePermission('approve_khaas_transfer'));
                if (!$canUseAllSources) {
                    return response()->json([
                        'success' => false,
                        'message' => 'You do not have permission to create expenses from this payment source. Only Expense Fund is allowed.'
                    ], 403);
                }
            }
            if ($sourceAccount && $sourceAccount->is_private) {
                $isTaimurRole = $loggedInUserForSource->roles()->whereRaw('LOWER(urole_name) = ?', ['taimur'])->exists();
                if (!$isTaimurRole) {
                    return response()->json([
                        'success' => false,
                        'message' => 'This payment source is not available.'
                    ], 403);
                }
            }
            // When an EXPENSE or SALARY ADVANCE is paid from an ONLINE bank
            // account, the specific receiving bank is mandatory so per-bank
            // balances stay correct. Gated to money-moving categories: the web
            // form submits the (hidden) pay-from select for LEAVE requests too,
            // and leaves move no money — they must never be blocked.
            if ($sourceAccount && $sourceAccount->account_category === \App\Models\FIN\AccountModel::CATEGORY_BANK
                && !$request->filled('receiving_account_id')) {
                $catForBankCheck = RequestCategoryModel::find($validated['category_id']);
                $isMoneyCategory = $catForBankCheck && (
                    in_array($catForBankCheck->category_code, ['expense', 'khaas_expense', 'salary_advance'], true)
                    || in_array($catForBankCheck->form_type ?? null, ['expense', 'salary'], true)
                );
                if ($isMoneyCategory) {
                    return response()->json([
                        'success' => false,
                        'message' => 'Select which bank this online payment is made from.'
                    ], 422);
                }
            }
        }

        // Prevent duplicate leave requests for overlapping dates
        if ($request->filled('leave_start_date') && $request->filled('leave_end_date')) {
            $leaveStart = $validated['leave_start_date'];
            $leaveEnd = $validated['leave_end_date'];
            $leaveUserId = $validated['requester_user_id'] ?? auth()->id();

            $overlapping = DB::table('t_req_master as r')
                ->join('t_req_category as c', 'c.id', '=', 'r.category_id')
                ->where('c.category_code', 'leave')
                ->where('r.requester_user_id', $leaveUserId)
                ->whereNotIn('r.status', ['cancelled', 'rejected'])
                ->where('r.leave_start_date', '<=', $leaveEnd)
                ->where('r.leave_end_date', '>=', $leaveStart)
                ->select('r.leave_start_date', 'r.leave_end_date', 'r.request_number')
                ->first();

            if ($overlapping) {
                $existingStart = \Carbon\Carbon::parse($overlapping->leave_start_date)->format('M d');
                $existingEnd = \Carbon\Carbon::parse($overlapping->leave_end_date)->format('M d');
                return response()->json([
                    'success' => false,
                    'message' => "Leave already raised for {$existingStart} - {$existingEnd} ({$overlapping->request_number}). Cannot create overlapping leave request."
                ], 422);
            }
        }

        // ── Leave policy (single pool + same-day cap). Applies only to a rider applying
        //    for THEMSELVES; a manager creating a leave for someone else is unbound
        //    (that path is the Attendance apply-leave). Sets $policyLeaveType so the row
        //    records how it was applied (planned = advance, emergency = same-day).
        $policyLeaveType = null;
        if ($request->filled('leave_start_date') && $request->filled('leave_end_date')) {
            $catForPolicy = RequestCategoryModel::find($validated['category_id']);
            $actorId = (int) auth()->id();
            $forUserId = (int) ($validated['requester_user_id'] ?? $actorId);
            if ($catForPolicy && $catForPolicy->category_code === 'leave') {
                if ($forUserId === $actorId) {
                    $policyRes = (new \App\Services\HR\LeavePolicyService())->validateApplication(
                        $forUserId, $validated['leave_start_date'], $validated['leave_end_date'],
                        date('Y-m-d'), date('H:i')
                    );
                    if (!$policyRes['ok']) {
                        return response()->json(['success' => false, 'message' => $policyRes['message']], 422);
                    }
                    // planned = applied in advance; emergency = same-day (feeds the cap).
                    $policyLeaveType = $policyRes['is_sameday'] ? 'emergency' : 'planned';
                } else {
                    // Manager creating a leave FOR someone else: honor their explicit
                    // choice but whitelist it — anything other than 'emergency'
                    // (including legacy/junk values) is stored as 'planned'.
                    $posted = strtolower(trim((string) ($validated['leave_type'] ?? '')));
                    $policyLeaveType = $posted === 'emergency' ? 'emergency' : 'planned';
                }
            }
        }

        try {
            DB::beginTransaction();

            $category = RequestCategoryModel::with('approvalConfig')->findOrFail($validated['category_id']);
            $loggedInUser = auth()->user();
            
            // Determine who the request is for
            $requesterId = $loggedInUser->id; // Default to logged-in user
            $createdByNote = '';
            
            // If requester_user_id is provided, verify permission
            if ($request->filled('requester_user_id') && $validated['requester_user_id']) {
                // Check if logged-in user has permission to create for others
                // Get ALL user's roles (not just the first one!)
                $userRoles = DB::table('t_sys_user_role as ur')
                    ->join('t_sys_role as r', 'r.id', '=', 'ur.role_id')
                    ->where('ur.user_id', $loggedInUser->id)
                    ->select('r.type', 'r.urole_name')
                    ->get();
                
                $hasPermission = false;
                
                // Check by role type OR role name
                $allowedTypes = ['admin', 'manager', 'supervisor'];
                $allowedNamePatterns = ['admin', 'manager', 'supervisor', 'super'];
                
                // Check each role
                foreach ($userRoles as $roleInfo) {
                    // Check type field
                    $typeMatch = in_array(strtolower($roleInfo->type ?? ''), $allowedTypes);
                    
                    // Check name field (case-insensitive, partial match)
                    $nameMatch = false;
                    $roleName = strtolower($roleInfo->urole_name ?? '');
                    foreach ($allowedNamePatterns as $pattern) {
                        if (strpos($roleName, $pattern) !== false) {
                            $nameMatch = true;
                            break;
                        }
                    }
                    
                    // If ANY role matches, user has permission
                    if ($typeMatch || $nameMatch) {
                        $hasPermission = true;
                        break;
                    }
                }
                
                if (!$hasPermission) {
                    return response()->json([
                        'success' => false,
                        'message' => 'You do not have permission to create requests for other users'
                    ], 403);
                }
                
                // Valid - create for the specified user
                $requesterId = $validated['requester_user_id'];
                $requesterUser = \App\Models\SysAdmin\UserModel::find($requesterId);
                $createdByNote = "\n\n[Created by {$loggedInUser->fullname} on behalf of employee]";
            }

            // Calculate leave days if it's a leave request
            $leaveDays = null;
            if ($request->filled('leave_start_date') && $request->filled('leave_end_date')) {
                $start = \Carbon\Carbon::parse($validated['leave_start_date']);
                $end = \Carbon\Carbon::parse($validated['leave_end_date']);
                $leaveDays = $end->diffInDays($start) + 1;
            }

            // Check if the logged-in user has L1 or L2 approval rights for auto-approval
            $userHasL1 = RoleApprovalLevelModel::userHasApprovalLevel($loggedInUser->id, 1);
            $userHasL2 = RoleApprovalLevelModel::userHasApprovalLevel($loggedInUser->id, 2);
            
            // Determine initial approval statuses based on user's approval rights
            $requiresL1 = $category->requiresLevel1();
            $requiresL2 = $category->requiresLevel2();
            
            // Auto-approve L1 if user has L1 rights
            $level1Status = null;
            $level1ApprovedBy = null;
            $level1ApprovedAt = null;
            if ($requiresL1) {
                if ($userHasL1) {
                    $level1Status = RequestModel::APPROVAL_STATUS_APPROVED;
                    $level1ApprovedBy = $loggedInUser->id;
                    $level1ApprovedAt = now();
                } else {
                    $level1Status = RequestModel::APPROVAL_STATUS_PENDING;
                }
            }
            
            // Auto-approve L2 if user has L2 rights (and L1 is approved or not required)
            $level2Status = null;
            $level2ApprovedBy = null;
            $level2ApprovedAt = null;
            if ($requiresL2) {
                if ($userHasL2 && (!$requiresL1 || $level1Status === RequestModel::APPROVAL_STATUS_APPROVED)) {
                    $level2Status = RequestModel::APPROVAL_STATUS_APPROVED;
                    $level2ApprovedBy = $loggedInUser->id;
                    $level2ApprovedAt = now();
                } else {
                    $level2Status = RequestModel::APPROVAL_STATUS_PENDING;
                }
            }
            
            // Determine overall status
            $overallStatus = RequestModel::STATUS_PENDING;
            if ((!$requiresL1 || $level1Status === RequestModel::APPROVAL_STATUS_APPROVED) &&
                (!$requiresL2 || $level2Status === RequestModel::APPROVAL_STATUS_APPROVED)) {
                $overallStatus = RequestModel::STATUS_APPROVED;
            }
            
            // Get assignees based on routing rules (if not auto-approved)
            $assignedToL1 = null;
            $assignedToL2 = null;
            if ($level1Status === RequestModel::APPROVAL_STATUS_PENDING) {
                $assignedToL1 = $this->getAssigneeForApproval(
                    'request_category',
                    $category->category_code,
                    1,
                    [
                        'payment_source_account_id' => $validated['payment_source_account_id'] ?? null,
                        'amount' => $validated['amount'] ?? null
                    ]
                );
            }
            if ($requiresL2 && $level2Status === RequestModel::APPROVAL_STATUS_PENDING) {
                $assignedToL2 = $this->getAssigneeForApproval(
                    'request_category',
                    $category->category_code,
                    2,
                    [
                        'payment_source_account_id' => $validated['payment_source_account_id'] ?? null,
                        'amount' => $validated['amount'] ?? null
                    ]
                );
            }
            
            // Store attachment image if provided (e.g. fuel bill photo)
            $attachments = null;
            if ($request->hasFile('attachment_image')) {
                $file = $request->file('attachment_image');
                $date = now();
                $filename = "req_{$requesterId}_{$date->format('Ymd_His')}.jpg";
                $path = "requests/attachments/{$date->format('Y')}/{$date->format('m')}/{$filename}";
                \Storage::disk('public')->put($path, file_get_contents($file));
                $attachments = [$path];
            }

            // Only keep the receiving bank when the source is an online bank
            // account — never tag a cash-funded expense with a bank.
            $expenseSourceAcct = isset($validated['payment_source_account_id'])
                ? \App\Models\FIN\AccountModel::find($validated['payment_source_account_id'])
                : null;
            $expenseBankId = ($expenseSourceAcct && $expenseSourceAcct->account_category === \App\Models\FIN\AccountModel::CATEGORY_BANK)
                ? ($validated['receiving_account_id'] ?? null)
                : null;

            // Create request
            $requestModel = RequestModel::create([
                'request_number' => RequestModel::generateRequestNumber(),
                'category_id' => $validated['category_id'],
                'requester_user_id' => $requesterId,
                'title' => $validated['title'],
                'description' => ($validated['description'] ?? '') . $createdByNote,
                'amount' => $validated['amount'] ?? null,
                'expense_category' => $validated['expense_category'] ?? null,
                'expense_date' => $validated['expense_date'] ?? now()->toDateString(),
                // 🔧 Only meaningful on a Maintenance row — never stamp a service type
                // onto an unrelated expense if a stale form submits the hidden fields.
                'service_type' => (($validated['expense_category'] ?? null) === 'Maintenance')
                    ? ($validated['service_type'] ?? null) : null,
                'meter_at_fill' => (($validated['expense_category'] ?? null) === 'Maintenance')
                    ? ($validated['meter_at_fill'] ?? null) : null,
                'payment_source_account_id' => $validated['payment_source_account_id'] ?? null,
                'receiving_account_id' => $expenseBankId,
                'business_unit_id' => $validated['business_unit_id'] ?? 1,
                'leave_start_date' => $validated['leave_start_date'] ?? null,
                'leave_end_date' => $validated['leave_end_date'] ?? null,
                // Self-service leaves record how they were applied (casual/emergency);
                // manager/other paths keep whatever was passed.
                'leave_type' => $policyLeaveType ?? ($validated['leave_type'] ?? null),
                'leave_days' => $leaveDays,
                'attachments' => $attachments,
                'status' => $overallStatus,
                'priority' => $validated['priority'] ?? 'normal',
                'requires_level_1' => $requiresL1,
                'requires_level_2' => $requiresL2,
                'level_1_status' => $level1Status,
                'level_1_assigned_to' => $assignedToL1,
                'level_1_approved_by' => $level1ApprovedBy,
                'level_1_approved_at' => $level1ApprovedAt,
                'level_2_status' => $level2Status,
                'level_2_assigned_to' => $assignedToL2,
                'level_2_approved_by' => $level2ApprovedBy,
                'level_2_approved_at' => $level2ApprovedAt,
                'submitted_at' => now(),
                'created_by' => $loggedInUser->id,
            ]);

            // If auto-approved and it's an expense-type request, post to ledger.
            // Uses RequestModel::isExpenseTypeCategory() so any category with form_type='expense'
            // (qurbani, future custom expense types) is handled the same as the original expense/khaas_expense.
            if ($overallStatus === RequestModel::STATUS_APPROVED) {
                $categoryCode = $category->category_code;
                $requestModel->setRelation('category', $category); // ensure relation is loaded for helper

                // 🔧 A request approved AT CREATION (category with no approval levels)
                // never passes through processApproval(), so the bike service-clock
                // hook must fire here too. Non-fatal; no-op unless it is an approved
                // oil-change/general Maintenance row with a meter reading.
                \App\Services\Riders\BikeServiceClock::onRequestApproved($requestModel);

                if ($requestModel->isExpenseTypeCategory() && $requestModel->amount > 0) {
                    try {
                        $ledgerService = app(\App\Services\FIN\LedgerPostingService::class);
                        $ledgerService->postExpenseFromRequest($requestModel);
                        Log::info("Auto-approved expense posted to ledger", [
                            'request_id' => $requestModel->id,
                            'request_number' => $requestModel->request_number,
                            'category_code' => $categoryCode
                        ]);
                    } catch (\Exception $e) {
                        Log::error("Failed to post auto-approved expense to ledger: " . $e->getMessage(), [
                            'request_id' => $requestModel->id
                        ]);
                        // Don't fail the request creation, just log the error
                    }
                } elseif ($categoryCode === 'salary_advance' && $requestModel->amount > 0) {
                    try {
                        $requestModel->postSalaryAdvanceToLedger();
                        Log::info("Auto-approved salary advance posted to ledger", [
                            'request_id' => $requestModel->id,
                            'request_number' => $requestModel->request_number
                        ]);
                    } catch (\Exception $e) {
                        Log::error("Failed to post auto-approved salary advance to ledger: " . $e->getMessage(), [
                            'request_id' => $requestModel->id
                        ]);
                    }
                }
            }

            DB::commit();

            // ⭐ Alert management (permission: receive_leave_alerts) when a NEW leave
            // request is added and is pending review. Skips auto-approved leaves and
            // never notifies the creator about their own entry. Best-effort.
            if (($category->category_code ?? null) === 'leave'
                && $overallStatus === RequestModel::STATUS_PENDING) {
                try {
                    $applicantName = 'An employee';
                    if ($requesterId === $loggedInUser->id) {
                        $applicantName = $loggedInUser->fullname ?: 'An employee';
                    } elseif (isset($requesterUser) && $requesterUser) {
                        $applicantName = $requesterUser->fullname ?: 'An employee';
                    }
                    $range = null;
                    if ($requestModel->leave_start_date) {
                        $s = date('M j', strtotime($requestModel->leave_start_date));
                        $e2 = $requestModel->leave_end_date ? date('M j', strtotime($requestModel->leave_end_date)) : $s;
                        $range = ($s === $e2) ? $s : "{$s} – {$e2}";
                    }
                    app(\App\Services\FirebaseService::class)->notifyLeaveAdded(
                        (int) $requestModel->id,
                        $applicantName,
                        $range,
                        (int) $loggedInUser->id
                    );
                } catch (\Throwable $e) {
                    \Log::warning('Leave alert failed (web/store)', ['error' => $e->getMessage()]);
                }
            }

            $autoApprovedNote = ($userHasL1 || $userHasL2) && $overallStatus === RequestModel::STATUS_APPROVED
                ? ' (Auto-approved)' 
                : '';
            
            $message = $requesterId !== $loggedInUser->id 
                ? 'Request created successfully for ' . ($requesterUser->fullname ?? 'employee') . $autoApprovedNote
                : 'Request submitted successfully' . $autoApprovedNote;

            return response()->json([
                'success' => true,
                'message' => $message,
                'request_id' => $requestModel->id,
                'request_number' => $requestModel->request_number,
                'auto_approved' => $overallStatus === RequestModel::STATUS_APPROVED
            ]);

        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Request creation error: ' . $e->getMessage());
            
            return response()->json([
                'success' => false,
                'message' => 'Failed to create request: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Show request details
     */
    public function show($id, \Illuminate\Http\Request $httpRequest)
    {
        // Store the previous URL (with filters) in session for smart back navigation
        if (url()->previous() !== url()->current()) {
            $previousUrl = url()->previous();
            // Only store if it's from the approvals index page
            if (strpos($previousUrl, route('approvals.index')) !== false || strpos($previousUrl, '/approvals') !== false) {
                session(['approvals_return_url' => $previousUrl]);
            }
        }
        
        $request = RequestModel::with([
            'category',
            'requester',
            'approvals.approver',
            'createdBy',
            'updatedBy',
            'paymentSourceAccount'
        ])->findOrFail($id);

        $user = auth()->user();
        
        // Check if user can approve this request
        $canApproveLevel1 = RoleApprovalLevelModel::userHasApprovalLevel($user->id, 1) 
            && $request->canBeApprovedByLevel(1);
        $canApproveLevel2 = RoleApprovalLevelModel::userHasApprovalLevel($user->id, 2) 
            && $request->canBeApprovedByLevel(2);

        if ($httpRequest->wantsJson() || $httpRequest->query('format') === 'json') {
            $l1 = $request->getLevel1Approver();
            $l2 = $request->getLevel2Approver();
            return response()->json([
                'success' => true,
                'request' => [
                    'id' => $request->id,
                    'request_number' => $request->request_number,
                    'status' => $request->status,
                    'priority' => $request->priority,
                    'category_name' => $request->category->category_name ?? '-',
                    'requester_name' => $request->requester->fullname ?? '-',
                    'created_by_name' => $request->createdBy->fullname ?? null,
                    'created_by_differs' => $request->created_by !== $request->requester_user_id,
                    'submitted_at' => $request->submitted_at?->format('Y-m-d H:i'),
                    'completed_at' => $request->completed_at?->format('Y-m-d H:i'),
                    'amount' => $request->amount,
                    'expense_category' => $request->expense_category,
                    'expense_date' => $request->expense_date?->format('Y-m-d'),
                    'payment_source' => $request->paymentSourceAccount?->account_name,
                    'title' => $request->title,
                    'description' => $request->description,
                    'rejection_reason' => $request->rejection_reason,
                    'leave_start_date' => $request->leave_start_date?->format('Y-m-d'),
                    'leave_end_date' => $request->leave_end_date?->format('Y-m-d'),
                    'leave_days' => $request->leave_days,
                    'leave_type' => $request->leave_type,
                    'attachments' => $request->attachments,
                    'requires_level_1' => $request->requires_level_1,
                    'requires_level_2' => $request->requires_level_2,
                    'level_1_status' => $request->level_1_status,
                    'level_2_status' => $request->level_2_status,
                    'l1_approver_name' => $l1?->approver?->fullname,
                    'l1_action_date' => $l1?->action_date?->format('Y-m-d H:i'),
                    'l1_comments' => $l1?->comments,
                    'l1_status' => $l1?->status,
                    'l2_approver_name' => $l2?->approver?->fullname,
                    'l2_action_date' => $l2?->action_date?->format('Y-m-d H:i'),
                    'l2_comments' => $l2?->comments,
                    'l2_status' => $l2?->status,
                    'can_approve_level_1' => $canApproveLevel1,
                    'can_approve_level_2' => $canApproveLevel2,
                ],
            ]);
        }

        return view('pages.requests.show', compact('request', 'canApproveLevel1', 'canApproveLevel2'));
    }

    /**
     * Update request (only for requester, and only if pending)
     */
    public function update(Request $request, $id)
    {
        $requestModel = RequestModel::findOrFail($id);
        $user = auth()->user();

        // Only requester can update, and only if pending
        if ($requestModel->requester_user_id !== $user->id) {
            return response()->json([
                'success' => false,
                'message' => 'You can only update your own requests'
            ], 403);
        }

        if (!$requestModel->isPending()) {
            return response()->json([
                'success' => false,
                'message' => 'Cannot update request that is not pending'
            ], 400);
        }

        $validated = $request->validate([
            'title' => 'required|string|max:255',
            'description' => 'nullable|string',
            'amount' => 'nullable|numeric|min:0',
            'leave_start_date' => 'nullable|date',
            'leave_end_date' => 'nullable|date|after_or_equal:leave_start_date',
            'leave_type' => 'nullable|string',
            'priority' => 'nullable|in:low,normal,high,urgent'
        ]);

        try {
            // Calculate leave days if it's a leave request.
            // NB: Carbon 3 diffInDays is SIGNED — end->diff(start) goes BACKWARDS (negative for a
            // normal range), which used to store e.g. -1 for a 3-day leave. Use abs().
            $leaveDays = null;
            if ($request->filled('leave_start_date') && $request->filled('leave_end_date')) {
                $start = \Carbon\Carbon::parse($validated['leave_start_date']);
                $end = \Carbon\Carbon::parse($validated['leave_end_date']);
                $leaveDays = abs($start->diffInDays($end)) + 1;
            }

            // Whitelist leave_type on SELF-update, same rule as create: only emergency/planned.
            // 'half_day' is a MANAGER-ONLY grant (attendance page) — a rider must not be able to
            // edit his pending request into one (0.5 charge + late suppression on approval).
            $leaveType = null;
            if ($request->filled('leave_type')) {
                $posted = strtolower(trim((string) $validated['leave_type']));
                $leaveType = $posted === 'emergency' ? 'emergency' : 'planned';
            }

            $requestModel->update([
                'title' => $validated['title'],
                'description' => $validated['description'] ?? null,
                'amount' => $validated['amount'] ?? null,
                'leave_start_date' => $validated['leave_start_date'] ?? null,
                'leave_end_date' => $validated['leave_end_date'] ?? null,
                'leave_type' => $leaveType,
                'leave_days' => $leaveDays,
                'priority' => $validated['priority'] ?? $requestModel->priority,
                'updated_by' => $user->id
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Request updated successfully'
            ]);

        } catch (\Exception $e) {
            Log::error('Request update error: ' . $e->getMessage());
            
            return response()->json([
                'success' => false,
                'message' => 'Failed to update request: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Cancel request (only for requester, and only if pending)
     */
    public function cancel($id)
    {
        $request = RequestModel::findOrFail($id);
        $user = auth()->user();

        if ($request->requester_user_id !== $user->id) {
            return response()->json([
                'success' => false,
                'message' => 'You can only cancel your own requests'
            ], 403);
        }

        if (!$request->isPending()) {
            return response()->json([
                'success' => false,
                'message' => 'Cannot cancel request that is not pending'
            ], 400);
        }

        try {
            $request->update([
                'status' => RequestModel::STATUS_CANCELLED,
                'updated_by' => $user->id
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Request cancelled successfully'
            ]);

        } catch (\Exception $e) {
            Log::error('Request cancellation error: ' . $e->getMessage());
            
            return response()->json([
                'success' => false,
                'message' => 'Failed to cancel request: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get assignee based on routing rules
     */
    private function getAssigneeForApproval(
        string $areaType,
        string $areaIdentifier,
        int $level,
        array $context = []
    ): ?int
    {
        try {
            // Query rules matching the criteria
            $query = DB::table('t_req_approval_rules')
                ->where('area_type', $areaType)
                ->where('area_identifier', $areaIdentifier)
                ->where('approval_level', $level)
                ->where('is_active', 1);
            
            // Apply contextual filters
            if (isset($context['payment_source_account_id']) && $context['payment_source_account_id']) {
                $query->where(function($q) use ($context) {
                    $q->where('payment_source_account_id', $context['payment_source_account_id'])
                      ->orWhereNull('payment_source_account_id');
                });
            }
            
            if (isset($context['amount']) && $context['amount']) {
                $amount = $context['amount'];
                $query->where(function($q) use ($amount) {
                    $q->where(function($subQ) use ($amount) {
                        $subQ->whereNull('min_amount')
                             ->orWhere('min_amount', '<=', $amount);
                    })
                    ->where(function($subQ) use ($amount) {
                        $subQ->whereNull('max_amount')
                             ->orWhere('max_amount', '>=', $amount);
                    });
                });
            }
            
            // Get highest priority rule
            $rule = $query->orderBy('priority', 'asc')->first();
            
            if (!$rule) {
                return null; // No rule found, use default behavior
            }
            
            // Get primary assignee for this rule
            $assignee = DB::table('t_req_approval_rule_assignees')
                ->where('rule_id', $rule->id)
                ->where('is_primary', 1)
                ->orderBy('sequence_order', 'asc')
                ->first();
            
            return $assignee ? $assignee->user_id : null;
            
        } catch (\Exception $e) {
            Log::error("Error getting assignee for approval", [
                'error' => $e->getMessage(),
                'area_type' => $areaType,
                'area_identifier' => $areaIdentifier
            ]);
            return null;
        }
    }

    /**
     * Find request by request number (for clickable links in ledger)
     */
    public function findByNumber($requestNumber)
    {
        try {
            $request = RequestModel::where('request_number', $requestNumber)->first();
            
            if (!$request) {
                return response()->json([
                    'success' => false,
                    'message' => 'Request not found'
                ], 404);
            }
            
            return response()->json([
                'success' => true,
                'request_id' => $request->id,
                'request_number' => $request->request_number
            ]);
            
        } catch (\Exception $e) {
            Log::error("Error finding request by number: " . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Error finding request'
            ], 500);
        }
    }
}

