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

class SocialAuthController extends Controller
{
    private const PROVIDERS = ['google', 'apple', 'github'];

    public function redirect(Request $request, string $provider): RedirectResponse
    {
        abort_unless(in_array($provider, self::PROVIDERS, true), 404);

        $configuration = config("services.oauth.{$provider}");
        abort_unless($configuration['client_id'] && $configuration['redirect'], 503, 'OAuth-провайдер не настроен.');

        $state = Str::random(64);
        Cache::put($this->stateKey($state), [
            'provider' => $provider,
            'role' => $request->string('role')->value() === UserRole::Artist->value
                ? UserRole::Artist->value
                : UserRole::Listener->value,
        ], now()->addMinutes(10));

        $query = [
            'client_id' => $configuration['client_id'],
            'redirect_uri' => $configuration['redirect'],
            'response_type' => 'code',
            'scope' => $configuration['scope'],
            'state' => $state,
        ];
        if ($provider === 'apple') {
            $query['response_mode'] = 'query';
        }

        return redirect()->away($configuration['authorize'].'?'.http_build_query($query));
    }

    public function callback(Request $request, string $provider): RedirectResponse
    {
        try {
            abort_unless(in_array($provider, self::PROVIDERS, true), 404);
            $stored = Cache::pull($this->stateKey($request->string('state')->value()));
            abort_unless($stored && $stored['provider'] === $provider, 419, 'OAuth-сессия истекла.');
            abort_if($request->filled('error'), 422, 'Авторизация отменена.');

            $profile = $this->profile($provider, $request->string('code')->value());
            $user = $this->findOrCreateUser($profile, $stored['role']);
            abort_if($user->is_blocked, 403, 'Пользователь заблокирован.');

            $handle = Str::random(64);
            Cache::put($this->exchangeKey($handle), [
                'user' => (new UserResource($user))->resolve($request),
                'token' => $user->createToken("oauth-{$provider}")->plainTextToken,
            ], now()->addMinute());

            return redirect()->to($this->frontendUrl().'/?oauth='.$handle);
        } catch (\Throwable $exception) {
            report($exception);

            return redirect()->to($this->frontendUrl().'/?oauth_error='.rawurlencode(
                $exception instanceof RuntimeException ? $exception->getMessage() : 'Не удалось завершить авторизацию.'
            ));
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
        $configuration = config("services.oauth.{$provider}");
        $token = Http::acceptJson()->asForm()->post($configuration['token'], [
            'grant_type' => 'authorization_code',
            'client_id' => $configuration['client_id'],
            'client_secret' => $configuration['client_secret'],
            'redirect_uri' => $configuration['redirect'],
            'code' => $code,
        ])->throw()->json();

        if ($provider === 'google') {
            $data = Http::withToken($token['access_token'])->get($configuration['userinfo'])->throw()->json();

            return ['id' => $data['sub'], 'email' => $data['email'] ?? null, 'name' => $data['name'] ?? null];
        }

        if ($provider === 'github') {
            $data = Http::withToken($token['access_token'])->get($configuration['userinfo'])->throw()->json();
            $primaryEmail = Http::withToken($token['access_token'])
                ->get($configuration['emails'])
                ->throw()
                ->collect()
                ->firstWhere('primary', true);
            $email = $data['email'] ?? (is_array($primaryEmail) ? ($primaryEmail['email'] ?? null) : null);

            return ['id' => (string) $data['id'], 'email' => $email, 'name' => $data['name'] ?: $data['login']];
        }

        $claims = $this->verifiedAppleClaims((string) ($token['id_token'] ?? ''));

        return ['id' => $claims['sub'] ?? null, 'email' => $claims['email'] ?? null, 'name' => 'Пользователь Apple'];
    }

    private function findOrCreateUser(array $profile, string $role): User
    {
        abort_unless($profile['id'] && filter_var($profile['email'] ?? null, FILTER_VALIDATE_EMAIL), 422, 'Провайдер не вернул подтверждённый email.');

        $user = User::where('oauth_provider', request()->route('provider'))
            ->where('oauth_id', $profile['id'])
            ->first()
            ?? User::where('email', $profile['email'])->first();

        if (! $user) {
            $user = User::create([
                'name' => $profile['name'] ?: Str::before($profile['email'], '@'),
                'email' => $profile['email'],
                'password' => Hash::make(Str::random(64)),
                'role' => $role,
                'oauth_provider' => request()->route('provider'),
                'oauth_id' => $profile['id'],
            ]);
            $user->forceFill(['email_verified_at' => now()])->save();
        } else {
            $user->forceFill([
                'oauth_provider' => request()->route('provider'),
                'oauth_id' => $profile['id'],
                'email_verified_at' => $user->email_verified_at ?: now(),
            ])->save();
        }

        return $user->refresh();
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

    private function verifiedAppleClaims(string $jwt): array
    {
        $parts = explode('.', $jwt);
        abort_unless(count($parts) === 3, 422, 'Apple не вернул профиль пользователя.');

        $header = json_decode($this->base64UrlDecode($parts[0]), true, 512, JSON_THROW_ON_ERROR);
        $claims = json_decode($this->base64UrlDecode($parts[1]), true, 512, JSON_THROW_ON_ERROR);
        $jwk = Http::timeout(5)->get('https://appleid.apple.com/auth/keys')->throw()->collect('keys')
            ->firstWhere('kid', $header['kid'] ?? null);
        abort_unless(is_array($jwk), 422, 'Не найден ключ подписи Apple.');
        abort_unless(($header['alg'] ?? null) === 'ES256' && ($jwk['kty'] ?? null) === 'EC', 422, 'Неподдерживаемый алгоритм Apple.');

        $signature = $this->ecdsaSignatureToDer($this->base64UrlDecode($parts[2]));
        $verified = openssl_verify(
            "{$parts[0]}.{$parts[1]}",
            $signature,
            $this->jwkToPem($jwk),
            OPENSSL_ALGO_SHA256,
        );
        abort_unless($verified === 1, 422, 'Подпись Apple недействительна.');

        abort_unless(($claims['iss'] ?? null) === 'https://appleid.apple.com', 422, 'Некорректный issuer Apple.');
        abort_unless(($claims['aud'] ?? null) === config('services.oauth.apple.client_id'), 422, 'Некорректный audience Apple.');

        return $claims;
    }

    private function base64UrlDecode(string $value): string
    {
        return base64_decode(strtr($value, '-_', '+/').str_repeat('=', (4 - strlen($value) % 4) % 4), true) ?: '';
    }

    private function jwkToPem(array $jwk): string
    {
        $x = $this->base64UrlDecode((string) ($jwk['x'] ?? ''));
        $y = $this->base64UrlDecode((string) ($jwk['y'] ?? ''));
        abort_unless(($jwk['crv'] ?? null) === 'P-256' && strlen($x) === 32 && strlen($y) === 32, 422, 'Некорректный ключ Apple.');
        $algorithm = "\x30\x13\x06\x07\x2a\x86\x48\xce\x3d\x02\x01\x06\x08\x2a\x86\x48\xce\x3d\x03\x01\x07";
        $bitString = "\x03\x42\x00\x04".$x.$y;

        return "-----BEGIN PUBLIC KEY-----\n".chunk_split(base64_encode("\x30".$this->asn1Length(strlen($algorithm) + strlen($bitString)).$algorithm.$bitString), 64, "\n")."-----END PUBLIC KEY-----\n";
    }

    private function ecdsaSignatureToDer(string $signature): string
    {
        abort_unless(strlen($signature) === 64, 422, 'Некорректная подпись Apple.');
        $r = ltrim(substr($signature, 0, 32), "\x00") ?: "\x00";
        $s = ltrim(substr($signature, 32, 32), "\x00") ?: "\x00";
        if (ord($r[0]) & 0x80) $r = "\x00".$r;
        if (ord($s[0]) & 0x80) $s = "\x00".$s;
        $body = "\x02".$this->asn1Length(strlen($r)).$r."\x02".$this->asn1Length(strlen($s)).$s;

        return "\x30".$this->asn1Length(strlen($body)).$body;
    }

    private function asn1Length(int $length): string
    {
        if ($length < 128) return chr($length);
        $encoded = ltrim(pack('N', $length), "\x00");
        return chr(0x80 | strlen($encoded)).$encoded;
    }
}