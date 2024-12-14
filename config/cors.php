<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Cross-Origin Resource Sharing (CORS) Configuration
    |--------------------------------------------------------------------------
    |
    | Here you may configure your settings for cross-origin resource sharing
    | or "CORS". This determines what cross-origin operations may execute
    | in web browsers. You are free to adjust these settings as needed.
    |
    | To learn more: https://developer.mozilla.org/en-US/docs/Web/HTTP/CORS
    |
    */

    'paths' => ['api/*', 'sanctum/csrf-cookie', 'factories/*', 'storage/*'],

    'allowed_methods' => ['*'],

//    'allowed_origins' => ['http://localhost:3000', 'http://localhost:8000', 'https://metalworks.am', 'https://api.metalworks.am'],

    'allowed_origins' => ['https://metalworks.am', 'https://api.metalworks.am'],

    'allowed_origins_patterns' => [],

    'allowed_headers' => ['*', 'https://metalworks.am', 'https://api.metalworks.am', 'http://localhost:3000', 'http://localhost:8000'],

    'exposed_headers' => [],

    'max_age' => 0,

    'supports_credentials' => true

];
