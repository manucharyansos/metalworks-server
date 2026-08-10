<?php

namespace App\Providers;

use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // In production, default session cookies to HTTPS-only unless the
        // production environment explicitly set SESSION_SECURE_COOKIE.
        if ($this->app->environment('production') && config('session.secure') === null) {
            config(['session.secure' => true]);
        }

        ResetPassword::createUrlUsing(function (object $notifiable, string $token): string {
            $frontendUrl = rtrim((string) config('frontend.password_reset_url'), '/');
            $email = urlencode($notifiable->getEmailForPasswordReset());

            return "{$frontendUrl}/reset-password?token=" . urlencode($token) . "&email={$email}";
        });
    }
}
