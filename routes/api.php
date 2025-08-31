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
    Route::prefix('woo')->controller(ShopifyController::class)->group(function () {
        Route::prefix('order')->group(function () {
            Route::get('get/{id}', 'get');
            Route::post('list', 'list');
            Route::post('store', 'store');
            Route::delete('remove/{id}', 'remove');
        });
    });
});

//Webhook

//Route::get('sendbasicemail', 'MailController@sendEmail');

 
 
 


 

  
 
// Protected routes (requires authentication)