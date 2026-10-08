<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

class RegistrationApproved extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(public string $applicantName, public string $companyName, public string $language = 'hy') {}

    public function build(): self
    {
        $copy = [
            'hy' => ['subject' => 'Գրանցումը հաստատված է', 'title' => 'Ձեր գրանցումը հաստատված է։', 'hello' => 'Բարև', 'body' => 'Մենեջերը հաստատել է ձեր գրանցման հարցումը։ Այժմ կարող եք մուտք գործել նշված ընկերության աշխատանքային հարթակ՝ ձեր էլ․ փոստով և գրանցման ժամանակ նշած գաղտնաբառով։', 'company' => 'Ընկերություն', 'button' => 'Մուտք գործել', 'help' => 'Եթե հարցեր ունեք ձեր հասանելիության մասին, դիմեք ընկերության մենեջերին։'],
            'ru' => ['subject' => 'Регистрация подтверждена', 'title' => 'Ваша регистрация подтверждена.', 'hello' => 'Здравствуйте', 'body' => 'Менеджер подтвердил вашу заявку. Теперь вы можете войти на рабочую платформу указанной компании с вашим email и паролем, указанным при регистрации.', 'company' => 'Компания', 'button' => 'Войти', 'help' => 'По вопросам доступа обращайтесь к менеджеру компании.'],
            'en' => ['subject' => 'Registration approved', 'title' => 'Your registration has been approved.', 'hello' => 'Hello', 'body' => 'The manager approved your registration request. You can now sign in to this company’s workspace with your email and the password you entered when applying.', 'company' => 'Company', 'button' => 'Sign in', 'help' => 'Contact the company manager if you have questions about your access.'],
        ];
        $language = array_key_exists($this->language, $copy) ? $this->language : 'hy';
        $url = rtrim(config('registration.workspace_url'), '/') . '/' . ($language === 'hy' ? '' : $language . '/') . 'login/';
        return $this->subject($this->companyName . ' — ' . $copy[$language]['subject'])
            ->markdown('mail.registration-approved', ['copy' => $copy[$language], 'loginUrl' => $url]);
    }
}
