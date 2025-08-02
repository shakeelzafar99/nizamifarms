<?php


use App\Http\Controllers\AuthController;
use App\Http\Controllers\CRM\AccountCustomerController;
use App\Http\Controllers\CRM\BranchController;
use App\Http\Controllers\CRM\CompanyConfigController;
use App\Http\Controllers\CRM\CompanyController;
use App\Http\Controllers\CRM\OrderController;
use App\Http\Controllers\CRM\WalkInCustomerController;
use App\Http\Controllers\FIN\ApInvoiceController;
use App\Http\Controllers\FIN\ApPaymentController;
use App\Http\Controllers\FIN\ArInvoiceController;
use App\Http\Controllers\FIN\ArPaymentController;
use App\Http\Controllers\FIN\GlController;
use App\Http\Controllers\FIN\GlTypeController;
use App\Http\Controllers\FIN\Sys\SysArInvoiceController;
use App\Http\Controllers\FIN\Sys\SysArPaymentController;
use App\Http\Controllers\FIN\Sys\SysReportsController;
use App\Http\Controllers\HR\DepartmentController;
use App\Http\Controllers\HR\DesignationController;
use App\Http\Controllers\HR\InstituteController;
use App\Http\Controllers\HR\QualificationController;
use App\Http\Controllers\HR\ScaleController;
use App\Http\Controllers\MailController;
use App\Http\Controllers\PDM\BrandController;
use App\Http\Controllers\PDM\PartController;
use App\Http\Controllers\PDM\ProductController;
use App\Http\Controllers\PDM\ProductTreadPatternsController;
use App\Http\Controllers\PDM\ServiceController;
use App\Http\Controllers\PDM\SizeController;
use App\Http\Controllers\SCM\PurchaseController;
use App\Http\Controllers\SCM\PurchaseDetailController;
use App\Http\Controllers\SCM\ReceiveController;
use App\Http\Controllers\SCM\StockController;
use App\Http\Controllers\SCM\SupplierController;
use App\Http\Controllers\SysAdmin\ConfigController;
use App\Http\Controllers\SysAdmin\EmailTemplateController;
use App\Http\Controllers\SysAdmin\EnquiryController;
use App\Http\Controllers\SysAdmin\LovController;
use App\Http\Controllers\SysAdmin\MenuController;
use App\Http\Controllers\SysAdmin\PackageController;
use App\Http\Controllers\SysAdmin\PaymentMethodController;
use App\Http\Controllers\SysAdmin\RoleController;
use App\Http\Controllers\SysAdmin\UserController;
use App\Http\Controllers\Webhook\ShopifyController;
use Illuminate\Http\Request;
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


Route::group([
    'prefix' => 'webhook'
], function ($router) {
    Route::get('/shopify', function () {
        $exitCode0 = Artisan::call('config:clear');
        $exitCode1 = Artisan::call('cache:clear');
        $exitCode2 = Artisan::call('view:clear');
        $exitCode3 = Artisan::call('route:clear');
        $exitCode4 = Artisan::call('config:cache');
    });
});

 

//Webhook
Route::prefix('webhook')->group(function () {
    
    // Shopify Routes
    Route::prefix('shopify')->group(function () {
        // Order routes
        Route::prefix('order')->controller(ShopifyController::class)->group(function () {
            Route::get('get/{id}', 'get'); 
            Route::post('list', 'list');
            Route::post('store', 'store');
            Route::delete('remove/{id}', 'remove');
        });

        
    });

     
});
//Webhook

//Route::get('sendbasicemail', 'MailController@sendEmail');


