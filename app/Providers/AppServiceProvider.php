<?php

namespace App\Providers;

use App\Models\Client;
use App\Models\Factory;
use App\Models\FactoryFileExtension;
use App\Models\FactoryOrder;
use App\Models\Material;
use App\Models\Order;
use App\Models\Pmp;
use App\Models\PmpFiles;
use App\Models\RemoteNumber;
use App\Models\Role;
use App\Models\User;
use App\Models\Worker;
use App\Observers\ActivityObserver;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->scoped(\App\Support\CompanyContext::class);
        // ValidationServiceProvider is deferred and can replace a direct
        // presence binding. Install the verifier when its factory resolves.
        $this->app->afterResolving('validator', function ($validator, $app): void {
            $validator->setPresenceVerifier(new \App\Support\CompanyPresenceVerifier($app['db']));
        });
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

        // Record meaningful state-changing work from every staff workspace.
        // The observer deliberately ignores console jobs and unauthenticated
        // requests, so seeders/migrations never pollute the employee timeline.
        foreach ([
            Order::class,
            PmpFiles::class,
            Pmp::class,
            RemoteNumber::class,
            FactoryOrder::class,
            Worker::class,
            User::class,
            Client::class,
            Material::class,
            Factory::class,
            FactoryFileExtension::class,
            Role::class,
        ] as $model) {
            $model::observe(ActivityObserver::class);
        }

        // The frontend uses Nuxt's prefix_except_default language routes.
        $resetUrl = static function (object $notifiable, string $token): string {
            $frontendUrl = rtrim((string) config('frontend.password_reset_url'), '/');
            $locale = app()->getLocale();
            $languagePrefix = in_array($locale, ['ru', 'en'], true) ? '/' . $locale : '';
            $email = rawurlencode($notifiable->getEmailForPasswordReset());

            return "{$frontendUrl}{$languagePrefix}/reset-password?token="
                . rawurlencode($token) . "&email={$email}";
        };

        ResetPassword::createUrlUsing($resetUrl);

        ResetPassword::toMailUsing(static function (object $notifiable, string $token) use ($resetUrl): MailMessage {
            $locale = app()->getLocale();
            $locale = in_array($locale, ['hy', 'ru', 'en'], true) ? $locale : 'hy';

            $subjects = [
                'hy' => 'Metalworks — Գաղտնաբառի վերականգնում',
                'ru' => 'Metalworks — Восстановление пароля',
                'en' => 'Metalworks — Reset your password',
            ];

            return (new MailMessage())
                ->subject($subjects[$locale])
                ->view('emails.password-reset', [
                    'resetUrl' => $resetUrl($notifiable, $token),
                    'locale' => $locale,
                    'expires' => (int) config('auth.passwords.users.expire', 60),
                ]);
        });
    }
}
