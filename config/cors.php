<?php

$appEnv = (string) env('APP_ENV', 'production');
$isLocal = in_array($appEnv, ['local', 'testing'], true);

$defaultOrigins = $isLocal
    ? 'http://localhost:3000,http://127.0.0.1:3000,https://metalworks.am,https://www.metalworks.am'
    : 'https://metalworks.am,https://www.metalworks.am';

$allowedOrigins = array_values(array_filter(array_map(
    'trim',
    explode(',', (string) env('CORS_ALLOWED_ORIGINS', $defaultOrigins))
)));

$allowedOriginPatterns = $isLocal ? [
    '#^http://localhost(?::\d+)?$#',
    '#^http://127\.0\.0\.1(?::\d+)?$#',
] : [];

return [
    'paths' => ['api/*', 'sanctum/csrf-cookie'],

    'allowed_methods' => ['*'],

    'allowed_origins' => $allowedOrigins,

    'allowed_origins_patterns' => $allowedOriginPatterns,

    'allowed_headers' => ['*'],

    'exposed_headers' => [],

    'max_age' => 0,

    'supports_credentials' => true,
];
