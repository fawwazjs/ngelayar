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

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    // === NGELAYAR ML Service (Python FastAPI) — Phase 3 plug-and-play ===
    'ml' => [
        'url' => env('ML_SERVICE_URL', 'http://localhost:8001'),
        'key' => env('ML_SERVICE_API_KEY', null),
        'timeout' => env('ML_SERVICE_TIMEOUT', 10),
        // Ganti 'enabled' => true saat model siap, controller akan otomatis pakai Http::get live
        'enabled' => env('ML_ENABLED', false),
    ],

];
