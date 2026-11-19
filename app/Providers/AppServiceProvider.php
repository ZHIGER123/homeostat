<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;

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
        RateLimiter::for('login', function (Request $request) {
            $email = is_string($request->input('email')) ? strtolower(trim($request->input('email'))) : '';
            $respond = function (Request $request, array $headers) {
                $seconds = max(1, (int) ($headers['Retry-After'] ?? 60));
                return response()->json([
                    'message' => "Слишком много попыток входа. Повторите через {$seconds} с.",
                    'retry_after' => $seconds,
                ], 429, $headers);
            };

            return [
                Limit::perMinute(5)->by('account:'.hash('sha256', $request->ip().'|'.$email))->response($respond),
                Limit::perMinute(60)->by('ip:'.$request->ip())->response($respond),
            ];
        });
    }
}
