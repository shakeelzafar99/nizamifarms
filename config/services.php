<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Mailgun, Postmark, AWS and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'token' => env('POSTMARK_TOKEN'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'resend' => [
        'key' => env('RESEND_KEY'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    // Google Maps Directions API (rider dispatch / multi-stop ETAs).
    // IMPORTANT: read this key via config('services.google_maps.directions_key'),
    // NEVER via env('GOOGLE_MAPS_DIRECTIONS_API_KEY') directly in app code.
    // When config is cached (php artisan config:cache), env() returns null
    // outside config files, which silently breaks dispatch ETAs. Keeping the
    // env() read here (inside a config file) is cache-safe.
    'google_maps' => [
        'directions_key' => env('GOOGLE_MAPS_DIRECTIONS_API_KEY'),
    ],

];
