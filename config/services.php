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

    // Kredensial hanya disimpan di .env server. Migration membaca konfigurasi
    // ini dan menyimpan password yang sudah di-hash, bukan teks aslinya.
    'developer' => [
        'name' => env('DEVELOPER_NAME', 'Developer 1017 Website'),
        'email' => env('DEVELOPER_EMAIL'),
        'password' => env('DEVELOPER_PASSWORD'),
        'tenant_slug' => env('DEVELOPER_TENANT_SLUG'),
        'store_code' => env('DEVELOPER_STORE_CODE'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

];
