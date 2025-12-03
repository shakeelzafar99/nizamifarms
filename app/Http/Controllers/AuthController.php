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
        if ($request->expectsJson()) {
            // Get the token from the request
            $token = $request->bearerToken();
            
            // ⭐ ALWAYS try to delete the user's tokens (for proper "Logged Out" status)
            $user = $request->user();
            if ($user) {
                // Delete ALL tokens for this user (not just current)
                $user->tokens()->delete();
                
                // ⭐ Clear app_version on logout to indicate logged out state
                $user->update([
                    'app_version' => null,
                    'app_version_updated_at' => null,
                ]);
            }
            
            // Update log status if found
            $userLog = LogModel::where('session_id', $token)->first();
            if ($userLog) {
                $userLog->update(['status' => 'logout']);
            }

            return response()->json(['message' => 'Successfully logged out']);
        }
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect('/auth/login');
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
        
        // Determine default view: 'store' if user has store access, otherwise 'rider'
        $defaultView = $hasStoreAccess ? 'store' : 'rider';
        
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
            'default_view' => $defaultView, // Default starting view for mobile app
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
