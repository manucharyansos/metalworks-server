@php
    $copy = [
        'hy' => [
            'heading' => 'Գաղտնաբառի վերականգնում',
            'hello' => 'Բարև Ձեզ,',
            'intro' => 'Ստացել ենք Ձեր Metalworks հաշվի գաղտնաբառը վերականգնելու հարցումը։',
            'button' => 'Վերականգնել գաղտնաբառը',
            'expiry' => 'Այս հղումը գործում է :minutes րոպե։',
            'ignore' => 'Եթե Դուք չեք ուղարկել այս հարցումը, որևէ գործողություն անհրաժեշտ չէ։',
            'fallback' => 'Եթե կոճակը չի աշխատում, պատճենեք և բացեք այս հղումը Ձեր դիտարկիչում։',
            'signature' => 'Հարգանքով՝ Metalworks թիմ',
        ],
        'ru' => [
            'heading' => 'Восстановление пароля',
            'hello' => 'Здравствуйте!',
            'intro' => 'Мы получили запрос на восстановление пароля к вашей учетной записи Metalworks.',
            'button' => 'Восстановить пароль',
            'expiry' => 'Ссылка действует :minutes минут.',
            'ignore' => 'Если вы не запрашивали восстановление пароля, никаких действий не требуется.',
            'fallback' => 'Если кнопка не работает, скопируйте ссылку и откройте её в браузере.',
            'signature' => 'С уважением, команда Metalworks',
        ],
        'en' => [
            'heading' => 'Reset your password',
            'hello' => 'Hello!',
            'intro' => 'We received a request to reset the password for your Metalworks account.',
            'button' => 'Reset password',
            'expiry' => 'This link expires in :minutes minutes.',
            'ignore' => 'If you did not request a password reset, no further action is needed.',
            'fallback' => 'If the button does not work, copy and paste this link into your browser.',
            'signature' => 'Regards, the Metalworks team',
        ],
    ][$locale ?? 'hy'];
@endphp
<!DOCTYPE html>
<html lang="{{ $locale ?? 'hy' }}">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $copy['heading'] }}</title>
</head>
<body style="margin:0;padding:0;background:#f1f5f9;color:#0f172a;font-family:Arial,Helvetica,sans-serif;">
    <table role="presentation" cellpadding="0" cellspacing="0" width="100%" style="background:#f1f5f9;padding:40px 14px;">
        <tr><td align="center">
            <table role="presentation" cellpadding="0" cellspacing="0" width="100%" style="max-width:560px;background:#ffffff;border-radius:16px;overflow:hidden;">
                <tr>
                    <td style="background:#101d32;color:#ffffff;padding:25px 32px;font-size:22px;font-weight:700;letter-spacing:.2px;">
                        <span style="display:inline-block;background:#ffffff;color:#101d32;border-radius:9px;padding:8px 10px;font-size:15px;margin-right:10px;">MW</span> Metalworks
                    </td>
                </tr>
                <tr><td style="padding:34px 32px 26px;">
                    <h1 style="font-size:24px;line-height:1.35;margin:0 0 22px;color:#101d32;">{{ $copy['heading'] }}</h1>
                    <p style="font-size:15px;line-height:1.7;margin:0 0 12px;">{{ $copy['hello'] }}</p>
                    <p style="font-size:15px;line-height:1.7;margin:0 0 26px;">{{ $copy['intro'] }}</p>
                    <table role="presentation" cellpadding="0" cellspacing="0"><tr><td style="background:#101d32;border-radius:9px;">
                        <a href="{{ $resetUrl }}" style="display:inline-block;color:#ffffff;text-decoration:none;font-size:15px;font-weight:bold;padding:14px 24px;">{{ $copy['button'] }}</a>
                    </td></tr></table>
                    <p style="font-size:14px;line-height:1.7;margin:28px 0 8px;color:#475569;">{{ str_replace(':minutes', (string) $expires, $copy['expiry']) }}</p>
                    <p style="font-size:14px;line-height:1.7;margin:0;color:#475569;">{{ $copy['ignore'] }}</p>
                    <div style="border-top:1px solid #e2e8f0;margin:28px 0 22px;"></div>
                    <p style="font-size:13px;line-height:1.6;margin:0 0 10px;color:#64748b;">{{ $copy['fallback'] }}</p>
                    <p style="font-size:12px;line-height:1.6;margin:0;word-break:break-all;">
                        <a href="{{ $resetUrl }}" style="color:#2563eb;text-decoration:underline;">{{ $resetUrl }}</a>
                    </p>
                    <p style="font-size:13px;line-height:1.6;margin:28px 0 0;color:#64748b;">{{ $copy['signature'] }}</p>
                </td></tr>
            </table>
            <p style="font-size:12px;color:#94a3b8;margin:20px 0 0;">© {{ date('Y') }} Metalworks</p>
        </td></tr>
    </table>
</body>
</html>
