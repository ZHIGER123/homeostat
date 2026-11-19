<?php

namespace App\Services;

use App\Mail\VerificationCodeMail;
use App\Models\EmailVerificationCode;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\TooManyRequestsHttpException;
use Symfony\Component\HttpKernel\Exception\ServiceUnavailableHttpException;

class EmailOtpService
{
    public function requestCode(string $email, string $name, string $role, ?string $birthDate = null, ?string $passwordHash = null): void
    {
        Cache::lock($this->operationKey($email), 30)->block(3, function () use ($email, $name, $role, $birthDate, $passwordHash): void {
            $this->sendCode($email, $name, $role, $birthDate, $passwordHash);
        });
    }

    private function sendCode(string $email, string $name, string $role, ?string $birthDate, ?string $passwordHash): void
    {
        $transport = config('mail.mailers.'.config('mail.default').'.transport');
        if (! app()->runningUnitTests() && ! in_array($transport, ['smtp', 'sendmail', 'ses', 'ses-v2', 'mailgun', 'postmark', 'resend'], true)) {
            throw new ServiceUnavailableHttpException(null, 'Отправка писем не настроена. Администратору нужно подключить SMTP или почтовый сервис.');
        }
        $email = $this->normalize($email);
        $cooldown = $this->cooldownKey($email);
        if (Cache::has($cooldown)) {
            throw new TooManyRequestsHttpException(60, 'Повторно запросить код можно через минуту.');
        }

        $code = (string) random_int(100000, 999999);
        EmailVerificationCode::where('email', $email)->whereNull('used_at')->update(['used_at' => now()]);
        $record = EmailVerificationCode::create([
            'email' => $email,
            'purpose' => 'registration',
            'code_hash' => Hash::make($code),
            'payload' => json_encode(['name' => trim($name), 'role' => $role, 'birth_date' => $birthDate, 'password_hash' => $passwordHash], JSON_THROW_ON_ERROR),
            'expires_at' => now()->addMinutes((int) config('services.email_otp.ttl', 10)),
        ]);

        $ttl = (int) config('services.email_otp.ttl', 10);
        try {
            Mail::to($email)->send(new VerificationCodeMail($code, $ttl));
        } catch (\Throwable $exception) {
            $record->update(['used_at' => now()]);
            report($exception);
            throw new ServiceUnavailableHttpException(null, 'Не удалось отправить код. Попробуйте позже или сообщите администратору о проблеме с почтой.');
        }
        Cache::put($cooldown, true, now()->addSeconds((int) config('services.email_otp.resend_seconds', 60)));
    }

    public function verifyCode(string $email, string $code, ?bool &$created = null): User
    {
        return Cache::lock($this->operationKey($email), 30)->block(3, function () use ($email, $code, &$created): User {
            return $this->consumeCode($email, $code, $created);
        });
    }

    private function consumeCode(string $email, string $code, ?bool &$created): User
    {
        $email = $this->normalize($email);
        $record = EmailVerificationCode::query()
            ->where('email', $email)
            ->where('purpose', 'registration')
            ->whereNull('used_at')
            ->where('expires_at', '>', now())
            ->latest('id')
            ->first();

        if (! $record) {
            throw ValidationException::withMessages(['code' => 'Код истёк или не найден. Запросите новый код.']);
        }
        $maxAttempts = (int) config('services.email_otp.max_attempts', 5);
        if ($record->attempts >= $maxAttempts) {
            $record->update(['used_at' => now()]);
            throw ValidationException::withMessages(['code' => 'Превышено число попыток. Запросите новый код.']);
        }
        if (! Hash::check($code, $record->code_hash)) {
            $record->increment('attempts');
            if ($record->fresh()->attempts >= $maxAttempts) $record->update(['used_at' => now()]);
            throw ValidationException::withMessages(['code' => 'Неверный код подтверждения.']);
        }
        $record->update(['used_at' => now()]);

        $created = false;
        $user = User::where('email', $email)->first();
        if ($user) {
            if ($user->is_blocked) throw new AccessDeniedHttpException('Пользователь заблокирован.');
            if (! $user->email_verified_at) $user->forceFill(['email_verified_at' => now()])->save();
            return $user;
        }
        $payload = json_decode((string) $record->payload, true, 512, JSON_THROW_ON_ERROR);
        \Illuminate\Support\Facades\Validator::make($payload, ['birth_date' => ['required', 'date_format:Y-m-d', new \App\Rules\MinimumAge(User::MIN_AGE)]])->validate();
        $created = true;
        $user = User::create([
            'name' => $payload['name'] ?: Str::before($email, '@'),
            'email' => $email,
            'password' => $payload['password_hash'] ?? Str::random(64),
            'role' => 'listener',
            'birth_date' => $payload['birth_date'] ?? null,
        ]);
        $user->forceFill(['email_verified_at' => now(), 'age_confirmed_at' => now()])->save();

        return $user->refresh();
    }

    private function operationKey(string $email): string { return 'homeostat:email-otp:operation:'.hash('sha256', $this->normalize($email)); }
    private function normalize(string $email): string { return Str::lower(trim($email)); }
    private function cooldownKey(string $email): string { return 'homeostat:email-otp:cooldown:'.hash('sha256', $email); }
}
