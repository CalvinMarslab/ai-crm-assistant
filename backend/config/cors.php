<?php

return [
    'paths' => ['api/*'],

    'allowed_methods' => ['*'],

    /*
     * Restricted to the configured frontend rather than the framework's "*"
     * default. Add production origins via FRONTEND_URL (comma-separated).
     */
    'allowed_origins' => array_filter(array_map(
        'trim',
        explode(',', (string) env('FRONTEND_URL', 'http://localhost:5173')),
    )),

    'allowed_origins_patterns' => [],

    'allowed_headers' => ['*'],

    'exposed_headers' => [],

    'max_age' => 3600,

    // The API authenticates with bearer tokens, not cookies.
    'supports_credentials' => false,
];
