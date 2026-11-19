<?php

namespace App\Services;

use App\Mail\VerificationCodeMail;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\ServiceUnavailableHttpException;

class RegistrationMailService
{
    public function assertConfigured(): void
    {
        $transport = config('mail.mailers.'.config('mail.default').'.transport');
        if ($transport === 'resend') {
            $key = trim((string) config('services.resend.key'));
            $from = trim((string) config('mail.from.address'));
            if ($key === '' || ! filter_var($from, FILTER_VALIDATE_EMAIL) || str_ends_with($from, '@example.com')) {
                throw new ServiceUnavailableHttpException(null, 'Почта пока не подключена. Администратору нужно указать RESEND_API_KEY и подтверждённый MAIL_FROM_ADDRESS.');
            }
            return;
        }
        if (! app()->runningUnitTests() && ! in_array($transport, ['smtp', 'sendmail', 'ses', 'ses-v2', 'mailgun', 'postmark'], true)) {
            throw new ServiceUnavailableHttpException(null, 'Отправка писем не настроена. Подключите Resend или SMTP.');
        }
    }

    public function sendVerificationCode(string $email, string $code, int $ttl): void
    {
        $this->assertConfigured();
        if (config('mail.mailers.'.config('mail.default').'.transport') !== 'resend') {
            Mail::to($email)->send(new VerificationCodeMail($code, $ttl));
            return;
        }

        // Direct HTTPS transport: no Resend SDK or queue worker is required.
        $response = Http::withToken((string) config('services.resend.key'))
            ->acceptJson()
            ->withHeaders(['Idempotency-Key' => 'email-verification/'.Str::uuid()])
            ->connectTimeout(5)
            ->timeout(15)
            ->post('https://api.resend.com/emails', [
                'from' => (string) config('mail.from.address'),
                'to' => [$email],
                'subject' => 'Подтвердите email в Homeostat',
                'text' => view('emails.verification-code', ['code' => $code, 'ttl' => $ttl])->render(),
            ]);

        $response->throw();
        if (! $response->successful() || ! is_string($response->json('id')) || $response->json('id') === '') {
            throw new \RuntimeException('Почтовый сервис не подтвердил приём письма.');
        }
    }
}
