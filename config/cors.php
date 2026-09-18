<?php

return [
    /*
     |--------------------------------------------------------------------------
     | CORS Options
     |--------------------------------------------------------------------------
     | Configure which origins are allowed to make cross-origin requests to the API.
     | In production, restrict to your frontend domain(s).
     */
    'paths' => ['api/*'],

    'allowed_methods' => ['*'],

    'allowed_origins' => [
        // Development
        'http://localhost:5173',  // Vite dev server
        'http://127.0.0.1:5173',
        'http://localhost:8000',  // Laravel dev server
        'http://127.0.0.1:8000',
        // Production — replace with your actual frontend domain
        // 'https://yourfrontend.example.com',
    ],

    'allowed_origins_patterns' => [
        // Allow any .vercel.app subdomain in preview environments
        // '~^https://.*\.vercel\.app$',
    ],

    'allowed_headers' => ['*'],

    'exposed_headers' => [],

    'max_age' => 3600,

    'supports_credentials' => false,
];
