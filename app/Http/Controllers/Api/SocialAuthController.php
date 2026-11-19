<?php

namespace App\Http\Controllers\Api;

use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Http\Resources\UserResource;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Вход через Google (OAuth 2.0 / OpenID Connect).
 * Другие провайдеры (Apple ID, GitHub) отключены.
 * Платформа строго 16+: перед входом пользователь подтверждает возраст.
 */
class SocialAuthController extends Controller
{
    private const PROVIDERS = ['google'];

    public function redirect(Request $request, string $provider): RedirectResponse
    {
        abort_unless(in_array($provider, self::PROVIDERS, true), 404);

        if (! $request->boolean('age_confirmed')) {
            return redirect()->to($this->frontendUrl().'/?oauth_error='.rawurlencode('Homeostat доступен только пользователям старше 16 лет. Подтвердите возраст.'));
        }

        $configuration = config("services.oauth.{$provider}");
        abort_unless($configuration['client_id'] && $configuration['client_secret'] && $configuration['redirect'], 503, 'Вход через Google не настроен.');

        $state = Str::random(64);
        Cache::put($this->stateKey($state), [
            'provider' => $provider,
            'role' => UserRole::Listener->value,
            'age_confirmed' => true,
        ], now()->addMinutes(10));

        $query = [
            'client_id' => $configuration['client_id'],
            'redirect_uri' => $configuration['redirect'],
            'response_type' => 'code',
            'scope' => $configuration['scope'],
            'state' => $state,
            'prompt' => 'select_account',
            'access_type' => 'online',
        ];

        return redirect()->away($configuration['authorize'].'?'.http_build_query($query));
    }

    public function callback(Request $request, string $provider): RedirectResponse
    {
        try {
            abort_unless(in_array($provider, self::PROVIDERS, true), 404);
            $stored = Cache::pull($this->stateKey($request->string('state')->value()));
            abort_unless($stored && $stored['provider'] === $provider, 419, 'OAuth-сессия истекла.');
            abort_unless($stored['age_confirmed'] ?? false, 403, 'Homeostat доступен только пользователям старше 16 лет.');
            abort_if($request->filled('error'), 422, 'Авторизация отменена.');

            $profile = $this->profile($provider, $request->string('code')->value());
            $user = $this->findOrCreateUser($provider, $profile, $stored['role']);
            abort_if($user->is_blocked, 403, 'Пользователь заблокирован.');

            $handle = Str::random(64);
            Cache::put($this->exchangeKey($handle), [
                'user' => (new UserResource($user))->resolve($request),
                'token' => $user->createToken("oauth-{$provider}")->plainTextToken,
                'new_account' => $user->wasRecentlyCreated,
            ], now()->addMinute());

            return redirect()->to($this->frontendUrl().'/?oauth='.$handle);
        } catch (\Throwable $exception) {
            report($exception);
            $message = $exception instanceof RuntimeException || $exception instanceof \Symfony\Component\HttpKernel\Exception\HttpExceptionInterface
                ? ($exception->getMessage() ?: 'Не удалось завершить авторизацию.')
                : 'Не удалось завершить авторизацию.';

            return redirect()->to($this->frontendUrl().'/?oauth_error='.rawurlencode($message));
        }
    }

    public function exchange(Request $request): JsonResponse
    {
        $handle = $request->validate(['handle' => ['required', 'string', 'size:64']])['handle'];
        $payload = Cache::pull($this->exchangeKey($handle));

        abort_unless($payload, 410, 'Ссылка авторизации истекла.');

        return response()->json($payload);
    }

    private function profile(string $provider, string $code): array
    {
        abort_if($code === '', 422, 'Google не вернул код авторизации.');
        $configuration = config("services.oauth.{$provider}");
        $token = Http::acceptJson()->asForm()->timeout(10)->post($configuration['token'], [
            'grant_type' => 'authorization_code',
            'client_id' => $configuration['client_id'],
            'client_secret' => $configuration['client_secret'],
            'redirect_uri' => $configuration['redirect'],
            'code' => $code,
        ])->throw()->json();

        $data = Http::withToken($token['access_token'])->timeout(10)->get($configuration['userinfo'])->throw()->json();

        return [
            'id' => $data['sub'] ?? null,
            'email' => $data['email'] ?? null,
            'name' => $data['name'] ?? null,
            'verified' => filter_var($data['email_verified'] ?? false, FILTER_VALIDATE_BOOLEAN),
        ];
    }

    private function findOrCreateUser(string $provider, array $profile, string $role): User
    {
        abort_unless($profile['id'] && filter_var($profile['email'] ?? null, FILTER_VALIDATE_EMAIL) && ($profile['verified'] ?? false), 422, 'Google не вернул подтверждённый email.');

        $email = Str::lower(trim($profile['email']));
        $user = User::where('oauth_provider', $provider)->where('oauth_id', $profile['id'])->first()
            ?? User::where('email', $email)->first();

        $created = ! $user;
        if (! $user) {
            $user = User::create([
                'name' => $profile['name'] ?: Str::before($email, '@'),
                'email' => $email,
                'password' => Hash::make(Str::random(64)),
                'role' => $role,
                'oauth_provider' => $provider,
                'oauth_id' => $profile['id'],
            ]);
            $user->forceFill(['email_verified_at' => now(), 'age_confirmed_at' => now()])->save();
        } else {
            $user->forceFill([
                'oauth_provider' => $provider,
                'oauth_id' => $profile['id'],
                'email_verified_at' => $user->email_verified_at ?: now(),
                'age_confirmed_at' => $user->age_confirmed_at ?: now(),
            ])->save();
        }

        $user->refresh();
        $user->wasRecentlyCreated = $created;

        return $user;
    }

    private function stateKey(string $state): string
    {
        return 'homeostat:oauth:state:'.$state;
    }

    private function exchangeKey(string $handle): string
    {
        return 'homeostat:oauth:exchange:'.$handle;
    }

    private function frontendUrl(): string
    {
        return rtrim((string) env('CLIENT_URL', config('app.url')), '/');
    }
}
