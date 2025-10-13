<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\SysAdmin\MenuController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\LogController;
use App\Http\Controllers\Webhook\AppSheetController;
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


// AppSheet Webhook Routes (no auth required)
Route::prefix('webhook/appsheet')->group(function () {
    Route::post('/order-converted', [AppSheetController::class, 'handleOrderConversion']);
    Route::post('/flag-update', [AppSheetController::class, 'handleFlagUpdate']);
    Route::any('/test', [AppSheetController::class, 'test']); // For testing
    Route::post('/status-update', [AppSheetController::class, 'statusUpdate']);
    Route::post('/attendance-update', [AppSheetController::class, 'attendanceUpdate']);
    Route::post('/rider-assignment', [AppSheetController::class, 'riderAssignment']);
});

Route::middleware(['auth'])->group(function () {
    Route::get('/dashboard', [DashboardController::class, 'index']);
    Route::get('/dashboard/kpis', [DashboardController::class, 'getKPIs']);
    Route::get('/dashboard/revenue-chart', [DashboardController::class, 'getRevenueChart']);
    Route::get('/dashboard/customer-growth-chart', [DashboardController::class, 'getCustomerGrowthChart']);
    Route::get('/dashboard/monthly-analytics', [DashboardController::class, 'getMonthlyAnalytics']);
    Route::get('/dashboard/daily-analytics', [DashboardController::class, 'getDailyAnalytics']);
    Route::get('/dashboard/general-stats', [DashboardController::class, 'getGeneralStats']);
    Route::post('/dashboard/clear-cache', [DashboardController::class, 'clearCache']);
    
    // Log viewer routes
    Route::get('/logs', [LogController::class, 'index']);
    Route::get('/logs/data', [LogController::class, 'getLogs']);
    Route::get('/logs/summary', [LogController::class, 'getSummary']);
    Route::get('/logs/dates', [LogController::class, 'getAvailableDates']);
    Route::get('/logs/info', [LogController::class, 'getLogInfo']);
    Route::post('/logs/clear-old', [LogController::class, 'clearOldLogs']);
    Route::get('/logs/export', [LogController::class, 'exportLogs']);
    Route::get('/logs/test', [LogController::class, 'testLogging']);
    // Operations dashboard page (imports, bulk delivery status)
    Route::get('/admin/operations', function () { return view('admin.operations'); })->name('admin.operations');
    Route::get('/orders', [OrderController::class, 'index']);
    Route::get('/orders/filter', [OrderController::class, 'filter'])->name('orders.filter');
    Route::get('/orders/open-status-counts', [OrderController::class, 'getOpenOrdersStatusCounts'])->name('orders.open-status-counts');
    Route::get('/orders/rider-counts', [OrderController::class, 'getRiderOrdersCounts'])->name('orders.rider-counts');
    Route::get('/orders/open-quantities', [OrderController::class, 'openQuantities'])->name('orders.open-quantities');
    Route::get('/orders/open-quantities/data', [OrderController::class, 'openQuantitiesData'])->name('orders.open-quantities.data');
    Route::get('/orders/{id}', [OrderController::class, 'show'])->name('orders.show');
    Route::get('/orders/{id}/invoice', [OrderController::class, 'invoice'])->name('orders.invoice');
    Route::get('/orders/{id}/edit-tab', [OrderController::class, 'editTab'])->name('orders.edit.tab');
    Route::get('/orders/{id}/invoice/pdf', [OrderController::class, 'invoicePdf'])->name('orders.invoice.pdf');
    Route::put('/orders/{id}', [OrderController::class, 'update'])->name('orders.update');
    Route::post('/orders', [OrderController::class, 'store'])->name('orders.store');

    // Rider assignment APIs
    Route::post('/orders/{id}/rider/assign', [\App\Http\Controllers\CRM\OrderRiderController::class, 'assign'])->name('orders.rider.assign');
    Route::get('/orders/{id}/rider/timeline', [\App\Http\Controllers\CRM\OrderRiderController::class, 'timeline'])->name('orders.rider.timeline');
    Route::get('/riders/active', [\App\Http\Controllers\CRM\RiderController::class, 'active'])->name('riders.active');
    Route::post('/operations/rider-import', [\App\Http\Controllers\CRM\OperationsController::class, 'importRiderAssignments'])->name('operations.rider-import');
    Route::post('/operations/attendance-import', [\App\Http\Controllers\CRM\OperationsController::class, 'importAttendance'])->name('operations.attendance-import');
    
    // Rider profile management
    Route::get('/riders', [\App\Http\Controllers\CRM\RiderProfileController::class, 'index'])->name('riders.index');
    Route::post('/riders', [\App\Http\Controllers\CRM\RiderProfileController::class, 'store'])->name('riders.store');
    Route::get('/riders/{id}', [\App\Http\Controllers\CRM\RiderProfileController::class, 'show'])->name('riders.show');
    Route::post('/riders/shift', [\App\Http\Controllers\CRM\RiderProfileController::class, 'updateShift'])->name('riders.shift');

    // Attendance (admin/manager view)
    Route::get('/attendance', [\App\Http\Controllers\CRM\AttendanceController::class, 'index'])->name('attendance.index');
    Route::get('/attendance/reports', function() { return view('pages.attendance.reports'); })->name('attendance.reports');
    Route::get('/attendance/data', [\App\Http\Controllers\CRM\AttendanceController::class, 'data'])->name('attendance.data');
    Route::post('/attendance', [\App\Http\Controllers\CRM\AttendanceController::class, 'store'])->name('attendance.store');
    Route::get('/attendance/summary', [\App\Http\Controllers\CRM\AttendanceController::class, 'summary'])->name('attendance.summary');
    Route::get('/attendance/monthly-report', [\App\Http\Controllers\CRM\AttendanceController::class, 'monthlyReport'])->name('attendance.monthly-report');
    Route::get('/attendance/employee-details', [\App\Http\Controllers\CRM\AttendanceController::class, 'employeeDetails'])->name('attendance.employee-details');
    Route::get('/attendance/users-visibility', [\App\Http\Controllers\CRM\AttendanceController::class, 'getUsersVisibility'])->name('attendance.users-visibility');
    Route::post('/attendance/update-visibility', [\App\Http\Controllers\CRM\AttendanceController::class, 'updateUserVisibility'])->name('attendance.update-visibility');

    // Shift Management
    Route::get('/shifts', [\App\Http\Controllers\Ops\ShiftController::class, 'index'])->name('shifts.index');
    Route::get('/shifts/clear-cache', [\App\Http\Controllers\Ops\ShiftController::class, 'clearCache'])->name('shifts.clear-cache');
    Route::get('/shifts/list', [\App\Http\Controllers\Ops\ShiftController::class, 'list'])->name('shifts.list');
    Route::post('/shifts', [\App\Http\Controllers\Ops\ShiftController::class, 'store'])->name('shifts.store');
    Route::put('/shifts/{id}', [\App\Http\Controllers\Ops\ShiftController::class, 'update'])->name('shifts.update');
    Route::delete('/shifts/{id}', [\App\Http\Controllers\Ops\ShiftController::class, 'destroy'])->name('shifts.destroy');
    Route::post('/shifts/{id}/set-default', [\App\Http\Controllers\Ops\ShiftController::class, 'setDefault'])->name('shifts.set-default');
    Route::get('/shifts/users-with-shifts', [\App\Http\Controllers\Ops\ShiftController::class, 'getUsersWithShifts'])->name('shifts.users-with-shifts');
    Route::post('/shifts/assign', [\App\Http\Controllers\Ops\ShiftController::class, 'assignShiftToUser'])->name('shifts.assign');
    Route::post('/shifts/bulk-assign', [\App\Http\Controllers\Ops\ShiftController::class, 'bulkAssignShift'])->name('shifts.bulk-assign');
    Route::delete('/shifts/remove-assignment', [\App\Http\Controllers\Ops\ShiftController::class, 'removeShiftAssignment'])->name('shifts.remove-assignment');

    // Holiday Management
    Route::get('/holidays', [\App\Http\Controllers\Ops\HolidayController::class, 'index'])->name('holidays.index');
    Route::get('/holidays/list', [\App\Http\Controllers\Ops\HolidayController::class, 'list'])->name('holidays.list');
    Route::post('/holidays', [\App\Http\Controllers\Ops\HolidayController::class, 'store'])->name('holidays.store');
    Route::delete('/holidays/{id}', [\App\Http\Controllers\Ops\HolidayController::class, 'destroy'])->name('holidays.destroy');
    Route::get('/holidays/upcoming', [\App\Http\Controllers\Ops\HolidayController::class, 'upcoming'])->name('holidays.upcoming');
    Route::get('/holidays/years', [\App\Http\Controllers\Ops\HolidayController::class, 'getYears'])->name('holidays.years');
    Route::get('/users/all', [\App\Http\Controllers\SysAdmin\UserController::class, 'allActive'])->name('users.all');
    Route::post('/orders/{id}/convert', [OrderController::class, 'convertOrder'])->name('orders.convert');
    Route::post('/orders/{id}/ignore', [OrderController::class, 'ignoreOrder'])->name('orders.ignore');
    Route::post('/orders/import-orders', [OrderController::class, 'importOrders'])->name('orders.importOrders');
    
    // Order Status Management Routes
    Route::prefix('order-status')->group(function () {
        Route::get('/', [\App\Http\Controllers\CRM\OrderStatusController::class, 'index'])->name('order-status.index');
        Route::get('/api/statuses', [\App\Http\Controllers\CRM\OrderStatusController::class, 'getAllStatuses'])->name('order-status.api.statuses');
        Route::get('/api/statistics', [\App\Http\Controllers\CRM\OrderStatusController::class, 'getStatistics'])->name('order-status.api.statistics');
        Route::post('/api/statuses', [\App\Http\Controllers\CRM\OrderStatusController::class, 'store'])->name('order-status.api.store');
        Route::put('/api/statuses/{id}', [\App\Http\Controllers\CRM\OrderStatusController::class, 'update'])->name('order-status.api.update');
        Route::delete('/api/statuses/{id}', [\App\Http\Controllers\CRM\OrderStatusController::class, 'destroy'])->name('order-status.api.destroy');
        Route::post('/api/change-status', [\App\Http\Controllers\CRM\OrderStatusController::class, 'changeOrderStatus'])->name('order-status.api.change');
        Route::post('/api/bulk-change', [\App\Http\Controllers\CRM\OrderStatusController::class, 'bulkChangeStatus'])->name('order-status.api.bulk-change');
        Route::get('/api/orders/{id}/history', [\App\Http\Controllers\CRM\OrderStatusController::class, 'getOrderHistory'])->name('order-status.api.history');
        Route::put('/api/history/{historyId}/update-timestamp', [\App\Http\Controllers\CRM\OrderStatusController::class, 'updateHistoryTimestamp'])->name('order-status.api.update-timestamp');
        Route::get('/api/orders/{id}/timeline', [\App\Http\Controllers\CRM\OrderStatusController::class, 'getOrderTimeline'])->name('order-status.api.timeline');
        Route::get('/api/statuses/{id}/transitions', [\App\Http\Controllers\CRM\OrderStatusController::class, 'getAvailableTransitions'])->name('order-status.api.transitions');
        Route::put('/api/statuses/{id}/transitions', [\App\Http\Controllers\CRM\OrderStatusController::class, 'updateTransitions'])->name('order-status.api.update-transitions');
        Route::post('/api/reorder', [\App\Http\Controllers\CRM\OrderStatusController::class, 'reorderStatuses'])->name('order-status.api.reorder');
        Route::get('/api/orders', [\App\Http\Controllers\CRM\OrderStatusController::class, 'getOrdersByStatus'])->name('order-status.api.orders');
        Route::get('/history', [\App\Http\Controllers\CRM\OrderStatusController::class, 'historyIndex'])->name('order-status.history.index');
        Route::get('/history/{orderId}', [\App\Http\Controllers\CRM\OrderStatusController::class, 'orderHistory'])->name('order-status.history.order');
    });

    // Bulk Status Update Routes (Admin only)
    Route::prefix('admin')->group(function () {
        Route::get('/bulk-status-update', [\App\Http\Controllers\CRM\BulkStatusUpdateController::class, 'showUploadForm'])->name('admin.bulk-status-update');
        Route::post('/bulk-status-update', [\App\Http\Controllers\CRM\BulkStatusUpdateController::class, 'processUpload'])->name('admin.bulk-status-update.process');
    });
    
    // API endpoints for products
    Route::get('/api/products/search', [\App\Http\Controllers\CRM\ProductController::class, 'search'])->name('products.search');
    
    // API endpoints for customers
    Route::get('/api/customers/search', [\App\Http\Controllers\CRM\CustomerController::class, 'search'])->name('customers.search');
    
    // Customer Management Routes
    Route::prefix('customers')->group(function () {
        Route::get('/', [\App\Http\Controllers\CRM\CustomerController::class, 'index'])->name('customers.index');
        Route::get('/search', [\App\Http\Controllers\CRM\CustomerController::class, 'search'])->name('customers.search.alt');
        Route::get('/filter', [\App\Http\Controllers\CRM\CustomerController::class, 'filter'])->name('customers.filter');
        Route::get('/{id}', [\App\Http\Controllers\CRM\CustomerController::class, 'show'])->name('customers.show');
        Route::get('/{id}/orders', [\App\Http\Controllers\CRM\CustomerController::class, 'orders'])->name('customers.orders');
        Route::put('/{id}', [\App\Http\Controllers\CRM\CustomerController::class, 'update'])->name('customers.update');
        Route::post('/{id}/notes', [\App\Http\Controllers\CRM\CustomerController::class, 'addNote'])->name('customers.addNote');
        Route::delete('/{id}', [\App\Http\Controllers\CRM\CustomerController::class, 'destroy'])->name('customers.destroy');
    });
    
    // Product Management Routes
    Route::get('/products', [\App\Http\Controllers\CRM\ProductController::class, 'index'])->name('products.index');
    Route::get('/products/search', [\App\Http\Controllers\CRM\ProductController::class, 'search'])->name('products.search.alt');
    Route::post('/products/bulk-adjust-prices', [\App\Http\Controllers\CRM\ProductController::class, 'bulkAdjustPrices'])->name('products.bulk_adjust_prices');
    // Attribute management
    Route::get('/products/attributes', [\App\Http\Controllers\CRM\ProductController::class, 'attributes'])->name('products.attributes');
    Route::post('/products/attributes/labels', [\App\Http\Controllers\CRM\ProductController::class, 'saveAttributeLabels'])->name('products.attributes.labels');
    Route::post('/products/attributes/groups', [\App\Http\Controllers\CRM\ProductController::class, 'createAttributeGroup'])->name('products.attributes.groups.create');
    Route::post('/products/attributes/groups/add-products', [\App\Http\Controllers\CRM\ProductController::class, 'addProductsToGroup'])->name('products.attributes.groups.add_products');
    Route::post('/products/attributes/apply', [\App\Http\Controllers\CRM\ProductController::class, 'applyAttributeRules'])->name('products.attributes.apply');
    Route::post('/products/attributes/preview', [\App\Http\Controllers\CRM\ProductController::class, 'previewAttributeRules'])->name('products.attributes.preview');
    Route::get('/products/lookup', [\App\Http\Controllers\CRM\ProductController::class, 'lookup'])->name('products.lookup');
    Route::post('/products/attributes/preview-auto', [\App\Http\Controllers\CRM\ProductController::class, 'previewAutoRules'])->name('products.attributes.preview_auto');
    Route::post('/products/attributes/save-rules', [\App\Http\Controllers\CRM\ProductController::class, 'saveAutoRules'])->name('products.attributes.save_rules');
    Route::post('/products/attributes/coverage', [\App\Http\Controllers\CRM\ProductController::class, 'getCoverageSummary'])->name('products.attributes.coverage');
    Route::post('/products/attributes/apply-saved', [\App\Http\Controllers\CRM\ProductController::class, 'applySavedRules'])->name('products.attributes.apply_saved');
    Route::get('/products/create', [\App\Http\Controllers\CRM\ProductController::class, 'create'])->name('products.create');
    Route::post('/products', [\App\Http\Controllers\CRM\ProductController::class, 'store'])->name('products.store');
    Route::get('/products/{id}', [\App\Http\Controllers\CRM\ProductController::class, 'show'])->name('products.show');
    Route::get('/products/{id}/edit', [\App\Http\Controllers\CRM\ProductController::class, 'edit'])->name('products.edit');
    Route::put('/products/{id}', [\App\Http\Controllers\CRM\ProductController::class, 'update'])->name('products.update');
    Route::post('/products/import', [\App\Http\Controllers\CRM\ProductController::class, 'importProducts'])->name('products.import');
    Route::post('/products/import-all', [\App\Http\Controllers\CRM\ProductController::class, 'importAllProducts'])->name('products.import-all');
    Route::post('/products/{id}/sync', [\App\Http\Controllers\CRM\ProductController::class, 'syncProduct'])->name('products.sync');
    
    // Shipping Configuration Routes
    Route::get('/shipping', [\App\Http\Controllers\ShippingController::class, 'index'])->name('shipping.index');
    Route::post('/shipping/update', [\App\Http\Controllers\ShippingController::class, 'update'])->name('shipping.update');
    Route::get('/api/shipping/price', [\App\Http\Controllers\ShippingController::class, 'getPrice'])->name('shipping.price');
    
    // Coupon Management Routes
    Route::prefix('coupons')->group(function () {
        Route::get('/', [\App\Http\Controllers\CRM\CouponController::class, 'index'])->name('coupons.index');
        Route::get('/create', [\App\Http\Controllers\CRM\CouponController::class, 'create'])->name('coupons.create');
        Route::post('/', [\App\Http\Controllers\CRM\CouponController::class, 'store'])->name('coupons.store');
        Route::get('/search', [\App\Http\Controllers\CRM\CouponController::class, 'search'])->name('coupons.search');
        Route::get('/active/list', [\App\Http\Controllers\CRM\CouponController::class, 'getActiveCoupons'])->name('coupons.active');
        Route::post('/validate', [\App\Http\Controllers\CRM\CouponController::class, 'validateCoupon'])->name('coupons.validate');
        Route::post('/import', [\App\Http\Controllers\CRM\CouponController::class, 'importCoupons'])->name('coupons.import');
        Route::post('/import-all', [\App\Http\Controllers\CRM\CouponController::class, 'importAllCoupons'])->name('coupons.import-all');
        Route::get('/{id}', [\App\Http\Controllers\CRM\CouponController::class, 'show'])->name('coupons.show');
        Route::get('/{id}/edit', [\App\Http\Controllers\CRM\CouponController::class, 'edit'])->name('coupons.edit');
        Route::put('/{id}', [\App\Http\Controllers\CRM\CouponController::class, 'update'])->name('coupons.update');
        Route::delete('/{id}', [\App\Http\Controllers\CRM\CouponController::class, 'destroy'])->name('coupons.destroy');
    });
    
    // User Management Routes
    Route::prefix('users')->group(function () {
        Route::get('/', [\App\Http\Controllers\SysAdmin\UserController::class, 'index'])->name('users.index');
        Route::post('/bulk', [\App\Http\Controllers\SysAdmin\UserController::class, 'bulkStore'])->name('users.bulk');
        Route::post('/{id}/create-cash-account', [\App\Http\Controllers\SysAdmin\UserController::class, 'createCashAccount'])->name('users.create-cash-account');
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
        
        // Permission management
        Route::get('/{id}/permissions', [\App\Http\Controllers\SysAdmin\RolePermissionController::class, 'manage'])->name('roles.permissions.manage');
        Route::put('/{id}/permissions', [\App\Http\Controllers\SysAdmin\RolePermissionController::class, 'update'])->name('roles.permissions.update');
        Route::post('/{id}/permissions/defaults', [\App\Http\Controllers\SysAdmin\RolePermissionController::class, 'setDefaults'])->name('roles.permissions.defaults');
    });
    
    // Request Management Routes
    Route::prefix('requests')->group(function () {
        // Specific routes MUST come before wildcard routes
        Route::get('/', [\App\Http\Controllers\Request\RequestController::class, 'index'])->name('requests.index');
        Route::get('/data', [\App\Http\Controllers\Request\RequestController::class, 'data'])->name('requests.data');
        Route::get('/create', [\App\Http\Controllers\Request\RequestController::class, 'create'])->name('requests.create');
        Route::get('/approval/statistics', [\App\Http\Controllers\Request\RequestApprovalController::class, 'statistics'])->name('requests.approval.statistics');
        
        // Settings routes (specific routes before {id})
        Route::get('/settings', [\App\Http\Controllers\Request\RequestSettingsController::class, 'index'])->name('requests.settings.index');
        Route::put('/settings/categories/{id}/config', [\App\Http\Controllers\Request\RequestSettingsController::class, 'updateCategoryConfig'])->name('requests.settings.category.config');
        Route::post('/settings/roles/assign-level', [\App\Http\Controllers\Request\RequestSettingsController::class, 'assignRoleToLevel'])->name('requests.settings.roles.assign');
        Route::delete('/settings/roles/level/{id}', [\App\Http\Controllers\Request\RequestSettingsController::class, 'removeRoleFromLevel'])->name('requests.settings.roles.remove');
        Route::get('/settings/users/level/{level}', [\App\Http\Controllers\Request\RequestSettingsController::class, 'getUsersWithLevel'])->name('requests.settings.users.level');
        Route::put('/settings/categories/{id}', [\App\Http\Controllers\Request\RequestSettingsController::class, 'updateCategory'])->name('requests.settings.category.update');
        Route::post('/settings/categories', [\App\Http\Controllers\Request\RequestSettingsController::class, 'createCategory'])->name('requests.settings.category.create');
        
        // POST routes
        Route::post('/', [\App\Http\Controllers\Request\RequestController::class, 'store'])->name('requests.store');
        Route::post('/{id}/cancel', [\App\Http\Controllers\Request\RequestController::class, 'cancel'])->name('requests.cancel');
        Route::post('/{id}/approve', [\App\Http\Controllers\Request\RequestApprovalController::class, 'approve'])->name('requests.approve');
        Route::post('/{id}/reject', [\App\Http\Controllers\Request\RequestApprovalController::class, 'reject'])->name('requests.reject');
        
        // Wildcard routes MUST come last
        Route::get('/{id}', [\App\Http\Controllers\Request\RequestController::class, 'show'])->name('requests.show');
        Route::put('/{id}', [\App\Http\Controllers\Request\RequestController::class, 'update'])->name('requests.update');
    });

    // Unified Approvals Dashboard
    Route::get('/approvals', [\App\Http\Controllers\ApprovalController::class, 'index'])->name('approvals.index');
    
    // Finance & Ledger Routes
    Route::prefix('finance')->name('fin.')->group(function () {
        
        // Action Items Routes
        Route::prefix('action-items')->name('action-items.')->group(function () {
            Route::get('/', [\App\Http\Controllers\FIN\ActionItemController::class, 'index'])->name('index');
            Route::get('/{id}', [\App\Http\Controllers\FIN\ActionItemController::class, 'show'])->name('show');
            Route::post('/{id}/resolve', [\App\Http\Controllers\FIN\ActionItemController::class, 'resolve'])->name('resolve');
            Route::post('/{id}/dismiss', [\App\Http\Controllers\FIN\ActionItemController::class, 'dismiss'])->name('dismiss');
            Route::post('/{id}/retry', [\App\Http\Controllers\FIN\ActionItemController::class, 'retryPosting'])->name('retry');
            Route::post('/toggle-posting', [\App\Http\Controllers\FIN\ActionItemController::class, 'toggleLedgerPosting'])->name('toggle-posting');
        });
        
        // Account Management Routes
        Route::prefix('accounts')->name('accounts.')->group(function () {
            Route::get('/', [\App\Http\Controllers\FIN\AccountController::class, 'index'])->name('index');
            Route::get('/create', [\App\Http\Controllers\FIN\AccountController::class, 'create'])->name('create');
            Route::post('/', [\App\Http\Controllers\FIN\AccountController::class, 'store'])->name('store');
            Route::get('/{id}', [\App\Http\Controllers\FIN\AccountController::class, 'show'])->name('show');
            Route::get('/{id}/edit', [\App\Http\Controllers\FIN\AccountController::class, 'edit'])->name('edit');
            Route::put('/{id}', [\App\Http\Controllers\FIN\AccountController::class, 'update'])->name('update');
            Route::post('/{id}/toggle-status', [\App\Http\Controllers\FIN\AccountController::class, 'toggleStatus'])->name('toggle-status');
            Route::post('/{id}/adjust-balance', [\App\Http\Controllers\FIN\AccountController::class, 'adjustBalance'])->name('adjust-balance');
        });
        
        // Import Routes
        Route::prefix('import')->name('import.')->group(function () {
            Route::get('/', [\App\Http\Controllers\FIN\ImportController::class, 'index'])->name('index');
            Route::get('/create', [\App\Http\Controllers\FIN\ImportController::class, 'create'])->name('create');
            Route::post('/legacy', [\App\Http\Controllers\FIN\ImportController::class, 'importLegacy'])->name('legacy');
            Route::post('/clear-legacy', [\App\Http\Controllers\FIN\ImportController::class, 'clearLegacyData'])->name('clear-legacy');
            Route::delete('/{id}', [\App\Http\Controllers\FIN\ImportController::class, 'deleteImport'])->name('delete');
            Route::get('/template', [\App\Http\Controllers\FIN\ImportController::class, 'downloadTemplate'])->name('template');
            Route::get('/{id}', [\App\Http\Controllers\FIN\ImportController::class, 'show'])->name('show');
        });

        // Expense Category Routes
        Route::prefix('expense-category')->name('expense-category.')->group(function () {
            Route::post('/', [\App\Http\Controllers\FIN\ExpenseCategoryController::class, 'store'])->name('store');
        });

        // Vendor Routes
        Route::prefix('vendors')->name('vendors.')->group(function () {
            Route::get('/', [\App\Http\Controllers\FIN\VendorController::class, 'index'])->name('index');
            Route::get('/create', [\App\Http\Controllers\FIN\VendorController::class, 'create'])->name('create');
            Route::post('/', [\App\Http\Controllers\FIN\VendorController::class, 'store'])->name('store');
            Route::get('/{id}', [\App\Http\Controllers\FIN\VendorController::class, 'show'])->name('show');
            Route::get('/{id}/edit', [\App\Http\Controllers\FIN\VendorController::class, 'edit'])->name('edit');
            Route::put('/{id}', [\App\Http\Controllers\FIN\VendorController::class, 'update'])->name('update');
            Route::post('/{id}/toggle-status', [\App\Http\Controllers\FIN\VendorController::class, 'toggleStatus'])->name('toggle-status');
            Route::post('/{id}/purchase', [\App\Http\Controllers\FIN\VendorController::class, 'recordPurchase'])->name('purchase');
            Route::post('/{id}/payment', [\App\Http\Controllers\FIN\VendorController::class, 'recordPayment'])->name('payment');
        });

        // Employee Cash Routes
        Route::prefix('employee')->name('employee.')->group(function () {
            Route::get('/', [\App\Http\Controllers\FIN\EmployeeCashController::class, 'index'])->name('index');
            Route::get('/dashboard', [\App\Http\Controllers\FIN\EmployeeCashController::class, 'dashboard'])->name('dashboard');
            Route::get('/{id}', [\App\Http\Controllers\FIN\EmployeeCashController::class, 'show'])->name('show');
            Route::post('/{id}/deposit', [\App\Http\Controllers\FIN\EmployeeCashController::class, 'recordDeposit'])->name('deposit');
            Route::post('/{id}/adjustment', [\App\Http\Controllers\FIN\EmployeeCashController::class, 'recordAdjustment'])->name('adjustment');
            Route::post('/{id}/expense-request', [\App\Http\Controllers\FIN\EmployeeCashController::class, 'createExpenseRequest'])->name('expense-request');
            
            // Company Account Transaction Routes
            Route::post('/{id}/company-receive', [\App\Http\Controllers\FIN\EmployeeCashController::class, 'recordCompanyReceipt'])->name('company-receive');
            Route::post('/{id}/company-payment', [\App\Http\Controllers\FIN\EmployeeCashController::class, 'recordCompanyPayment'])->name('company-payment');
            Route::post('/{id}/company-transfer', [\App\Http\Controllers\FIN\EmployeeCashController::class, 'recordCompanyTransfer'])->name('company-transfer');
        });

        // Ledger Routes (Overall Ledger & Transfers)
        Route::prefix('ledger')->name('ledger.')->group(function () {
            Route::get('/', [\App\Http\Controllers\FIN\LedgerController::class, 'index'])->name('index');
            Route::get('/transfer', [\App\Http\Controllers\FIN\LedgerController::class, 'createTransfer'])->name('transfer');
            Route::post('/transfer', [\App\Http\Controllers\FIN\LedgerController::class, 'storeTransfer'])->name('transfer.store');
            Route::get('/{id}', [\App\Http\Controllers\FIN\LedgerController::class, 'show'])->name('show');
            Route::post('/{id}/approve', [\App\Http\Controllers\FIN\LedgerController::class, 'approve'])->name('approve');
            Route::post('/{id}/reject', [\App\Http\Controllers\FIN\LedgerController::class, 'reject'])->name('reject');
        });
        
        // Expense Management & Settlement Routes
        Route::prefix('expenses')->name('expenses.')->group(function () {
            Route::get('/', [\App\Http\Controllers\FIN\ExpenseManagementController::class, 'index'])->name('index');
            Route::post('/{id}/settle', [\App\Http\Controllers\FIN\ExpenseManagementController::class, 'settle'])->name('settle');
            Route::post('/bulk-settle', [\App\Http\Controllers\FIN\ExpenseManagementController::class, 'bulkSettle'])->name('bulk-settle');
            Route::get('/{id}/settlement-details', [\App\Http\Controllers\FIN\ExpenseManagementController::class, 'getSettlementDetails'])->name('settlement-details');
        });
    });
});

// User attendance - accessible by anyone with view_attendance permission
Route::middleware(['auth'])->group(function () {
    Route::get('/attendance/mine', [\App\Http\Controllers\CRM\AttendanceController::class, 'mine'])->name('attendance.mine');
    Route::get('/attendance/mine/data', [\App\Http\Controllers\CRM\AttendanceController::class, 'mineData'])->name('attendance.mine.data');
});
