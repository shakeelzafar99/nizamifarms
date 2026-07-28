<?php

namespace App\Http\Controllers\SysAdmin;
use App\Http\Controllers\Controller;
use App\Models\SysAdmin\UserModel;
use App\Models\SysAdmin\RoleModel;
use App\Models\SysAdmin\UserRoleModel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;


class UserController extends Controller
{

    protected $userModel;
    public function __construct(UserModel  $userModel)
    {
        $this->userModel = $userModel;
    }

    // Web interface methods
    public function index(Request $request)
    {
        // Get users with their roles (most recent first)
        $users = UserModel::with(['userRoles.role'])->orderBy('created_at', 'desc')->paginate(10);
        
        // Add cash account information to each user
        $users->getCollection()->transform(function($user) {
            // Check if user has employee cash account
            $cashAccount = \DB::table('t_fin_accounts')
                ->where('user_id', $user->id)
                ->where('account_category', 'employee_cash')
                ->where('is_active', 1)
                ->first();
            
            $user->cash_account_id = $cashAccount ? $cashAccount->id : null;
            $user->has_cash_account = ($cashAccount !== null);
            
            return $user;
        });
        
        // Debug: Log user information
        \Log::info('UserController::index - Users count: ' . $users->total());
        \Log::info('UserController::index - Users on current page: ' . $users->count());
        
        // Get all roles for dropdown - use direct DB query to avoid any model issues
        $roles = \DB::table('t_sys_role')->where('is_active', 1)->get();
        
        // Debug: Log roles for troubleshooting
        \Log::info('UserController::index - Roles count: ' . $roles->count());
        
        return view('pages.users.index', compact('users', 'roles'));
    }

    public function show($id)
    {
        try {
            $user = UserModel::with(['userRoles.role'])->findOrFail($id);

            // Compute safe defaults for fields the frontend expects
            $roleId = optional($user->userRoles->first())->role_id; // null if none
            $payload = [
                'id' => $user->id,
                'fullname' => $user->fullname ?? '',
                'email' => $user->email ?? '',
                'user_type' => $user->user_type ?? 'role_based',
                'description' => $user->description ?? '',
                'is_active' => (bool)($user->is_active ?? 1),
                'role_id' => $roleId,
                'user_roles' => $user->userRoles, // keep original for compatibility
            ];

            return response()->json([
                'success' => true,
                'user' => $payload
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'User not found'
            ], 404);
        }
    }

    // Get all active users for dropdowns
    public function allActive(Request $request)
    {
        $users = \DB::table('t_sys_user as u')
            ->leftJoin('t_ops_rider_profile as rp', 'rp.user_id', '=', 'u.id')
            ->leftJoin('t_sys_user_role as ur', 'ur.user_id', '=', 'u.id')
            ->leftJoin('t_sys_role as r', 'r.id', '=', 'ur.role_id')
            ->where('u.is_active', 1)
            ->orderBy('u.fullname')
            ->select(
                'u.id',
                'u.fullname',
                'u.email',
                \DB::raw('COALESCE(rp.shift_start, "09:00") as shift_start'),
                \DB::raw('COALESCE(rp.shift_end, "17:00") as shift_end'),
                'r.urole_name as role_name'
            )
            ->get();

        return response()->json([
            'success' => true,
            'data' => $users
        ]);
    }

    // Bulk user creation
    public function bulkStore(Request $request)
    {
        $request->validate([
            'names' => 'required|string',
            'role_id' => 'required|exists:t_sys_role,id'
        ]);

        try {
            $names = array_filter(array_map('trim', explode("\n", $request->names)));
            
            $created = [];
            $errors = [];
            $skipped = [];

            foreach ($names as $fullname) {
                if (empty($fullname)) continue;

                // Generate email from fullname
                $email = $this->generateEmail($fullname);

                // Check if email already exists
                $existing = UserModel::where('email', $email)->first();
                if ($existing) {
                    $skipped[] = [
                        'name' => $fullname,
                        'reason' => 'Email already exists: ' . $email
                    ];
                    continue;
                }

                try {
                    $user = UserModel::create([
                        'fullname' => $fullname,
                        'email' => $email,
                        'password' => Hash::make('nf123456'), // Default password
                        'user_type' => 'role_based',
                        'is_active' => 1,
                        'company_id' => 1,
                        'branch_id' => 1,
                        'created_by' => auth()->id()
                    ]);

                    // Assign role
                    UserRoleModel::create([
                        'user_id' => $user->id,
                        'role_id' => $request->role_id
                    ]);

                    $created[] = [
                        'name' => $fullname,
                        'email' => $email,
                        'id' => $user->id
                    ];

                } catch (\Exception $e) {
                    $errors[] = [
                        'name' => $fullname,
                        'error' => $e->getMessage()
                    ];
                }
            }

            return response()->json([
                'success' => true,
                'created' => $created,
                'errors' => $errors,
                'skipped' => $skipped,
                'summary' => [
                    'total' => count($names),
                    'created' => count($created),
                    'skipped' => count($skipped),
                    'errors' => count($errors)
                ]
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error processing bulk users: ' . $e->getMessage()
            ], 500);
        }
    }

