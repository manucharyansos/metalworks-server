<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Auth\Notifications\ResetPassword;
use Tests\TestCase;

class PasswordResetEmailTest extends TestCase
{
    /**
     * @dataProvider resetLocales
     */
    public function test_reset_mail_uses_requested_language_and_frontend_route(
        string $locale,
        string $path,
        string $heading
    ): void {
        config()->set('frontend.password_reset_url', 'http://localhost:3000');
        app()->setLocale($locale);

        $user = new User(['email' => 'test@example.com']);
        $mail = (new ResetPassword('sample-token'))->toMail($user);

        $this->assertSame('emails.password-reset', $mail->view);
        $url = $mail->viewData['resetUrl'];
        $this->assertSame(
            'http://localhost:3000' . $path . '?token=sample-token&email=test%40example.com',
            $url
        );

        $body = view($mail->view, $mail->viewData)->render();
        $this->assertStringContainsString($heading, $body);
        $this->assertStringContainsString($url, $body);
    }

    public static function resetLocales(): array
    {
        return [
            'Armenian' => ['hy', '/reset-password', 'Գաղտնաբառի վերականգնում'],
            'Russian' => ['ru', '/ru/reset-password', 'Восстановление пароля'],
            'English' => ['en', '/en/reset-password', 'Reset your password'],
        ];
    }
}
