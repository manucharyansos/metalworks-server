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
            'hy' => [
                'subject' => 'Գրանցումը հաստատված է', 'title' => 'Բարի գալուստ աշխատանքային հարթակ', 'hello' => 'Բարև',
                'body' => 'Ձեր գրանցման հարցումը հաստատված է։ Ընկերության աշխատանքային հարթակն այժմ հասանելի է ձեզ։',
                'company' => 'Ձեր ընկերությունը', 'button' => 'Մուտք գործել հարթակ',
                'credentials' => 'Մուտք գործելու համար օգտագործեք ձեր էլ․ փոստը և գրանցման ժամանակ նշած գաղտնաբառը։',
                'help' => 'Եթե մուտքի կամ հասանելիության վերաբերյալ հարցեր ունեք, կապ հաստատեք ընկերության պատասխանատուի հետ։',
                'closing' => 'Մաղթում ենք արդյունավետ աշխատանք և հաջող համագործակցություն։',
            ],
            'ru' => [
                'subject' => 'Регистрация подтверждена', 'title' => 'Добро пожаловать на рабочую платформу', 'hello' => 'Здравствуйте',
                'body' => 'Ваша заявка на регистрацию подтверждена. Доступ к рабочей платформе компании открыт.',
                'company' => 'Ваша компания', 'button' => 'Войти на платформу',
                'credentials' => 'Для входа используйте свой email и пароль, указанный при регистрации.',
                'help' => 'Если возникнут вопросы о входе или доступе, свяжитесь с представителем компании.',
                'closing' => 'Желаем продуктивной работы и успешного сотрудничества!',
            ],
            'en' => [
                'subject' => 'Registration approved', 'title' => 'Welcome to your workspace', 'hello' => 'Hello',
                'body' => 'Your registration request has been approved. You now have access to the company’s workspace.',
                'company' => 'Your company', 'button' => 'Sign in to your workspace',
                'credentials' => 'Sign in with your email and the password you entered when registering.',
                'help' => 'If you have questions about signing in or your access, please contact a company representative.',
                'closing' => 'We wish you productive work and successful collaboration.',
            ],
        ];
        $language = array_key_exists($this->language, $copy) ? $this->language : 'hy';
        $url = rtrim(config('registration.workspace_url'), '/') . '/' . ($language === 'hy' ? '' : $language . '/') . 'login/';
        return $this->subject($this->companyName . ' — ' . $copy[$language]['subject'])
            ->markdown('mail.registration-approved', ['copy' => $copy[$language], 'loginUrl' => $url]);
    }
}
