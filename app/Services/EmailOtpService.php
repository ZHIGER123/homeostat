<?php

namespace App\Services;

use App\Models\EmailVerificationCode;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\TooManyRequestsHttpException;

class EmailOtpService
{
    public function requestCode(string $email, string $name, string $role): void
    {
        $email = $this->normalize($email);
        $cooldown = $this->cooldownKey($email);
        if (Cache::has($cooldown)) {
            throw new TooManyRequestsHttpException(60, 'Повторно запросить код можно через минуту.');
        }

        $code = (string) random_int(100000, 999999);
        EmailVerificationCode::where('email', $email)->whereNull('used_at')->update(['used_at' => now()]);
        EmailVerificationCode::create([
            'email' => $email,
            'purpose' => 'registration',
            'code_hash' => Hash::make($code),
            'payload' => json_encode(['name' => trim($name), 'role' => $role], JSON_THROW_ON_ERROR),
            'expires_at' => now()->addMinutes((int) config('services.email_otp.ttl', 10)),
        ]);

        Mail::raw("Ваш код Homeostat: {$code}\n\nКод действует 10 минут и может быть использован только один раз.", function ($message) use ($email): void {
            $message->to($email)->subject('Код подтверждения Homeostat');
        });
        Cache::put($cooldown, true, now()->addSeconds((int) config('services.email_otp.resend_seconds', 60)));
    }

    public function verifyCode(string $email, string $code, ?bool &$created = null): User
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
            return $user;
        }
        $payload = json_decode((string) $record->payload, true, 512, JSON_THROW_ON_ERROR);
        $created = true;
        $user = User::create([
            'name' => $payload['name'] ?: Str::before($email, '@'),
            'email' => $email,
            'password' => Str::random(64),
            'role' => 'listener',
        ]);
        $user->forceFill(['email_verified_at' => now()])->save();

        return $user->refresh();
    }

    private function normalize(string $email): string { return Str::lower(trim($email)); }
    private function cooldownKey(string $email): string { return 'homeostat:email-otp:cooldown:'.hash('sha256', $email); }
}