Route::group([
    'prefix' => 'auth'
], function ($router) {
    Route::post('/authenticate', [AuthController::class, 'authenticate']);
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

Route::group([
    'middleware' => ['auth:sanctum'],
    'prefix' => 'sysadmin'
], function ($router) {
    Route::prefix('menu')->group(function () {
        Route::get('navtree', [MenuController::class, 'navtree']);
        //Route::get('urlauth/{url}', [MenuController::class, 'urlauth'])->where('url', '.*'); 
        Route::post('permission', [MenuController::class, 'permission']);
        Route::get('tree/{id}', [MenuController::class, 'tree']);
        Route::get('get/{id}', [MenuController::class, 'get']);
        Route::post('list', [MenuController::class, 'list']);
        Route::post('store', [MenuController::class, 'store']);
        Route::delete('remove/{id}', [MenuController::class, 'remove']);
        Route::post('action-button', [MenuController::class, 'list']);
    });

    Route::prefix('role')->group(function () {
        Route::get('get/{id}', [RoleController::class, 'get']);
        Route::post('list', [RoleController::class, 'list']);
        Route::post('store', [RoleController::class, 'store']);
        Route::delete('remove/{id}', [RoleController::class, 'remove']);
    });

    Route::prefix('package')->group(function () {
        Route::get('get/{id}', [PackageController::class, 'get']);
        Route::get('list/{status}', [PackageController::class, 'listByStatus']);
        Route::post('list', [PackageController::class, 'list']);
        Route::post('store', [PackageController::class, 'store']);
        Route::delete('remove/{id}', [PackageController::class, 'remove']);
    });

    Route::prefix('lov')->group(function () {
        Route::get('list/{group}', [LovController::class, 'list']);
    });

    Route::prefix('user')->group(function () {
        Route::get('get/{id}', [UserController::class, 'get']);
        Route::post('list', [UserController::class, 'list']);
        Route::post('store', [UserController::class, 'store']);
        Route::delete('remove/{id}', [UserController::class, 'remove']);
        Route::post('change-password', [UserController::class, 'change_password']);
    });

    Route::prefix('payment-method')->group(function () {
        Route::get('list/{status}', [PaymentMethodController::class, 'listByStatus']);
        Route::get('get/{id}', [PaymentMethodController::class, 'get']);
        Route::post('filter', [PaymentMethodController::class, 'filter']);
        Route::post('list', [PaymentMethodController::class, 'list']);
        Route::post('store', [PaymentMethodController::class, 'store']);
        Route::delete('remove/{id}', [PaymentMethodController::class, 'remove']);
    });

    Route::prefix('emailtemplate')->group(function () {
        Route::get('get/{id}', [EmailTemplateController::class, 'get']);
        Route::post('list', [EmailTemplateController::class, 'list']);
        Route::post('store', [EmailTemplateController::class, 'store']);
        Route::delete('remove/{id}', [EmailTemplateController::class, 'remove']);
    });

    Route::prefix('config')->group(function () {
        Route::get('get/{id}', [ConfigController::class, 'get']);
        Route::post('list', [ConfigController::class, 'list']);
        Route::post('store', [ConfigController::class, 'store']);
        Route::delete('remove/{id}', [ConfigController::class, 'remove']);
    });

    Route::prefix('enquiry')->group(function () {
        Route::post('list', [EnquiryController::class, 'list']);
        Route::delete('remove/{id}', [EnquiryController::class, 'remove']);
        Route::get('get/{id}', [EnquiryController::class, 'get']);
    });
});




Route::group([
    'middleware' => ['auth:sanctum'],
    'prefix' => 'pdm'
], function ($router) {
    Route::prefix('size')->group(function () {
        Route::get('get/{id}', [SizeController::class, 'get']);
        Route::get('list/{status}', [SizeController::class, 'listByStatus']);  // list by status
        Route::post('list', [SizeController::class, 'list']);
        Route::post('store', [SizeController::class, 'store']);
        Route::delete('remove/{id}', [SizeController::class, 'remove']);
        Route::get('autocomplete/{value}', [SizeController::class, 'autocomplete']);
    });

    Route::prefix('product')->group(function () {
        Route::get('get/{id}', [ProductController::class, 'get']);
        Route::get('get-brand-size/{brand_id}/{size_id}', [ProductController::class, 'getBrandSize']);
        Route::post('list', [ProductController::class, 'list']);
        Route::get('autocomplete/{company_id}/{branch_id}/{value}/{value1}/{value2}', [ProductController::class, 'autocomplete']);
        Route::post('store', [ProductController::class, 'store']);
        Route::delete('remove/{id}', [ProductController::class, 'remove']);
    });

    Route::prefix('product-tread-pattern')->group(function () {
        Route::get('get/{id}', [ProductTreadPatternsController::class, 'get']);
        Route::post('list', [ProductTreadPatternsController::class, 'list']);
        Route::get('autocomplete/{company_id}/{branch_id}/{value}/{value1}/{value2}', [ProductTreadPatternsController::class, 'autocomplete']);
        Route::post('store', [ProductTreadPatternsController::class, 'store']);
        Route::delete('remove/{id}', [ProductTreadPatternsController::class, 'remove']);
    });

    Route::prefix('brand')->group(function () {
        Route::get('list/{status}', [BrandController::class, 'listByStatus']);  // list by status
        Route::post('list', [BrandController::class, 'list']);
        Route::post('store', [BrandController::class, 'store']);
        Route::delete('remove/{id}', [BrandController::class, 'remove']);
        Route::get('get/{id}', [BrandController::class, 'get']);
    });

    Route::prefix('part')->group(function () {
        Route::get('get/{id}', [PartController::class, 'get']);
        Route::post('list', [PartController::class, 'list']);
        Route::get('autocomplete/{company_id}/{branch_id}/{value}/{value1}/{value2}', [PartController::class, 'autocomplete']);
        Route::post('store', [PartController::class, 'store']);
        Route::delete('remove/{id}', [PartController::class, 'remove']);
    });

    Route::prefix('service')->group(function () {
        Route::get('get/{id}', [ServiceController::class, 'get']);
        Route::post('list', [ServiceController::class, 'list']);
        Route::get('autocomplete/{company_id}/{branch_id}/{value}/{value1}/{value2}', [ServiceController::class, 'autocomplete']);
        Route::post('store', [ServiceController::class, 'store']);
        Route::delete('remove/{id}', [ServiceController::class, 'remove']);
    });
});




Route::group([
    'middleware' => ['auth:sanctum'],
    'prefix' => 'crm'
], function ($router) {
    Route::prefix('customer/account')->group(function () {
        Route::get('get/{id}', [AccountCustomerController::class, 'get']);
        Route::post('list', [AccountCustomerController::class, 'list']);
        Route::post('store', [AccountCustomerController::class, 'store']);
        Route::delete('remove/{id}', [AccountCustomerController::class, 'remove']);
        Route::get('autocomplete/{company_id}/{branch_id}/{value}/{value1}/{value2}', [AccountCustomerController::class, 'autocomplete']);
    });

    Route::prefix('customer/walk-in')->group(function () {
        Route::get('get/{id}', [WalkInCustomerController::class, 'get']);
        Route::get('get-by-reg-no/{reg_no}', [WalkInCustomerController::class, 'getByRegNo']);
        Route::post('list', [WalkInCustomerController::class, 'list']);
        Route::post('store', [WalkInCustomerController::class, 'store']);
        Route::delete('remove/{id}', [WalkInCustomerController::class, 'remove']);
        Route::get('autocomplete/{company_id}/{branch_id}/{value}/{value1}/{value2}', [WalkInCustomerController::class, 'autocomplete']);
    });

    Route::prefix('company')->group(function () {
        Route::get('get/{id}', [CompanyController::class, 'get']);
        Route::get('list/{status}', [CompanyController::class, 'listByStatus']);
        Route::post('list', [CompanyController::class, 'list']);
        Route::post('store', [CompanyController::class, 'store']);
        Route::delete('remove/{id}', [CompanyController::class, 'remove']);
    });

    Route::prefix('branch')->group(function () {
        Route::get('get/{id}', [BranchController::class, 'get']);
        Route::get('list/{status}', [BranchController::class, 'listByStatus']);  // list by status
        Route::post('list', [BranchController::class, 'list']);
        Route::post('filter', [BranchController::class, 'filter']);
        Route::post('store', [BranchController::class, 'store']);
        Route::delete('remove/{id}', [BranchController::class, 'remove']);
    });

    Route::prefix('order')->group(function () {
        Route::get('get/{id}', [OrderController::class, 'get']);
        Route::get('details/get/{id}', [OrderController::class, 'getdetail']);  // Order detail
        Route::post('list', [OrderController::class, 'list']);
        Route::post('store', [OrderController::class, 'store']);
        Route::delete('remove/{id}', [OrderController::class, 'remove']);
    });

    Route::prefix('companyconfig')->group(function () {
        Route::get('get/{id}', [CompanyConfigController::class, 'get']);
        Route::post('list', [CompanyConfigController::class, 'list']);
        Route::post('store', [CompanyConfigController::class, 'store']);
        Route::delete('remove/{id}', [CompanyConfigController::class, 'remove']);
    });
});


Route::group([
    'middleware' => ['auth:sanctum'],
    'prefix' => 'scm'
], function ($router) {
    Route::prefix('purchase')->group(function () {
        Route::post('store', [PurchaseController::class, 'store']);
        Route::get('get/{id}', [PurchaseController::class, 'get']);
        Route::get('details/get/{id}', [PurchaseController::class, 'getdetail']);
        Route::post('list', [PurchaseController::class, 'list']);
        Route::delete('remove/{id}', [PurchaseController::class, 'remove']);
    });

    Route::prefix('purchaseitem')->group(function () {
        Route::post('list', [PurchaseDetailController::class, 'list']);
        Route::post('store', [PurchaseDetailController::class, 'store']);
        Route::delete('remove/{id}', [PurchaseDetailController::class, 'remove']);
    });

    Route::prefix('receive')->group(function () {
        Route::get('get/{id}', [ReceiveController::class, 'get']);
        Route::get('details/get/{id}', [ReceiveController::class, 'getdetail']); // Receive detail
        Route::post('list', [ReceiveController::class, 'list']);
        Route::post('store', [ReceiveController::class, 'store']);
        Route::delete('remove/{id}', [ReceiveController::class, 'remove']);
    });

    Route::prefix('stock')->group(function () {
        Route::post('product/list', [StockController::class, 'productList']);
        Route::post('part/list', [StockController::class, 'partList']);
        Route::get('get/{id}', [StockController::class, 'get']);
        Route::post('store', [StockController::class, 'store']);
    });

    Route::prefix('supplier')->group(function () {
        Route::get('get/{id}', [SupplierController::class, 'get']);
        Route::post('list', [SupplierController::class, 'list']);
        Route::get('list/{status}', [SupplierController::class, 'listByStatus']);
        Route::post('store', [SupplierController::class, 'store']);
        Route::delete('remove/{id}', [SupplierController::class, 'remove']);
    });
});



Route::group([
    'middleware' => ['auth:sanctum'],
    'prefix' => 'fin'
], function ($router) {
    Route::prefix('gl')->group(function () {
        Route::get('get/{id}', [GlController::class, 'get']);
        Route::post('list', [GlController::class, 'list']);
        Route::post('store', [GlController::class, 'store']);
        Route::delete('remove/{id}', [GlController::class, 'remove']);
    });

    Route::prefix('glt')->group(function () {
        Route::get('get/{id}', [GlTypeController::class, 'get']);
        Route::post('list', [GlTypeController::class, 'list']);
        Route::post('store', [GlTypeController::class, 'store']);
        Route::delete('remove', [GlTypeController::class, 'remove']);
    });

    Route::prefix('invoice/payable')->group(function () {
        Route::post('list', [ApInvoiceController::class, 'list']);
        Route::get('get/{id}', [ApInvoiceController::class, 'get']);
        Route::get('details/get/{id}', [ApInvoiceController::class, 'getdetail']);
        Route::get('outstanding/list/{company_id}/{branch_id}/{supplier_id}', [ApInvoiceController::class, 'getOutstanding']);
    });

    Route::prefix('invoice/receivable')->group(function () {
        Route::post('list', [ArInvoiceController::class, 'list']);
        Route::get('get/{id}', [ArInvoiceController::class, 'get']);
        Route::get('details/get/{id}', [ArInvoiceController::class, 'getdetail']);
        Route::get('outstanding/list/{company_id}/{branch_id}/{cust_id}', [ArInvoiceController::class, 'getOutstanding']);
    });

    Route::prefix('payment/payable')->group(function () {
        Route::post('list', [ApPaymentController::class, 'list']);
        Route::get('get/{id}', [ApPaymentController::class, 'get']);
        Route::get('details/get/{id}', [ApPaymentController::class, 'getdetail']);
        Route::post('store', [ApPaymentController::class, 'store']);
    });

    Route::prefix('arpayment')->group(function () {
        Route::post('list', [ArPaymentController::class, 'list']);
        Route::get('get/{id}', [ArPaymentController::class, 'get']);
        Route::get('detail/get/{id}', [ArPaymentController::class, 'getdetail']);
    });

    Route::prefix('sys/invoice/receivable')->group(function () {
        Route::get('get/{id}', [SysArInvoiceController::class, 'get']);
        Route::post('list', [SysArInvoiceController::class, 'list']);
        Route::post('store', [SysArInvoiceController::class, 'store']);
        Route::delete('remove/{id}', [SysArInvoiceController::class, 'remove']);
        Route::get('email/{id}', [SysArInvoiceController::class, 'email']);
    });

    Route::prefix('sys/payment/receivable')->group(function () {
        Route::get('get/{id}', [SysArPaymentController::class, 'get']);
        Route::post('list', [SysArPaymentController::class, 'list']);
        Route::post('store', [SysArPaymentController::class, 'store']);
        Route::delete('remove/{id}', [SysArPaymentController::class, 'remove']);
    });

    Route::prefix('sys/reports')->group(function () {
        Route::post('cashup', [SysReportsController::class, 'cashup']);
    });
});



Route::group([
    'middleware' => ['auth:sanctum'],
    'prefix' => 'hr'
], function ($router) {
    Route::prefix('department')->group(function () {
        Route::get('get/{id}', [DepartmentController::class, 'get']);
        Route::post('list', [DepartmentController::class, 'list']);
        Route::post('store', [DepartmentController::class, 'store']);
        Route::delete('remove/{id}', [DepartmentController::class, 'remove']);
    });

    Route::prefix('designation')->group(function () {
        Route::get('get/{id}', [DesignationController::class, 'get']);
        Route::post('list', [DesignationController::class, 'list']);
        Route::post('store', [DesignationController::class, 'store']);
        Route::delete('remove/{id}', [DesignationController::class, 'remove']);
    });

    Route::prefix('institute')->group(function () {
        Route::get('get/{id}', [InstituteController::class, 'get']);
        Route::post('list', [InstituteController::class, 'list']);
        Route::post('store', [InstituteController::class, 'store']);
        Route::delete('remove/{id}', [InstituteController::class, 'remove']);
    });

    Route::prefix('qualification')->group(function () {
        Route::get('get/{id}', [QualificationController::class, 'get']);
        Route::post('list', [QualificationController::class, 'list']);
        Route::post('store', [QualificationController::class, 'store']);
        Route::delete('remove/{id}', [QualificationController::class, 'remove']);
    });

    Route::prefix('scale')->group(function () {
        Route::get('get/{id}', [ScaleController::class, 'get']);
        Route::post('list', [ScaleController::class, 'list']);
        Route::post('store', [ScaleController::class, 'store']);
        Route::delete('remove/{id}', [ScaleController::class, 'remove']);
    });
});
 
// Protected routes (requires authentication)