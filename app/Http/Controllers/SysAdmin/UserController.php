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
        // Get users with their roles
        $users = UserModel::with(['userRoles.role'])->paginate(10);
        
        // Get all roles for dropdown - use direct DB query to avoid any model issues
        $roles = \DB::table('t_sys_role')->where('is_active', 1)->get();
        
        // Debug: Log roles for troubleshooting
        \Log::info('UserController::index - Roles count: ' . $roles->count());
        \Log::info('UserController::index - Roles data: ' . $roles->toJson());
        
        return view('pages.users.index', compact('users', 'roles'));
    }

    public function show($id)
    {
        try {
            $user = UserModel::with(['userRoles.role'])->findOrFail($id);
            
            return response()->json([
                'success' => true,
                'user' => $user
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'User not found'
            ], 404);
        }
    }

    public function store(Request $request)
    {
        $request->validate([
            'fullname' => 'required|string|max:255',
            'email' => 'required|email|unique:t_sys_user,email',
            'password' => 'required|min:6',
            'user_type' => 'required|string',
            'role_id' => 'nullable|exists:t_sys_role,id' // Made optional
        ]);

        try {
            // Log the request data for debugging
            \Log::info('Creating user with data:', $request->all());
            
            // Create user
            $user = UserModel::create([
                'fullname' => $request->fullname,
                'email' => $request->email,
                'password' => Hash::make($request->password),
                'user_type' => $request->user_type,
                'description' => $request->description,
                'is_active' => $request->is_active ?? 1,
                'created_by' => auth()->id()
            ]);

            \Log::info('User created successfully with ID: ' . $user->id);

            // Assign role only if provided
            if ($request->role_id) {
                UserRoleModel::create([
                    'user_id' => $user->id,
                    'role_id' => $request->role_id
                ]);
                \Log::info('Role assigned to user: ' . $request->role_id);
            }

            return redirect()->route('users.index')->with('success', 'User created successfully!');
        } catch (\Exception $e) {
            \Log::error('Error creating user: ' . $e->getMessage(), [
                'request_data' => $request->all(),
                'trace' => $e->getTraceAsString()
            ]);
            return redirect()->back()->with('error', 'Error creating user: ' . $e->getMessage());
        }
    }

    public function update(Request $request, $id)
    {
        $request->validate([
            'fullname' => 'required|string|max:255',
            'email' => 'required|email|unique:t_sys_user,email,' . $id,
            'user_type' => 'required|string',
            'role_id' => 'nullable|exists:t_sys_role,id' // Made optional
        ]);

        try {
            $user = UserModel::findOrFail($id);
            
            $updateData = [
                'fullname' => $request->fullname,
                'email' => $request->email,
                'user_type' => $request->user_type,
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
            }

            return redirect()->route('users.index')->with('success', 'User updated successfully!');
        } catch (\Exception $e) {
            return redirect()->back()->with('error', 'Error updating user: ' . $e->getMessage());
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
   
}