    // Helper function to generate email from fullname
    private function generateEmail($fullname)
    {
        // Convert to lowercase
        $email = strtolower($fullname);
        
        // Remove special characters and replace spaces with dots
        $email = preg_replace('/[^a-z0-9\s]/', '', $email);
        $email = preg_replace('/\s+/', '.', trim($email));
        
        // Add domain
        $email = $email . '@nizamifarms.com';
        
        return $email;
    }

    /**
     * Check if email is available
     * GET /users/check-email?email=test@example.com
     */
    public function checkEmail(Request $request)
    {
        $email = $request->query('email');
        
        if (!$email) {
            return response()->json([
                'available' => false,
                'message' => 'Email parameter is required'
            ], 400);
        }
        
        // Check if email exists
        $exists = UserModel::where('email', $email)->exists();
        
        return response()->json([
            'available' => !$exists,
            'email' => $email
        ]);
    }

    public function store(Request $request)
    {
        $request->validate([
            'fullname' => 'required|string|max:255',
            'email' => 'required|email|unique:t_sys_user,email',
            'password' => 'required|min:6',
            'role_id' => 'required|exists:t_sys_role,id'
        ]);

        try {
            $user = UserModel::create([
                'fullname' => $request->fullname,
                'email' => $request->email,
                'password' => Hash::make($request->password),
                'user_type' => $request->role_id ? 'role_based' : ($request->user_type ?? 'branch_user'),
                'description' => $request->description,
                'is_active' => $request->is_active ?? 1,
                'company_id' => 1, // Default company
                'branch_id' => 1, // Default branch to avoid SQL error
                'created_by' => auth()->id()
            ]);

            // Always assign the selected role
            UserRoleModel::create([
                'user_id' => $user->id,
                'role_id' => $request->role_id
            ]);

            // A new rider-role account starts as an ACTIVE delivery rider, so he is assignable
            // the moment he exists. Before this, a new rider had no rider_profile row at all and
            // was invisible in every assign list until someone hand-ticked "Delivery Rider".
            $this->ensureRiderProfile((int) $user->id, (int) $request->role_id);

            return redirect()->route('users.index')
                ->with('success', 'User created successfully!')
                ->withHeaders(['Cache-Control' => 'no-cache, no-store, must-revalidate']);
        } catch (\Exception $e) {
            return redirect()->back()->with('error', 'Error creating user: ' . $e->getMessage());
        }
    }

    public function update(Request $request, $id)
    {
        $request->validate([
            'fullname' => 'required|string|max:255',
            'email' => 'required|email|unique:t_sys_user,email,' . $id,
            'role_id' => 'nullable|exists:t_sys_role,id'
        ]);

        try {
            $user = UserModel::findOrFail($id);
            
            // Always default to role_based since we removed user_type from UI
            $updateData = [
                'fullname' => $request->fullname,
                'email' => $request->email,
                'user_type' => 'role_based',
                'description' => $request->description,
                'is_active' => $request->is_active ?? 1,
                'updated_by' => auth()->id()
            ];

            // Only update password if provided
            if ($request->filled('password')) {
                $updateData['password'] = Hash::make($request->password);
            }

            $user->update($updateData);

            // Update role - only if provided
            UserRoleModel::where('user_id', $id)->delete();
            if ($request->role_id) {
                UserRoleModel::create([
                    'user_id' => $id,
                    'role_id' => $request->role_id
                ]);
                // Promoted INTO a rider role → give him a profile too (same as create).
                $this->ensureRiderProfile((int) $id, (int) $request->role_id);
            }

            return redirect()->route('users.index')->with('success', 'User updated successfully!');
        } catch (\Exception $e) {
            return redirect()->back()->with('error', 'Error updating user: ' . $e->getMessage());
        }
    }

