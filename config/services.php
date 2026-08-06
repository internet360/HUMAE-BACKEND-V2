<?php

declare(strict_types=1);

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
        'key' => env('POSTMARK_API_KEY'),
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

    'stripe' => [
        'key' => env('STRIPE_KEY'),
        'secret' => env('STRIPE_SECRET'),
        'webhook_secret' => env('STRIPE_WEBHOOK_SECRET'),
        'currency' => env('STRIPE_CURRENCY', 'mxn'),
    ],

    /*
    | CINCEL — constancia de conservación de mensajes de datos NOM-151-SCFI-2016.
    | Sella la integridad del PDF del contrato ya firmado; no emite la firma.
    */
    'cincel' => [
        'jwt' => env('CINCEL_JWT'),
        'base_url' => env('CINCEL_BASE_URL', 'https://api.cincel.digital/v3'),
        // La constancia no se emite al instante: la API responde 202 mientras la
        // prepara, así que hay que reintentar (mismos valores que RED1A1).
        'max_retries' => env('CINCEL_MAX_RETRIES', 5),
        'retry_delay_ms' => env('CINCEL_RETRY_DELAY_MS', 1500),
        'timeout_seconds' => env('CINCEL_TIMEOUT_SECONDS', 20),
    ],

];
