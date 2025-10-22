<?php

namespace App\Http\Controllers\HR;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\HR\EmployeeProfileModel;
use App\Models\SysAdmin\UserModel;
use Illuminate\Support\Facades\Log;

class EmployeeProfileController extends Controller
{
    /**
     * Display list of employee profiles
     */
    public function index(Request $request)
    {
        if ($request->wantsJson() || $request->has('api')) {
            return $this->getData($request);
        }

        return view('pages.hr.employees.index');
    }

    /**
     * Get employee profiles data (API)
     */
    public function getData(Request $request)
    {
        // Get all users (not just those with profiles)
        // TODO: Uncomment the admin filter once you confirm user_type values
        $query = UserModel::with(['hrProfile', 'hrProfile.activeLoans'])
            ->where('is_active', 1);
            // ->where('user_type', '!=', 'admin'); // Temporarily showing ALL users including admins

        // Filter by profile status
        if ($request->filled('status')) {
            if ($request->status == '1') {
                // Active profiles only
                $query->whereHas('hrProfile', function($q) {
                    $q->where('is_active', 1);
                });
            } elseif ($request->status == '0') {
                // Inactive profiles only  
                $query->whereHas('hrProfile', function($q) {
                    $q->where('is_active', 0);
                });
            }
        }

        // Search
        if ($request->filled('search')) {
            $search = $request->search;
            $query->where(function($q) use ($search) {
                $q->where('fullname', 'like', "%{$search}%")
                  ->orWhere('email', 'like', "%{$search}%")
                  ->orWhereHas('hrProfile', function($profQuery) use ($search) {
                      $profQuery->where('employee_code', 'like', "%{$search}%");
                  });
            });
        }

        // Order
        $query->orderBy('fullname', 'asc');

        // Get all (no pagination for simplicity)
        $users = $query->get();

        // Transform data
        $employees = $users->map(function($user) {
            // Calculate total outstanding loans
            $totalLoanOutstanding = 0;
            if ($user->hrProfile && $user->hrProfile->activeLoans) {
                $totalLoanOutstanding = $user->hrProfile->activeLoans->sum('outstanding_balance');
            }

            // Calculate unadjusted salary advances
            // Get approved salary advances that haven't been settled yet (not in a salary slip)
            $unadjustedAdvances = 0;
            try {
                $unadjustedAdvances = \App\Models\Request\RequestModel::where('requester_user_id', $user->id)
                    ->where('status', 'approved')
                    ->whereHas('category', function($q) {
                        $q->where('category_code', 'salary_advance');
                    })
                    ->where(function($q) {
                        // Only include advances not yet deducted (settlement_status is null or pending)
                        $q->whereNull('settlement_status')
                          ->orWhere('settlement_status', '!=', 'settled');
                    })
                    ->sum('amount');
            } catch (\Exception $e) {
                Log::error('Error calculating salary advances', [
                    'user_id' => $user->id,
                    'error' => $e->getMessage()
                ]);
            }

            // Get salary slip count and last slip month
            $salarySlipCount = 0;
            $lastSlipMonth = null;
            try {
                $slips = \App\Models\HR\SalarySlipModel::where('user_id', $user->id)
                    ->whereIn('slip_status', ['approved', 'paid'])
                    ->orderBy('salary_month', 'desc')
                    ->get();
                
                $salarySlipCount = $slips->count();
                if ($salarySlipCount > 0) {
                    $lastSlipMonth = $slips->first()->salary_month;
                    // Ensure it's in YYYY-MM-DD format
                    if ($lastSlipMonth instanceof \Carbon\Carbon) {
                        $lastSlipMonth = $lastSlipMonth->format('Y-m-d');
                    }
                }
            } catch (\Exception $e) {
                Log::error('Error getting salary slip count', [
                    'user_id' => $user->id,
                    'error' => $e->getMessage()
                ]);
            }

            return [
                'id' => $user->id,
                'fullname' => $user->fullname,
                'email' => $user->email,
                'user_type' => $user->user_type,
                'total_loan_outstanding' => (float) $totalLoanOutstanding,
                'unadjusted_salary_advances' => (float) ($unadjustedAdvances ?? 0),
                'salary_slip_count' => $salarySlipCount,
                'last_slip_month' => $lastSlipMonth,
                'hr_profile' => $user->hrProfile ? [
                    'id' => $user->hrProfile->id,
                    'base_salary' => $user->hrProfile->base_salary,
                    'overtime_rate' => $user->hrProfile->overtime_rate,
                    'late_deduction_rate' => $user->hrProfile->late_deduction_rate,
                    'designation' => $user->hrProfile->designation,
                    'department' => $user->hrProfile->department,
                    'employee_code' => $user->hrProfile->employee_code,
                    'is_active' => $user->hrProfile->is_active,
                ] : null
            ];
        });

        // Calculate statistics
        $statistics = [
            'total' => $users->count(),
            'active' => $users->filter(function($u) { 
                return $u->hrProfile && $u->hrProfile->is_active; 
            })->count(),
            'missing_profiles' => $users->filter(function($u) { 
                return !$u->hrProfile; 
            })->count(),
            'total_salary' => $users->filter(function($u) { 
                return $u->hrProfile && $u->hrProfile->is_active; 
            })->sum(function($u) {
                return $u->hrProfile->base_salary ?? 0;
            })
        ];

        return response()->json([
            'success' => true,
            'employees' => $employees,
            'statistics' => $statistics
        ]);
    }