    /**
     * Give a rider-role user an ACTIVE rider profile ("Delivery Rider" ticked) if he has none.
     *
     * NEVER touches an existing row: unticking someone in the People & Rider List is a deliberate
     * act (a stood-down rider, an office person), and editing their name later must not silently
     * put them back on the roster. Non-fatal — creating a user must never fail over this.
     */
    private function ensureRiderProfile(int $userId, int $roleId): void
    {
        try {
            if (!$userId || !$roleId) { return; }
            if (\DB::table('t_sys_role')->where('id', $roleId)->value('type') !== 'rider') { return; }
            if (\DB::table('t_ops_rider_profile')->where('user_id', $userId)->exists()) { return; }
            \DB::table('t_ops_rider_profile')->insert([
                'user_id' => $userId,
                'active' => 1,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        } catch (\Throwable $e) {
            \Log::warning('ensureRiderProfile failed (non-fatal)', ['user_id' => $userId, 'error' => $e->getMessage()]);
        }
    }

    public function destroy($id)
    {
        try {
            $user = UserModel::findOrFail($id);
            
            // Don't allow deleting yourself
            if ($user->id == auth()->id()) {
                return redirect()->back()->with('error', 'You cannot delete your own account!');
            }

            // Delete user roles first
            UserRoleModel::where('user_id', $id)->delete();
            
            // Delete user
            $user->delete();

            return redirect()->route('users.index')->with('success', 'User deleted successfully!');
        } catch (\Exception $e) {
            return redirect()->back()->with('error', 'Error deleting user: ' . $e->getMessage());
        }
    }

    public function change_password(Request $request)
    {
        try {     
            $response = $this->userModel->change_password($request->all());
            return $this->success($response);
        } catch (\Exception $e) {
            return $this->error($e->getMessage(),$e->getCode());
        }
    }

    // Legacy API methods (keeping for backward compatibility)
    function list(Request $request)
    {
        try {     
            $response = $this->userModel->List($request->all());
            return $this->success($response);
        } catch (\Exception $e) {
            return $this->error($e->getMessage(),$e->getCode());
        }
    }

    function get($id)
    {
        try {          
            $response = $this->userModel->Get($id);
            return $this->success($response);
        } catch (\Exception $e) {
            return $this->error($e->getMessage(),$e->getCode());
        }  
    }

    function storeApi(Request $request) //ADD   
    {     
        try {            
            $response = $this->userModel->Store($request->all()); 
        return $this->success($response);
        } catch (\Exception $e) {
            return $this->error($e->getMessage(),$e->getCode());
        }  
    } 

    function remove(Request $request) //DELETE
    {
        try {          
            $id = $request->id; 
            $response = $this->userModel->Remove($id);
            return $this->success($response);
        } catch (\Exception $e) {
            return $this->error($e->getMessage(),$e->getCode());
        } 
    }
    
    /**
     * Create cash account for user (AJAX)
     */
    public function createCashAccount($id)
    {
        try {
            $user = UserModel::findOrFail($id);
            
            // Check if already has account
            $existing = \DB::table('t_fin_accounts')
                ->where('user_id', $user->id)
                ->where('account_category', 'employee_cash')
                ->first();
            
            if ($existing) {
                return response()->json([
                    'success' => false,
                    'message' => 'User already has a cash account'
                ]);
            }
            
            // Create account using the AccountModel method
            $account = \App\Models\FIN\AccountModel::createEmployeeCashAccount(
                $user->id, 
                $user->fullname ?? $user->name ?? 'User #' . $user->id
            );
            
            return response()->json([
                'success' => true,
                'message' => 'Cash account created successfully!',
                'account_id' => $account->id,
                'account_name' => $account->account_name
            ]);
            
        } catch (\Exception $e) {
            \Log::error('Error creating cash account: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Error creating account: ' . $e->getMessage()
            ], 500);
        }
    }
   
}
