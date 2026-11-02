<?php

// Password reset links must always point at the public frontend in production.
// An accidentally copied local .env must not produce localhost links in emails.
$passwordResetUrl = env('FRONTEND_PASSWORD_RESET_URL')
    ?: env('FRONTEND_URL')
    ?: 'https://metalworks.am/work';

if (
    env('APP_ENV') === 'production'
    && preg_match('~^https?://(?:localhost|127\\.0\\.0\\.1)(?::[0-9]+)?(?:/|$)~i', $passwordResetUrl)
) {
    $passwordResetUrl = 'https://metalworks.am/work';
}

return [
    'password_reset_url' => $passwordResetUrl,
];
