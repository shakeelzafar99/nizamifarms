<?php


use App\Http\Controllers\AuthController; 
use App\Http\Controllers\MailController; 
use App\Http\Controllers\Webhook\ShopifyController;
use App\Http\Controllers\Webhook\WooController;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Route;


// Public routes (requires no authentication)

Route::group([
    'prefix' => 'public'
], function ($router) {
    Route::get('/sendbasicemail', [MailController::class, 'sendEmail']);
    Route::get('storage-link', function () {
        Artisan::call('storage:link'); // command
        dd("Done!!!");
    });
    Route::get('/xclean', function () {
        $exitCode0 = Artisan::call('config:clear');
        $exitCode1 = Artisan::call('cache:clear');
        $exitCode2 = Artisan::call('view:clear');
        $exitCode3 = Artisan::call('route:clear');
        $exitCode4 = Artisan::call('config:cache');
        dd('CACHE-CLEARED, VIEW-CLEARED, ROUTE-CLEARED & CONFIG-CACHED WAS SUCCESSFUL!');
    });
});

// Webhook Routes
Route::prefix('webhook')->group(function () {

    // Shopify Routes
    Route::prefix('shopify')->controller(ShopifyController::class)->group(function () {
        Route::prefix('order')->group(function () {
            Route::get('get/{id}', 'get');
            Route::post('list', 'list');
            Route::post('store', 'store');
            Route::delete('remove/{id}', 'remove');
        });
    });

    // WooCommerce Routes
    Route::prefix('woo')->controller(WooController::class)->group(function () {
        Route::prefix('order')->group(function () {
            Route::get('get/{id}', 'get');
            Route::post('list', 'list');
            Route::post('store', 'store');
            Route::post('test', 'test'); // Test endpoint for debugging webhooks
            Route::delete('remove/{id}', 'remove');
        });
    });
});

//Webhook

//Route::get('sendbasicemail', 'MailController@sendEmail');

 
 
 


 

  
 
// Protected routes (requires authentication)

// Mobile App API Routes
// These routes are for the mobile app and return JSON responses

// Authentication (uses existing AuthController)
Route::post('/auth/authenticate', [AuthController::class, 'authenticate']);

// Authenticated mobile routes
Route::middleware('auth:sanctum')->group(function () {
    
    // Auth endpoints
    Route::get('/auth/me', [AuthController::class, 'me']);
    Route::get('/auth/logout', [AuthController::class, 'logout']);
    
    // Rider-specific routes
    // App version check (no auth required - anyone can check)
    Route::get('/app/version', [\App\Http\Controllers\API\AppController::class, 'getLatestVersion']);

    Route::prefix('rider')->group(function () {
        // Dashboard
        Route::get('/dashboard', [\App\Http\Controllers\API\RiderController::class, 'dashboard']);
        
        // Orders - reusing existing filter endpoint (enhanced with customer order count)
        Route::get('/orders', [\App\Http\Controllers\CRM\OrderController::class, 'filter']);
        Route::get('/orders/{id}', [\App\Http\Controllers\API\RiderController::class, 'getOrderDetails']);
        Route::post('/orders/{id}/mark-delivered', [\App\Http\Controllers\API\RiderController::class, 'markOrderDelivered']);
        Route::post('/orders/{id}/change-payment-method', [\App\Http\Controllers\API\RiderController::class, 'changePaymentMethod']);
        
        // Customer verified location
        Route::post('/customers/{customerId}/set-verified-location', [\App\Http\Controllers\API\RiderController::class, 'setCustomerVerifiedLocation']);
        
        // Ledger & Settlements
        Route::get('/ledger', [\App\Http\Controllers\API\RiderController::class, 'getLedger']);
        Route::get('/ledger/outstanding-invoices', [\App\Http\Controllers\API\RiderController::class, 'getOutstandingInvoices']);
        Route::get('/ledger/expense-categories', [\App\Http\Controllers\API\RiderController::class, 'getExpenseCategories']);
        Route::post('/ledger/settle', [\App\Http\Controllers\API\RiderController::class, 'settleInvoices']);
        Route::post('/ledger/settle-short-cash', [\App\Http\Controllers\API\RiderController::class, 'settleShortCash']);
        
        // Attendance
        Route::get('/attendance/today', [\App\Http\Controllers\API\RiderController::class, 'getTodayAttendance']);
        Route::post('/attendance/check-in', [\App\Http\Controllers\API\RiderController::class, 'checkIn']);
        Route::post('/attendance/check-out', [\App\Http\Controllers\API\RiderController::class, 'checkOut']);
        Route::post('/attendance/upload-meter-picture', [\App\Http\Controllers\API\RiderController::class, 'uploadMeterPicture']);
        Route::get('/attendance/monthly', [\App\Http\Controllers\API\RiderController::class, 'getMonthlyAttendance']);
        
        // Requests
        Route::get('/requests/categories', [\App\Http\Controllers\API\RiderController::class, 'getRequestCategories']);
        Route::get('/requests', [\App\Http\Controllers\API\RiderController::class, 'getRequests']);
        Route::post('/requests', [\App\Http\Controllers\API\RiderController::class, 'createRequest']);
        
    // Salary
    Route::get('/salary', [\App\Http\Controllers\API\RiderController::class, 'getSalaryInfo']);
    Route::get('/salary/slips/{slipId}', [\App\Http\Controllers\API\RiderController::class, 'getSalarySlipDetails']);
    
    // Mobile Permissions
    Route::get('/permissions', [\App\Http\Controllers\API\RiderController::class, 'getMobilePermissions']);
    
    // Store Mode - Open Orders
    Route::get('/store/order-statuses', [\App\Http\Controllers\API\RiderController::class, 'getOrderStatuses']);
    Route::get('/store/open-orders', [\App\Http\Controllers\API\RiderController::class, 'getStoreOpenOrders']);
    Route::get('/store/riders', [\App\Http\Controllers\API\RiderController::class, 'getActiveRiders']);
    Route::post('/store/assign-rider', [\App\Http\Controllers\API\RiderController::class, 'assignRiderToOrder']);
    Route::post('/store/update-status', [\App\Http\Controllers\API\RiderController::class, 'updateOrderStatus']);
    Route::post('/store/update-packets', [\App\Http\Controllers\API\RiderController::class, 'updatePacketInfo']);
    
    // Store Mode - Open Order Quantities
    Route::get('/store/open-quantities', [\App\Http\Controllers\API\RiderController::class, 'getOpenOrderQuantities']);
    
    // Line Item Status Management (mobile app only - uses token auth)
    Route::post('/orders/{orderId}/line-items/bulk-update-status', [\App\Http\Controllers\API\RiderController::class, 'bulkUpdateLineItemStatus']);
    });
});