<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

class CaptchaService
{
    public function challenge(): array
    {
        if (config('services.turnstile.site_key') && config('services.turnstile.secret')) {
            return [
                'provider' => 'turnstile',
                'site_key' => config('services.turnstile.site_key'),
                'expires_in' => 300,
            ];
        }

        $left = random_int(2, 9);
        $right = random_int(1, 9);
        $id = (string) Str::uuid();

        Cache::put($this->key($id), (string) ($left + $right), now()->addMinutes(10));

        return [
            'provider' => 'math',
            'id' => $id,
            'question' => "Сколько будет {$left} + {$right}?",
            'expires_in' => 600,
        ];
    }

    public function verify(?string $id, ?string $answer, ?string $turnstileToken, ?string $ip): bool
    {
        if (config('services.turnstile.site_key') && config('services.turnstile.secret')) {
            if (! $turnstileToken) {
                return false;
            }

            return (bool) Http::asForm()
                ->timeout(5)
                ->post('https://challenges.cloudflare.com/turnstile/v0/siteverify', [
                    'secret' => config('services.turnstile.secret'),
                    'response' => $turnstileToken,
                    'remoteip' => $ip,
                ])
                ->json('success', false);
        }

        if (! $id || $answer === null || ! preg_match('/^\d{1,3}$/', $answer)) {
            return false;
        }

        $expected = Cache::pull($this->key($id));

        return $expected !== null && hash_equals((string) $expected, (string) $answer);
    }

    private function key(string $id): string
    {
        return 'homeostat:captcha:'.$id;
    }
}