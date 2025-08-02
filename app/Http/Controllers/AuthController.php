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

        // Create log model early
        $userLog = new LogModel([
            'user_id' => $user->id ?? null,
            'terminal' => $request->ip(),
            'status' => 'login',
        ]);

        if (!$user || !Hash::check($credentials['password'], $user->password)) {
            $userLog->status = 'Invalid-credentials';
            $userLog->save();

            return $request->expectsJson()
                ? response()->json(['isError' => true, 'message' => 'Invalid credentials.'], 401)
                : back()->withErrors(['email' => 'Invalid credentials'])->withInput();
        }

        // User authenticated
        $token = $user->createToken('gx-token')->plainTextToken;
        $userLog->session_id = $token;
        $userLog->save();

        if ($request->expectsJson()) {
            return $this->respondWithToken($token);
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
            $userLog = LogModel::where('session_id', $token)->first();
            if ($userLog) {
                // Revoke the current token
                if ($request->user()) {
                    $request->user()->tokens->each(function ($token) {
                        $token->delete();  // This will revoke all tokens associated with the user
                    });
                }
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
     *
     * @return \Illuminate\Http\JsonResponse
     */
    protected function respondWithToken($token)
    {
        $expirationTime = Carbon::now()->addMinutes(60);
        return response()->json([
            'isError' => false,
            'authToken' => $token,
            'refreshToken' => $token,
            'tokenType' => 'bearer',
            'expires_at' => $expirationTime
        ]);
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
