<?php

return [
    'password_reset_url' => env(
        'FRONTEND_PASSWORD_RESET_URL',
        env('FRONTEND_URL', 'https://metalworks.am/work')
    ),
];