    /**
     * Get or create employee profile for a user
     */
    public function getOrCreate($userId)
    {
        try {
            $user = UserModel::findOrFail($userId);
            $profile = EmployeeProfileModel::where('user_id', $userId)->first();

            if (!$profile) {
                // Create new profile
                $profile = EmployeeProfileModel::create([
                    'user_id' => $userId,
                    'base_salary' => 0,
                    'overtime_rate' => 0,
                    'late_deduction_rate' => 0,
                    'salary_currency' => 'PKR',
                    'is_active' => 1,
                    'created_by' => auth()->id()
                ]);
            }

            return response()->json([
                'success' => true,
                'employee' => [
                    'id' => $user->id,
                    'fullname' => $user->fullname,
                    'email' => $user->email,
                    'hr_profile' => [
                        'id' => $profile->id,
                        'base_salary' => $profile->base_salary,
                        'overtime_rate' => $profile->overtime_rate,
                        'late_deduction_rate' => $profile->late_deduction_rate,
                        'designation' => $profile->designation,
                        'department' => $profile->department,
                        'employee_code' => $profile->employee_code,
                        'salary_effective_date' => $profile->salary_effective_date,
                        'bank_name' => $profile->bank_name,
                        'bank_account_number' => $profile->bank_account_number,
                        'bank_account_title' => $profile->bank_account_title,
                        'is_active' => $profile->is_active,
                    ]
                ]
            ]);

        } catch (\Exception $e) {
            Log::error('Error getting/creating employee profile', [
                'user_id' => $userId,
                'error' => $e->getMessage()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to get employee profile: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Show employee profile
     */
    public function show($userId)
    {
        $profile = EmployeeProfileModel::with(['user', 'activeLoans', 'salarySlips'])
            ->where('user_id', $userId)
            ->first();

        if (!$profile) {
            return response()->json([
                'success' => false,
                'message' => 'Employee profile not found'
            ], 404);
        }

        $profileInfo = $profile->getEmployeeInfo();
        
        // Add recent salary slips
        $profileInfo['recent_slips'] = $profile->salarySlips()
            ->recent()
            ->limit(5)
            ->get()
            ->map(function($slip) {
                return [
                    'id' => $slip->id,
                    'slip_number' => $slip->slip_number,
                    'month' => $slip->formatted_month,
                    'net_salary' => $slip->net_salary,
                    'status' => $slip->slip_status
                ];
            });

        // Add active loans
        $profileInfo['active_loans'] = $profile->activeLoans->map(function($loan) {
            return $loan->getLoanSummary();
        });

        return response()->json([
            'success' => true,
            'profile' => $profileInfo
        ]);
    }

    /**
     * Update employee salary configuration
     */
    public function updateSalary(Request $request, $userId)
    {
        $validated = $request->validate([
            'base_salary' => 'required|numeric|min:0',
            'overtime_rate' => 'nullable|numeric|min:0',
            'late_deduction_rate' => 'nullable|numeric|min:0',
            'designation' => 'nullable|string|max:100',
            'department' => 'nullable|string|max:100',
            'employee_code' => 'nullable|string|max:50',
            'bank_name' => 'nullable|string|max:100',
            'bank_account_number' => 'nullable|string|max:50',
            'bank_account_title' => 'nullable|string|max:255'
        ]);

        try {
            // Get or create profile
            $profile = EmployeeProfileModel::getOrCreateForUser($userId, auth()->id());

            if (!$profile) {
                return response()->json([
                    'success' => false,
                    'message' => 'Cannot create salary profile for admin users'
                ], 400);
            }

            // Track salary change
            $salaryChanged = $profile->base_salary != $validated['base_salary'];

            // Update profile
            $profile->fill($validated);

            if ($salaryChanged) {
                $profile->previous_salary = $profile->getOriginal('base_salary');
                $profile->last_salary_change_date = now();
                $profile->salary_effective_date = now();
            }

            $profile->updated_by = auth()->id();
            $profile->save();

            return response()->json([
                'success' => true,
                'message' => 'Employee salary configuration updated successfully',
                'profile' => $profile->getEmployeeInfo()
            ]);

        } catch (\Exception $e) {
            Log::error('Error updating employee salary', [
                'user_id' => $userId,
                'error' => $e->getMessage()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to update salary configuration: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get employees without salary profiles
     */
    public function getWithoutProfiles()
    {
        try {
            // Get all active users who don't have salary profiles
            $usersWithoutProfiles = UserModel::leftJoin('t_hr_employee_profile', 't_sys_user.id', '=', 't_hr_employee_profile.user_id')
                ->whereNull('t_hr_employee_profile.id')
                ->where('t_sys_user.is_active', 1)
                ->select('t_sys_user.id', 't_sys_user.fullname', 't_sys_user.email')
                ->get()
                ->map(function($user) {
                    return [
                        'user_id' => $user->id,
                        'fullname' => $user->fullname,
                        'email' => $user->email
                    ];
                });

            return response()->json([
                'success' => true,
                'users' => $usersWithoutProfiles
            ]);
        } catch (\Exception $e) {
            \Log::error('Error getting users without profiles', [
                'error' => $e->getMessage()
            ]);
            
            return response()->json([
                'success' => false,
                'message' => 'Error: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Bulk create salary profiles
     */
    public function bulkCreate(Request $request)
    {
        $validated = $request->validate([
            'user_ids' => 'required|array',
            'user_ids.*' => 'required|integer|exists:t_sys_user,id',
            'default_base_salary' => 'nullable|numeric|min:0',
            'default_overtime_rate' => 'nullable|numeric|min:0',
            'default_late_deduction_rate' => 'nullable|numeric|min:0'
        ]);

        $created = 0;
        $skipped = 0;
        $errors = [];

        foreach ($validated['user_ids'] as $userId) {
            try {
                $profile = EmployeeProfileModel::getOrCreateForUser($userId, auth()->id());
                
                if ($profile) {
                    // Only update if not already configured
                    if (!$profile->hasSalaryConfigured() && isset($validated['default_base_salary'])) {
                        $profile->base_salary = $validated['default_base_salary'];
                        $profile->overtime_rate = $validated['default_overtime_rate'] ?? 0;
                        $profile->late_deduction_rate = $validated['default_late_deduction_rate'] ?? 0;
                        $profile->salary_effective_date = now();
                        $profile->save();
                    }
                    $created++;
                } else {
                    $skipped++; // Admin user
                }
            } catch (\Exception $e) {
                $errors[] = "User ID {$userId}: " . $e->getMessage();
            }
        }

        return response()->json([
            'success' => true,
            'message' => "Created/updated {$created} profiles, skipped {$skipped} (admins)",
            'created' => $created,
            'skipped' => $skipped,
            'errors' => $errors
        ]);
    }

    /**
     * Deactivate employee profile
     */
    public function deactivate($userId)
    {
        try {
            $profile = EmployeeProfileModel::where('user_id', $userId)->firstOrFail();
            $profile->is_active = false;
            $profile->updated_by = auth()->id();
            $profile->save();

            return response()->json([
                'success' => true,
                'message' => 'Employee profile deactivated successfully'
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to deactivate profile: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Reactivate employee profile
     */
    public function activate($userId)
    {
        try {
            $profile = EmployeeProfileModel::where('user_id', $userId)->firstOrFail();
            $profile->is_active = true;
            $profile->updated_by = auth()->id();
            $profile->save();

            return response()->json([
                'success' => true,
                'message' => 'Employee profile activated successfully'
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to activate profile: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get salary slips for a specific employee
     */
    public function getSalarySlips($userId)
    {
        try {
            $slips = \App\Models\HR\SalarySlipModel::where('user_id', $userId)
                ->orderBy('salary_month', 'desc')
                ->orderBy('created_at', 'desc')
                ->get()
                ->map(function($slip) {
                    // Ensure salary_month is in YYYY-MM-DD format for frontend matching
                    $salaryMonth = $slip->salary_month;
                    if ($salaryMonth instanceof \Carbon\Carbon) {
                        $salaryMonth = $salaryMonth->format('Y-m-d');
                    } elseif (is_string($salaryMonth) && strlen($salaryMonth) > 10) {
                        // If it's a datetime string, extract just the date part
                        $salaryMonth = substr($salaryMonth, 0, 10);
                    }
                    
                    return [
                        'id' => $slip->id,
                        'slip_number' => $slip->slip_number,
                        'salary_month' => $salaryMonth,
                        'slip_status' => $slip->slip_status,
                        'gross_salary' => (float) $slip->gross_salary,
                        'total_deductions' => (float) $slip->total_deductions,
                        'net_salary' => (float) $slip->net_salary,
                        'created_at' => $slip->created_at ? $slip->created_at->format('Y-m-d H:i:s') : null
                    ];
                });

            return response()->json([
                'success' => true,
                'slips' => $slips
            ]);

        } catch (\Exception $e) {
            Log::error('Error getting employee salary slips', [
                'user_id' => $userId,
                'error' => $e->getMessage()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to get salary slips: ' . $e->getMessage()
            ], 500);
        }
    }
}

