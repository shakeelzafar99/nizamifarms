<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Shopify API Configuration
    |--------------------------------------------------------------------------
    |
    | Store your Shopify API credentials and store name here.
    | Make sure to add these values in your .env file.
    |
    */

    'api_key' => env('SHOPIFY_API_KEY', ''),
    'password' => env('SHOPIFY_PASSWORD', ''),
    'store_name' => env('SHOPIFY_STORE_NAME', ''),
    'api_version' => env('SHOPIFY_API_VERSION', '2023-10'),

];
