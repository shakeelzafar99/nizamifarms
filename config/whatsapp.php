<?php

return [

    /*
    |--------------------------------------------------------------------------
    | WhatsApp Business API Configuration
    |--------------------------------------------------------------------------
    |
    | Credentials and settings for the Meta WhatsApp Cloud API.
    | All values should be set in your .env file.
    |
    */

    'phone_number_id' => env('WHATSAPP_PHONE_NUMBER_ID', ''),
    'business_account_id' => env('WHATSAPP_BUSINESS_ACCOUNT_ID', ''),
    'access_token' => env('WHATSAPP_ACCESS_TOKEN', ''),
    'app_secret' => env('WHATSAPP_APP_SECRET', ''),
    'verify_token' => env('WHATSAPP_VERIFY_TOKEN', 'nizamifarms_wa_hook_2026'),
    'api_version' => env('WHATSAPP_API_VERSION', 'v21.0'),

    'api_base_url' => 'https://graph.facebook.com',

    // Media storage path (relative to storage/app)
    'media_disk' => 'public',
    'media_path' => 'whatsapp-media',

    // Auto-reply settings (disabled by default, enable later)
    'auto_reply_enabled' => env('WHATSAPP_AUTO_REPLY_ENABLED', false),
    'auto_reply_message' => env('WHATSAPP_AUTO_REPLY_MESSAGE', 'Thank you for reaching out to Nizami Farms. Our team will respond shortly.'),
    'business_hours_start' => env('WHATSAPP_BUSINESS_HOURS_START', '09:00'),
    'business_hours_end' => env('WHATSAPP_BUSINESS_HOURS_END', '21:00'),

    // Firebase Cloud Messaging
    'firebase_project_id' => env('FIREBASE_PROJECT_ID', ''),
    'firebase_credentials_path' => env('FIREBASE_CREDENTIALS_PATH', 'firebase-service-account.json'),

];
