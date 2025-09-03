<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\SysAdmin\MenuController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\CRM\OrderController;

// Redirect root to demo1
Route::get('/', function () {
    return redirect('/auth/login');
});

Route::group([
    'prefix' => 'auth'
], function ($router) {
    Route::get('/login', [AuthController::class, 'login'])->name('login');
    Route::post('/authenticate', [AuthController::class, 'authenticate'])->name('authenticate');
    Route::post('/forgot-password', [AuthController::class, 'forgotPassword']);
    Route::post('/reset-password', [AuthController::class, 'resetPassword']);
});

// Protected routes (requires authentication)
Route::group([
    'middleware' => ['auth:sanctum'],
    'prefix' => 'auth'
], function ($router) {
    Route::get('/me', [AuthController::class, 'me']);
    Route::get('/logout', [AuthController::class, 'logout']);
    Route::get('/refresh', [AuthController::class, 'refresh']);
    Route::post('/menu', [MenuController::class, 'list']);
});


Route::middleware(['auth'])->group(function () {
    Route::get('/dashboard', [DashboardController::class, 'index']);
    Route::get('/orders', [OrderController::class, 'index']);
    Route::get('/orders/{id}', [OrderController::class, 'show'])->name('orders.show');
    Route::post('/orders/import-orders', [OrderController::class, 'importOrders'])->name('orders.importOrders');
    
    // User Management Routes
    Route::prefix('users')->group(function () {
        Route::get('/', [\App\Http\Controllers\SysAdmin\UserController::class, 'index'])->name('users.index');
        Route::get('/{id}', [\App\Http\Controllers\SysAdmin\UserController::class, 'show'])->name('users.show');
        Route::post('/', [\App\Http\Controllers\SysAdmin\UserController::class, 'store'])->name('users.store');
        Route::put('/{id}', [\App\Http\Controllers\SysAdmin\UserController::class, 'update'])->name('users.update');
        Route::delete('/{id}', [\App\Http\Controllers\SysAdmin\UserController::class, 'destroy'])->name('users.destroy');
    });
    
    // Role Management Routes
    Route::prefix('roles')->group(function () {
        Route::get('/', [\App\Http\Controllers\SysAdmin\RoleController::class, 'index'])->name('roles.index');
        Route::get('/{id}', [\App\Http\Controllers\SysAdmin\RoleController::class, 'show'])->name('roles.show');
        Route::post('/', [\App\Http\Controllers\SysAdmin\RoleController::class, 'store'])->name('roles.store');
        Route::put('/{id}', [\App\Http\Controllers\SysAdmin\RoleController::class, 'update'])->name('roles.update');
        Route::delete('/{id}', [\App\Http\Controllers\SysAdmin\RoleController::class, 'destroy'])->name('roles.destroy');
    });
});
