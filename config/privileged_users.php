<?php

return [
    'admin' => [
        'email' => env('ADMIN_EMAIL'),
        'name' => env('ADMIN_NAME', 'Main Admin'),
        'password' => env('ADMIN_INITIAL_PASSWORD'),
    ],

    'manager' => [
        'email' => env('MANAGER_EMAIL'),
        'name' => env('MANAGER_NAME', 'Manager'),
        'password' => env('MANAGER_INITIAL_PASSWORD'),
    ],
];
