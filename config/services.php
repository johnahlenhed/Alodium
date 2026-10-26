<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Resend, Postmark, AWS, and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'key' => env('POSTMARK_API_KEY'),
    ],

    // Automatic rebuilds of the Astro public site (see deploy/rebuild-astro.sh).
    'astro' => [
        // Shared secret for POST /api/webhook/rebuild. Empty = webhook disabled.
        'webhook_secret' => env('WEBHOOK_SECRET'),
        // Absolute path to the rebuild script. Empty = rebuilds are skipped (local dev).
        'rebuild_script' => env('ASTRO_REBUILD_SCRIPT'),
        // Written by deploy/rebuild-astro.sh; read (never written) by the dashboard.
        'rebuild_log' => storage_path('logs/astro-rebuild.log'),
    ],

    'resend' => [
        'key' => env('RESEND_API_KEY'),
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

];
