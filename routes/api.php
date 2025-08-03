<?php


use App\Http\Controllers\AuthController;
use App\Http\Controllers\CRM\AccountCustomerController;
use App\Http\Controllers\CRM\BranchController;
use App\Http\Controllers\CRM\CompanyConfigController;
use App\Http\Controllers\CRM\CompanyController;
use App\Http\Controllers\CRM\OrderController;
use App\Http\Controllers\CRM\WalkInCustomerController; 
use App\Http\Controllers\MailController;
use App\Http\Controllers\PDM\BrandController;
use App\Http\Controllers\PDM\PartController;
use App\Http\Controllers\PDM\ProductController;
use App\Http\Controllers\PDM\ProductTreadPatternsController;
use App\Http\Controllers\PDM\ServiceController;
use App\Http\Controllers\PDM\SizeController; 
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

  
 
// Protected routes (requires authentication)