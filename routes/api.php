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

// App version check - PUBLIC endpoint (no auth required - anyone can check for updates)
Route::get('/app/version', [\App\Http\Controllers\API\AppController::class, 'getLatestVersion']);

// Authenticated mobile routes
Route::middleware('auth:sanctum')->group(function () {
    
    // Auth endpoints
    Route::get('/auth/me', [AuthController::class, 'me']);
    Route::get('/auth/logout', [AuthController::class, 'logout']);

    Route::prefix('rider')->group(function () {
        // Dashboard
        Route::get('/dashboard', [\App\Http\Controllers\API\RiderController::class, 'dashboard']);
        
        // Orders - reusing existing filter endpoint (enhanced with customer order count)
        Route::get('/orders', [\App\Http\Controllers\CRM\OrderController::class, 'filter']);
        Route::get('/orders/{id}', [\App\Http\Controllers\API\RiderController::class, 'getOrderDetails']);
        Route::post('/orders/{id}/mark-delivered', [\App\Http\Controllers\API\RiderController::class, 'markOrderDelivered']);
        Route::post('/orders/{id}/change-payment-method', [\App\Http\Controllers\API\RiderController::class, 'changePaymentMethod']);
        Route::post('/orders/{id}/mark-online-message-sent', [\App\Http\Controllers\API\RiderController::class, 'markOnlineMessageSent']);
        
        // Customer verified location
        Route::post('/customers/{customerId}/set-verified-location', [\App\Http\Controllers\API\RiderController::class, 'setCustomerVerifiedLocation']);
        
        // Quick verify location from address (Store Mode)
        Route::post('/store/orders/{orderId}/set-verified-location', [\App\Http\Controllers\API\RiderController::class, 'setVerifiedLocationFromAddress']);
        
        // Ledger & Settlements
        Route::get('/ledger', [\App\Http\Controllers\API\RiderController::class, 'getLedger']);
        Route::get('/ledger/outstanding-invoices', [\App\Http\Controllers\API\RiderController::class, 'getOutstandingInvoices']);
        Route::get('/ledger/expense-categories', [\App\Http\Controllers\API\RiderController::class, 'getExpenseCategories']);
        Route::post('/ledger/settle', [\App\Http\Controllers\API\RiderController::class, 'settleInvoices']);
        Route::post('/ledger/settle-short-cash', [\App\Http\Controllers\API\RiderController::class, 'settleShortCash']);
        
        // Overall Ledger
        Route::get('/overall-ledger', [\App\Http\Controllers\API\RiderController::class, 'getOverallLedger']);
        
        // Daily Closing (Invoice Tracker)
        Route::get('/daily-closing', [\App\Http\Controllers\API\RiderController::class, 'getDailyClosing']);
        Route::post('/daily-closing/approve/{id}', [\App\Http\Controllers\API\RiderController::class, 'approveDailyClosingSettlement']);
        Route::post('/daily-closing/reject/{id}', [\App\Http\Controllers\API\RiderController::class, 'rejectDailyClosingSettlement']);
        
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
    
    // Approvals (Admin/Manager users)
    Route::prefix('approvals')->group(function () {
        Route::get('/', [\App\Http\Controllers\API\ApprovalsAPIController::class, 'index']);
        Route::get('/summaries', [\App\Http\Controllers\API\ApprovalsAPIController::class, 'summaries']);
        Route::get('/online', [\App\Http\Controllers\API\ApprovalsAPIController::class, 'onlineOnly']); // ⭐ Fast endpoint for online only
    });
    
    // Mobile Permissions
    Route::get('/permissions', [\App\Http\Controllers\API\RiderController::class, 'getMobilePermissions']);
    
    // Active Users (for creating requests for others)
    Route::get('/users/active', [\App\Http\Controllers\API\RiderController::class, 'getActiveUsers']);
    
    // Store Mode - Open Orders
    Route::get('/store/order-statuses', [\App\Http\Controllers\API\RiderController::class, 'getOrderStatuses']);
    Route::get('/store/open-orders', [\App\Http\Controllers\API\RiderController::class, 'getStoreOpenOrders']);
    Route::get('/store/open-orders-light', [\App\Http\Controllers\API\RiderController::class, 'getStoreOpenOrdersLight']); // Lightweight for list
    Route::get('/store/open-orders/{id}/details', [\App\Http\Controllers\API\RiderController::class, 'getStoreOpenOrderDetails']); // Full details when expanded
    Route::get('/store/delivered-orders', [\App\Http\Controllers\API\RiderController::class, 'getStoreDeliveredOrders']); // ⭐ Delivered orders grouped by date
    Route::get('/store/cancelled-orders', [\App\Http\Controllers\API\RiderController::class, 'getStoreCancelledOrders']); // ⭐ Cancelled orders grouped by date
    Route::get('/store/delivered-quantities-tree', [\App\Http\Controllers\API\RiderController::class, 'getDeliveredQuantitiesTree']); // ⭐ Delivered quantities with drill-down (lazy)
    Route::get('/store/delivered-quantities-full-tree', [\App\Http\Controllers\API\RiderController::class, 'getDeliveredQuantitiesFullTree']); // ⭐ Full tree for instant access (last 10 days)
    Route::get('/store/riders', [\App\Http\Controllers\API\RiderController::class, 'getActiveRiders']);
    Route::post('/store/assign-rider', [\App\Http\Controllers\API\RiderController::class, 'assignRiderToOrder']);
    Route::post('/store/update-status', [\App\Http\Controllers\API\RiderController::class, 'updateOrderStatus']);
    Route::post('/store/update-packets', [\App\Http\Controllers\API\RiderController::class, 'updatePacketInfo']);
    Route::post('/store/update-order-note', [\App\Http\Controllers\API\RiderController::class, 'updateOrderNote']); // ⭐ Add order instructions
    Route::post('/store/add-customer-note', [\App\Http\Controllers\API\RiderController::class, 'addCustomerNote']); // ⭐ Pin note to customer profile
    Route::post('/store/update-payment-method', [\App\Http\Controllers\API\RiderController::class, 'updatePaymentMethod']); // ⭐ Change payment type
    Route::post('/store/update-delivery-priorities', [\App\Http\Controllers\API\RiderController::class, 'updateDeliveryPriorities']); // ⭐ Set delivery sequence
    
    // Store Mode - Open Order Quantities
    Route::get('/store/open-quantities', [\App\Http\Controllers\API\RiderController::class, 'getOpenOrderQuantities']);
    Route::get('/store/open-quantities-tree', [\App\Http\Controllers\API\RiderController::class, 'getOpenOrderQuantitiesTree']);
    // Fixed 3-level hierarchy (attribute_1 -> attribute_2 -> attribute_3 -> product -> orders) for mobile
    Route::get(
        '/store/open-quantities-tree-fixed',
        [\App\Http\Controllers\API\OpenQuantitiesFixedController::class, 'getFixedThreeLevelTree']
    );
    
    // Line Item Status Management (mobile app only - uses token auth)
    Route::post('/orders/{orderId}/line-items/bulk-update-status', [\App\Http\Controllers\API\RiderController::class, 'bulkUpdateLineItemStatus']);
    Route::post('/orders/bulk-mark-prepared', [\App\Http\Controllers\CRM\OrderController::class, 'bulkMarkOrdersAsPrepared']);
    
    // Shopify Order Actions (Store Mode - uses token auth)
    Route::post('/orders/{id}/convert', [\App\Http\Controllers\CRM\OrderController::class, 'convertOrder']);
    Route::post('/orders/{id}/ignore', [\App\Http\Controllers\CRM\OrderController::class, 'ignoreOrder']);
    
    // Expense Management (Store Mode)
    Route::get('/expenses', [\App\Http\Controllers\API\RiderController::class, 'getExpenses']);
    Route::get('/expenses/fund-transfers', [\App\Http\Controllers\API\RiderController::class, 'getFundTransfers']);
    Route::get('/expenses/payment-sources', [\App\Http\Controllers\API\RiderController::class, 'getPaymentSources']);
    Route::post('/expenses/set-default-account', [\App\Http\Controllers\API\RiderController::class, 'setBuDefaultExpenseAccount']); // ⭐ Set default expense account for a BU
    Route::get('/expenses/categories', [\App\Http\Controllers\API\RiderController::class, 'getExpenseCategoriesFromConfig']); // Fetch expense types from config
    Route::post('/expenses/categories', [\App\Http\Controllers\FIN\ExpenseCategoryController::class, 'store']); // Create new expense category (reuses web controller)
    Route::post('/expenses/{id}/approve', [\App\Http\Controllers\API\RiderController::class, 'approveExpense']);
    Route::post('/expenses/{id}/reject', [\App\Http\Controllers\API\RiderController::class, 'rejectExpense']);
    Route::post('/expenses/{id}/settle', [\App\Http\Controllers\API\RiderController::class, 'settleExpense']);
    Route::delete('/expenses/{id}', [\App\Http\Controllers\API\RiderController::class, 'deleteExpense']); // L2 only
    Route::post('/expenses/{id}/delete', [\App\Http\Controllers\API\RiderController::class, 'deleteExpense']); // L2 only - POST alternative
    
    // NF Ledger (Store Mode - requires view_nf_ledger permission)
    Route::get('/nf-ledger/accounts', [\App\Http\Controllers\API\RiderController::class, 'getNFLedgerAccounts']);
    Route::get('/nf-ledger/accounts/{accountId}', [\App\Http\Controllers\API\RiderController::class, 'getNFLedgerDetails']);
    Route::post('/nf-ledger/accounts/{accountId}/petty-cash', [\App\Http\Controllers\API\RiderController::class, 'updatePettyCash']);
    Route::get('/nf-ledger/transfer-accounts', [\App\Http\Controllers\API\RiderController::class, 'getTransferAccounts']);
    Route::post('/nf-ledger/transfer', [\App\Http\Controllers\API\RiderController::class, 'processTransfer']);
    
    // Assets (Store Mode - requires view_assets permission)
    Route::get('/nf-ledger/assets', [\App\Http\Controllers\FIN\AssetController::class, 'apiIndex']);
    Route::get('/nf-ledger/assets/form-data', [\App\Http\Controllers\FIN\AssetController::class, 'apiFormData']);
    Route::get('/nf-ledger/assets/{id}', [\App\Http\Controllers\FIN\AssetController::class, 'apiShow']);
    Route::post('/nf-ledger/assets', [\App\Http\Controllers\FIN\AssetController::class, 'apiStore']);
    
    // Overall Ledger (Store Mode - requires view_nf_ledger permission)
    Route::get('/overall-ledger', [\App\Http\Controllers\API\RiderController::class, 'getOverallLedger']);
    
        // Store Attendance (Store Mode - requires view_store_attendance permission)
        Route::get('/store-attendance/daily', [\App\Http\Controllers\API\RiderController::class, 'getStoreAttendanceDaily']);
        Route::get('/store-attendance/monthly', [\App\Http\Controllers\API\RiderController::class, 'getStoreAttendanceMonthly']);
        Route::get('/store-attendance/employee-details', [\App\Http\Controllers\API\RiderController::class, 'getStoreAttendanceEmployeeDetails']);
        Route::post('/store-attendance/update-meter-values', [\App\Http\Controllers\API\RiderController::class, 'updateMeterValues']);
        
        // ⭐ Road Distance Calculation (on-demand) - uses OpenRouteService API
        Route::get('/store-attendance/calculate-road-distance', [\App\Http\Controllers\API\RiderController::class, 'calculateRoadDistance']);
        
        // ⭐ Employee Profiles (Store Mode - for salary management)
        Route::get('/store-salary/employees', [\App\Http\Controllers\HR\EmployeeProfileController::class, 'getData']);
        
        // ⭐ Loan Management (Store Mode - same as web)
        Route::post('/store-salary/loans', [\App\Http\Controllers\HR\EmployeeLoanController::class, 'store']);
        Route::put('/store-salary/loans/{id}', [\App\Http\Controllers\HR\EmployeeLoanController::class, 'update']);
        Route::post('/store-salary/loans/{id}/cancel', [\App\Http\Controllers\HR\EmployeeLoanController::class, 'cancel']);
        Route::get('/store-salary/loans/{id}', [\App\Http\Controllers\HR\EmployeeLoanController::class, 'show']);
        
        // ⭐ Salary Advance Settlement (Store Mode)
        Route::post('/store-salary/advances/{id}/settle', [\App\Http\Controllers\API\RiderController::class, 'settleSalaryAdvance']);
        
        // ⭐ Get employee details with loans and advances (Store Mode)
        Route::get('/store-salary/employee/{userId}/details', [\App\Http\Controllers\API\RiderController::class, 'getEmployeeSalaryDetails']);
        
        // ⭐ Create salary advance for employee (Store Mode)
        Route::post('/requests/create-salary-advance', [\App\Http\Controllers\API\RiderController::class, 'createSalaryAdvance']);
        
        // ⭐ Get available disbursement accounts (Store Mode)
        Route::get('/store-salary/disbursement-accounts', [\App\Http\Controllers\API\RiderController::class, 'getDisbursementAccounts']);
        
        // ⭐ Salary Slip Management (Store Mode)
        Route::get('/store-salary/salary-slips', [\App\Http\Controllers\API\RiderController::class, 'getSalarySlips']);
        Route::get('/store-salary/salary-slips/calculate', [\App\Http\Controllers\API\RiderController::class, 'calculateSalary']);
        Route::post('/store-salary/salary-slips', [\App\Http\Controllers\API\RiderController::class, 'createSalarySlip']);
        Route::get('/store-salary/salary-slips/{id}', [\App\Http\Controllers\API\RiderController::class, 'getStoreSalarySlipDetails']);
        
        // ⭐ ETA to destination (Google Maps with fallback to OpenRouteService)
        Route::get('/eta-to-destination', [\App\Http\Controllers\API\RiderController::class, 'getEtaToDestination']);
        Route::get('/api-usage-stats', [\App\Http\Controllers\API\RiderController::class, 'getApiUsageStats']);
        
        // ⭐ Calculate delivery ETAs for rider's out_for_delivery orders (manual trigger)
        Route::post('/{riderId}/calculate-delivery-etas', [\App\Http\Controllers\API\RiderController::class, 'calculateDeliveryEtas']);
        
        // ⭐ LOCATION TRACKING: Heartbeat from mobile app (every 5 minutes when checked in)
        Route::post('/location-heartbeat', [\App\Http\Controllers\API\RiderController::class, 'locationHeartbeat']);
        
        // ⭐ LOCATION TRACKING: Log location failures (helps diagnose GPS gaps)
        Route::post('/location-failure', [\App\Http\Controllers\API\RiderController::class, 'logLocationFailure']);
        
        // ⭐ LOCATION TRACKING: Get active riders for map (mobile + web)
        Route::get('/active-riders-map', [\App\Http\Controllers\API\RiderController::class, 'getActiveRidersForMap']);
        Route::get('/location-heartbeat/active-riders', [\App\Http\Controllers\API\RiderController::class, 'getActiveRidersForMap']);
        
        // ⭐ LOCATION TRACKING: Get detailed map data for a specific rider (mobile + web)
        Route::get('/rider-map/{riderId}', [\App\Http\Controllers\API\RiderController::class, 'getRiderMapData']);
        Route::get('/location-heartbeat/rider-map/{riderId}', [\App\Http\Controllers\API\RiderController::class, 'getRiderMapData']);
        
        // ⭐ LOCATION TRACKING: Get rider location history (last N unique locations)
        Route::get('/rider-map/{riderId}/location-history', [\App\Http\Controllers\API\RiderController::class, 'getRiderLocationHistory']);
        
        // ⭐ LOCATION TRACKING: Get GPS trail segment between two deliveries
        Route::get('/rider-map/{riderId}/trail-segment', [\App\Http\Controllers\API\RiderController::class, 'getTrailSegment']);
        
        // ⭐ DELIVERY JOURNEY: Analyze rider journey for a delivered order
        Route::get('/delivery-journey/{orderId}', [\App\Http\Controllers\API\RiderController::class, 'getDeliveryJourney']);
        
        // ⭐ LOCATION TRACKING: Get all open orders for map view
        Route::get('/all-open-orders-map', [\App\Http\Controllers\API\RiderController::class, 'getAllOpenOrdersForMap']);
        
        // ⭐ LOCATION TRACKING: Get delivery history for a date
        Route::get('/delivery-history', [\App\Http\Controllers\API\RiderController::class, 'getDeliveryHistory']);
        
        // ⭐ HISTORY: Get riders list and individual rider history
        Route::get('/riders-for-history', [\App\Http\Controllers\API\RiderController::class, 'getRidersForHistory']);
        Route::get('/rider-history/{riderId}', [\App\Http\Controllers\API\RiderController::class, 'getRiderDeliveryHistory']);
    }); // Close rider prefix group
    
    // Store Mode - Requests (uses same controller as web for consistency, under /api prefix)
    Route::post('/requests/store', [\App\Http\Controllers\Request\RequestController::class, 'store']);
    Route::post('/requests/{id}/approve', [\App\Http\Controllers\Request\RequestApprovalController::class, 'approve']);
    Route::post('/requests/{id}/reject', [\App\Http\Controllers\Request\RequestApprovalController::class, 'reject']);
    
    // Ledger Approvals (for mobile app)
    Route::post('/ledger/{id}/approve', [\App\Http\Controllers\FIN\LedgerController::class, 'approve']);
    Route::post('/ledger/{id}/approve-l1-only', [\App\Http\Controllers\FIN\LedgerController::class, 'approveAtL1Only']);
    Route::post('/ledger/{id}/reject', [\App\Http\Controllers\FIN\LedgerController::class, 'reject']);
    Route::post('/ledger/adjustments/{id}/approve', [\App\Http\Controllers\FIN\LedgerAdjustmentController::class, 'approve']);
    Route::post('/ledger/adjustments/{id}/reject', [\App\Http\Controllers\FIN\LedgerAdjustmentController::class, 'reject']);
    
    // Vendor Management (Store Mode - uses token auth, but NOT under /rider prefix)
    Route::prefix('vendors')->group(function () {
        Route::get('/', [\App\Http\Controllers\FIN\VendorController::class, 'index']);
        Route::get('/monthly-summary', [\App\Http\Controllers\FIN\VendorController::class, 'monthlySummary']);
        Route::get('/{id}', [\App\Http\Controllers\FIN\VendorController::class, 'show']);
        Route::post('/{id}/purchase', [\App\Http\Controllers\FIN\VendorController::class, 'recordPurchase']);
        Route::post('/{id}/payment', [\App\Http\Controllers\FIN\VendorController::class, 'recordPayment']);
        Route::post('/{id}/weighted-purchase', [\App\Http\Controllers\FIN\VendorController::class, 'recordWeightedPurchase']);
        Route::post('/transaction/{id}/delete', [\App\Http\Controllers\FIN\VendorController::class, 'deleteTransaction']);
        Route::post('/transaction/{id}/update', [\App\Http\Controllers\FIN\VendorController::class, 'updateTransaction']);
        
        // Vendor Products (for weight-based vendors)
        Route::get('/{id}/products/list', [\App\Http\Controllers\FIN\VendorProductController::class, 'list']);
        Route::post('/{id}/products', [\App\Http\Controllers\FIN\VendorProductController::class, 'store']);
        Route::put('/{vendorId}/products/{productId}', [\App\Http\Controllers\FIN\VendorProductController::class, 'update']);
        Route::delete('/{vendorId}/products/{productId}', [\App\Http\Controllers\FIN\VendorProductController::class, 'destroy']);
    });

    // Ledger transaction details (used by mobile vendor view - mirrors web route)
    Route::get('/finance/ledger/transaction/{id}', [\App\Http\Controllers\FIN\LedgerController::class, 'getTransactionDetails']);
    
    // Shipping price endpoint (for mobile order creation)
    Route::get('/shipping/price', [\App\Http\Controllers\ShippingController::class, 'getPrice']);
    
    // Payment source accounts (for vendor payments in mobile)
    Route::get('/finance/accounts/payment-sources', [\App\Http\Controllers\FIN\AccountController::class, 'getPaymentSources']);
    
    // ⭐ Business Units (for dropdowns in mobile/web)
    Route::get('/business-units', [\App\Http\Controllers\FIN\BusinessUnitController::class, 'apiList']);

    // ⭐ Online Receiving Accounts (for dropdowns in approval flow)
    Route::get('/online-receiving-accounts', function () {
        $accounts = \App\Models\FIN\OnlineReceivingAccountModel::active()->ordered()
            ->get(['id', 'name', 'short_code', 'color_hex']);
        return response()->json(['success' => true, 'data' => $accounts]);
    });
    
    // ============================
    // Products (Mobile Store Mode)
    // ============================
    Route::prefix('products')->group(function () {
        Route::get('/', [\App\Http\Controllers\CRM\ProductController::class, 'index']);
        Route::get('/search', [\App\Http\Controllers\CRM\ProductController::class, 'search']);
        Route::get('/dropdown-options', [\App\Http\Controllers\CRM\ProductController::class, 'getDropdownOptions']);
        Route::get('/{id}/history', [\App\Http\Controllers\CRM\ProductController::class, 'getHistory']); // Product change history
        Route::get('/{id}', [\App\Http\Controllers\CRM\ProductController::class, 'show']);
        Route::put('/{id}', [\App\Http\Controllers\CRM\ProductController::class, 'update']);
        Route::delete('/{id}', [\App\Http\Controllers\CRM\ProductController::class, 'destroy']);
        Route::post('/', [\App\Http\Controllers\CRM\ProductController::class, 'store']);
        Route::post('/bulk-adjust-prices/preview', [\App\Http\Controllers\CRM\ProductController::class, 'previewBulkAdjustPrices']);
        Route::post('/bulk-adjust-prices', [\App\Http\Controllers\CRM\ProductController::class, 'bulkAdjustPrices']);
        Route::post('/bulk-set-weight-factor', [\App\Http\Controllers\CRM\ProductController::class, 'bulkSetWeightFactor']);
        Route::get('/attributes/list', [\App\Http\Controllers\CRM\ProductController::class, 'attributes']);
        Route::post('/attributes/apply', [\App\Http\Controllers\CRM\ProductController::class, 'applyAttributeRules']);
    });
    
    // ============================
    // Warehouse Inventory (Khaas Mode)
    // ============================
    Route::prefix('warehouse')->group(function () {
        Route::get('/inventory', [\App\Http\Controllers\CRM\WarehouseController::class, 'getInventory']);
        Route::post('/stock', [\App\Http\Controllers\CRM\WarehouseController::class, 'updateStock']);
        Route::post('/transfer', [\App\Http\Controllers\CRM\WarehouseController::class, 'initiateTransfer']);
        Route::get('/transfers/pending', [\App\Http\Controllers\CRM\WarehouseController::class, 'getPendingTransfers']);
        Route::get('/transfers/history', [\App\Http\Controllers\CRM\WarehouseController::class, 'getTransferHistory']);
        Route::post('/transfer/{id}/approve', [\App\Http\Controllers\CRM\WarehouseController::class, 'approveTransfer']);
        Route::post('/transfer/{id}/reject', [\App\Http\Controllers\CRM\WarehouseController::class, 'rejectTransfer']);
        Route::get('/product-history', [\App\Http\Controllers\CRM\WarehouseController::class, 'getProductHistory']);
        
        // ⭐ Batch Production Tracking
        Route::get('/batch/active', [\App\Http\Controllers\CRM\WarehouseController::class, 'getActiveBatches']);
        Route::post('/batch/start', [\App\Http\Controllers\CRM\WarehouseController::class, 'startBatch']);
        Route::post('/batch/{id}/end', [\App\Http\Controllers\CRM\WarehouseController::class, 'endBatch']);
        Route::post('/batch/{id}/cancel', [\App\Http\Controllers\CRM\WarehouseController::class, 'cancelBatch']);
        Route::get('/batch/product/{productId}/history', [\App\Http\Controllers\CRM\WarehouseController::class, 'getBatchHistory']);
        
        // ⭐ Inventory & Sales Report
        Route::get('/inventory-report', [\App\Http\Controllers\CRM\WarehouseController::class, 'inventoryReport']);
        
        // ⭐ Storage Feature (NF Meat → Khaas Warehouse)
        Route::get('/storage/inventory', [\App\Http\Controllers\CRM\WarehouseController::class, 'getStorageInventory']);
        Route::get('/storage/available-products', [\App\Http\Controllers\CRM\WarehouseController::class, 'getAvailableStorageProducts']);
        Route::post('/storage/config', [\App\Http\Controllers\CRM\WarehouseController::class, 'updateStorageConfig']);
        Route::post('/storage/order', [\App\Http\Controllers\CRM\WarehouseController::class, 'placeStorageOrder']);
        Route::post('/storage/order/{id}/receive', [\App\Http\Controllers\CRM\WarehouseController::class, 'receiveStorageOrder']);
        Route::post('/storage/use', [\App\Http\Controllers\CRM\WarehouseController::class, 'useStorageItem']);
        Route::post('/storage/settings', [\App\Http\Controllers\CRM\WarehouseController::class, 'updateStorageSettings']);
        
        Route::get('/products/{productId}/store-log', [\App\Http\Controllers\KhaasController::class, 'getStoreInventoryLog']);
    });

    // ============================
    // Customers (Mobile Store Mode)
    // ============================
    Route::prefix('customers')->group(function () {
        Route::get('/', [\App\Http\Controllers\CRM\CustomerController::class, 'index']);
        Route::get('/filter', [\App\Http\Controllers\CRM\CustomerController::class, 'filter']);
        Route::get('/search', [\App\Http\Controllers\CRM\CustomerController::class, 'search']);
        // ⭐ NEW: Customer creation for mobile (must be before {id} routes)
        Route::post('/', [\App\Http\Controllers\CRM\CustomerController::class, 'store']); // Create new customer
        Route::post('/check-phone', [\App\Http\Controllers\CRM\CustomerController::class, 'checkPhone']); // Check phone duplicate
        // History order details route (must be before {id} to avoid conflict)
        Route::get('/history-order/{historyOrderId}', [\App\Http\Controllers\CRM\CustomerController::class, 'historyOrderDetails']);
        Route::get('/{id}', [\App\Http\Controllers\CRM\CustomerController::class, 'show']);
        Route::get('/{id}/orders', [\App\Http\Controllers\CRM\CustomerController::class, 'orders']);
        Route::put('/{id}', [\App\Http\Controllers\CRM\CustomerController::class, 'update']);
        Route::post('/{id}/notes', [\App\Http\Controllers\CRM\CustomerController::class, 'addNote']);
        Route::post('/{id}/set-verified-location', [\App\Http\Controllers\CRM\CustomerController::class, 'setVerifiedLocation']);
        Route::post('/{id}/geocode', [\App\Http\Controllers\CRM\CustomerController::class, 'geocode']);
        Route::post('/{id}/geocode-single', [\App\Http\Controllers\CRM\CustomerController::class, 'geocodeSingle']);
    });
    
    // ============================
    // Orders (Mobile Store Mode)
    // ============================
    Route::post('/orders', [\App\Http\Controllers\CRM\OrderController::class, 'store']);
    Route::get('/orders/{id}', [\App\Http\Controllers\CRM\OrderController::class, 'show']);
    
    // ============================
    // Reports (Mobile Store Mode)
    // ============================
    Route::prefix('reports')->group(function () {
        Route::get('/monthly-summary', [\App\Http\Controllers\API\ReportsController::class, 'getMonthlySummary']);
        Route::get('/month-details', [\App\Http\Controllers\API\ReportsController::class, 'getMonthDetails']);
        Route::get('/vendor-daily', [\App\Http\Controllers\API\ReportsController::class, 'getVendorDailyReport']);
        Route::get('/expense-daily', [\App\Http\Controllers\API\ReportsController::class, 'getExpenseDailyReport']);
        Route::get('/daily-summary', [\App\Http\Controllers\API\ReportsController::class, 'getDailySummary']);
        Route::get('/daily-details', [\App\Http\Controllers\API\ReportsController::class, 'getDailyDetails']);
    });
}); // Close auth:sanctum middleware group