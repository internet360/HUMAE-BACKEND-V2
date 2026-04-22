<?php

declare(strict_types=1);

return [
    'paths' => ['api/*', 'sanctum/csrf-cookie'],

    'allowed_methods' => ['*'],

    // En prod: solo FRONTEND_URL (el dominio real).
    // En dev local: cualquier puerto de localhost / 127.0.0.1 via pattern.
    'allowed_origins' => array_values(array_filter([
        env('FRONTEND_URL'),
    ])),

    'allowed_origins_patterns' => env('APP_ENV') === 'local'
        ? ['#^https?://(localhost|127\.0\.0\.1)(:\d+)?$#']
        : [],

    'allowed_headers' => ['*'],

    'exposed_headers' => [],

    'max_age' => 0,

    'supports_credentials' => true,
];
