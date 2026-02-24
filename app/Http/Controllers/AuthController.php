<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\SysAdmin\AuthModel;
use App\Models\SysAdmin\UserModel;
use App\Models\SysAdmin\LogModel;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use Tymon\JWTAuth\Exceptions\JWTException;
use Tymon\JWTAuth\Facades\JWTAuth as JWTAuth;

class AuthController extends Controller
{
    /**
     * Create a new AuthController instance.
     *
     * @return void
     */


    protected $authModel;
    public function __construct(AuthModel  $authModel)
    {
        $this->authModel = $authModel;
    }


    public function login()
    {
        return view('pages.auth.login'); // View file: resources/views/auth/login.blade.php
    }

    public function forgotPassword(Request $request)
    {
        try {
            $response = $this->authModel->forgotPassword($request->all());
            return $this->success($response);
        } catch (\Exception $e) {
            dd($e->getMessage());
        }
    }
    public function resetPassword(Request $request)
    {
        try {
            $response = $this->authModel->resetPassword($request->all());
            return $this->success($response);
        } catch (\Exception $e) {
            dd($e->getMessage());
        }
    }
    public function authenticate(Request $request)
    {
        // Validate input
        $credentials = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required'],
        ]);

        $user = User::where('email', $credentials['email'])->first();

        if (!$user || !Hash::check($credentials['password'], $user->password)) {
            // Log failed login attempt only if user exists
            if ($user) {
                $userLog = new LogModel([
                    'user_id' => $user->id,
                    'terminal' => $request->ip(),
                    'status' => 'Invalid-credentials',
                ]);
                $userLog->save();
            }

            return $request->expectsJson()
                ? response()->json(['isError' => true, 'message' => 'Invalid credentials.'], 401)
                : back()->withErrors(['email' => 'Invalid credentials'])->withInput();
        }

        // Create log model for successful login
        $userLog = new LogModel([
            'user_id' => $user->id,
            'terminal' => $request->ip(),
            'status' => 'login',
        ]);

        // User authenticated
        $token = $user->createToken('gx-token')->plainTextToken;
        $userLog->session_id = $token;
        $userLog->save();
        
        // ⭐ Store app version if provided (mobile app login)
        $appVersion = $request->input('app_version');
        if ($appVersion && is_string($appVersion)) {
            $user->app_version = substr($appVersion, 0, 20);
            $user->app_version_updated_at = now();
            $user->save();
        }

        if ($request->expectsJson()) {
            return $this->respondWithToken($token, $user, $credentials['password']);
        }

        // Laravel web session auth (only needed if using session-based auth)
        Auth::guard('web')->login($user);
        $request->session()->regenerate();

        // ⭐ Redirect based on user's mode access (priority: main dashboard > khaas dashboard)
        // Khaas-only users go directly to the Khaas dashboard
        $user->load(['roles.mobilePermissions']);
        $webPermissions = $user->getMobilePermissions();
        $hasKhaasWeb = in_array('access_khaas_mode', $webPermissions);
        $hasStoreWeb = in_array('access_store_mode', $webPermissions);
        
        // If user has only Khaas access (no store/admin), redirect to Khaas dashboard
        if ($hasKhaasWeb && !$hasStoreWeb && !in_array($user->user_type, ['admin'])) {
            return redirect()->intended('khaas');
        }

        return redirect()->intended('dashboard');
    }




    /**
     * Get the authenticated User
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function me()
    {
        $user = new UserModel();
        $_user = $user->Get(Auth::id());
        return response()->json($_user->data);
    }

    /**
     * Log the user out (Invalidate the token)
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function logout(Request $request)
    {
        // ⭐ Handle API logout (always process if we have a bearer token)
        $token = $request->bearerToken();
        $user = $request->user();
        
        \Log::info('Logout attempt', [
            'has_token' => !empty($token),
            'has_user' => !empty($user),
            'user_id' => $user ? $user->id : null,
            'user_name' => $user ? $user->fullname : null,
            'expects_json' => $request->expectsJson(),
        ]);
        
        // If we have a user (from bearer token), process API logout
        if ($user) {
            // Count tokens before deletion
            $tokensBefore = $user->tokens()->count();
            
            // Delete ALL tokens for this user
            $user->tokens()->delete();
            
            // Count tokens after deletion
            $tokensAfter = $user->tokens()->count();
            
            // Clear app_version on logout
            $user->update([
                'app_version' => null,
                'app_version_updated_at' => null,
            ]);
            
            \Log::info('Logout successful', [
                'user_id' => $user->id,
                'user_name' => $user->fullname,
                'tokens_before' => $tokensBefore,
                'tokens_after' => $tokensAfter,
            ]);
            
            // Update log status if found
            if ($token) {
                $userLog = LogModel::where('session_id', $token)->first();
                if ($userLog) {
                    $userLog->update(['status' => 'logout']);
                }
            }

            return response()->json(['message' => 'Successfully logged out', 'tokens_deleted' => $tokensBefore]);
        }
        
        // Fallback for requests without user
        if ($request->expectsJson()) {
            \Log::warning('Logout called but no user found', ['token' => substr($token ?? '', 0, 20) . '...']);
            return response()->json(['message' => 'No active session to logout']);
        }
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect('/auth/login');
    }


    /**
     * Web logout - always works even if session/token is expired.
     * Clears session, cookies, and redirects to login page.
     */
    public function webLogout(Request $request)
    {
        // Try to delete API tokens if user is authenticated
        try {
            $user = Auth::user();
            if ($user) {
                $user->tokens()->delete();
                \Log::info('Web logout - tokens deleted', ['user_id' => $user->id]);
            }
        } catch (\Exception $e) {
            // Ignore - user might not be authenticated
        }
        
        // Always clear the web session
        try {
            Auth::guard('web')->logout();
        } catch (\Exception $e) {
            // Ignore
        }
        
        try {
            $request->session()->invalidate();
            $request->session()->regenerateToken();
        } catch (\Exception $e) {
            // Ignore - session might already be invalid
        }
        
        return redirect('/auth/login')->with('message', 'You have been logged out successfully.');
    }

    /**
     * Refresh a token.
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function refresh()
    {
        return $this->respondWithToken(Auth::refresh());
    }

    /**
     * Get the token array structure.
     *
     * @param  string $token
     * @param  \App\Models\User $user
     * @param  string|null $password
     *
     * @return \Illuminate\Http\JsonResponse
     */
    protected function respondWithToken($token, $user = null, $password = null)
    {
        $expirationTime = Carbon::now()->addMinutes(60);
        
        // Get user data - use passed user or Auth::user() for backward compatibility
        if (!$user) {
            $user = Auth::user();
        }
        
        // Load user roles with mobile permissions for permission check
        $user->load(['roles.mobilePermissions']);
        
        // Get mobile permissions
        $mobilePermissions = $user->getMobilePermissions();
        $hasStoreAccess = in_array('access_store_mode', $mobilePermissions);
        $hasKhaasAccess = in_array('access_khaas_mode', $mobilePermissions);
        
        // Determine default view based on available modes
        // Priority: store > khaas > rider
        if ($hasStoreAccess) {
            $defaultView = 'store';
        } elseif ($hasKhaasAccess) {
            $defaultView = 'khaas';
        } else {
            $defaultView = 'rider';
        }
        
        // ⭐ Get Khaas business unit info if user has khaas access
        $khaasBusinessUnit = null;
        if ($hasKhaasAccess) {
            $khaasBusinessUnit = \App\Models\FIN\BusinessUnitModel::where('code', 'KHAAS')
                ->where('is_active', 1)
                ->first(['id', 'code', 'name', 'short_code', 'color_hex']);
        }
        
        // ⭐ Get expense backdate days from user's roles (take maximum)
        $expenseBackdateDays = \DB::table('t_sys_user_role as ur')
            ->join('t_sys_role as r', 'r.id', '=', 'ur.role_id')
            ->where('ur.user_id', $user->id)
            ->max('r.expense_backdate_days') ?? 0;
        
        $response = [
            'isError' => false,
            'access_token' => $token, // Mobile app expects this key
            'authToken' => $token, // Keep for backward compatibility with webapp
            'refreshToken' => $token,
            'tokenType' => 'bearer',
            'expires_at' => $expirationTime,
            'user' => [ // Add user data for mobile app
                'id' => $user->id,
                'fullname' => $user->fullname,
                'email' => $user->email,
                'user_type' => $user->user_type,
            ],
            'mobile_permissions' => $mobilePermissions, // All mobile permissions
            'has_store_access' => $hasStoreAccess, // Quick check for store access
            'has_khaas_access' => $hasKhaasAccess, // ⭐ Quick check for khaas access
            'khaas_business_unit' => $khaasBusinessUnit, // ⭐ Khaas BU details (id, name, color)
            'default_view' => $defaultView, // Default starting view for mobile app
            'expense_backdate_days' => (int)$expenseBackdateDays, // ⭐ Expense backdate days allowed
        ];
        
        // Add password to response if provided (for mobile app to store securely)
        if ($password !== null) {
            $response['password'] = $password;
        }
        
        return response()->json($response);
    }

    /**
     * Get the guard to be used during authentication.
     *
     * @return \Illuminate\Contracts\Auth\Guard
     */
    public function guard()
    {
        return Auth::guard();
    }

    function changePassword()
    {
        try {
            $response = $this->authModel->Tree();
            return $this->success($response);
        } catch (\Exception $e) {
            return $this->error($e->getMessage(), $e->getCode());
        }
    }
}
