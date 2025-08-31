<?php
return [

    'base_url' => env('WOOCOMMERCE_API_URL', 'http://www.old.nizamifarms.com/wp-json/wc/v3'),
    'consumer_key' => env('WOOCOMMERCE_CONSUMER_KEY'),
    'consumer_secret' => env('WOOCOMMERCE_CONSUMER_SECRET'),
    'webhook_secret' => env('WOOCOMMERCE_WEBHOOK_SECRET'),

];
