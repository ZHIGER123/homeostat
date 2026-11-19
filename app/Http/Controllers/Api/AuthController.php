<?php

namespace App\Http\Controllers\Api;

use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Http\Requests\LoginRequest;
use App\Http\Requests\RegisterRequest;
use App\Http\Requests\UpdateProfileRequest;
use App\Http\Resources\UserResource;
use App\Models\User;
use App\Rules\MinimumAge;
use App\Services\CaptchaService;
use App\Services\EmailOtpService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;

class AuthController extends Controller
{
    public function register(RegisterRequest $request, CaptchaService $captcha, EmailOtpService $otp): JsonResponse
    {
        return $this->requestEmailCode($request, $captcha, $otp);
    }

    public function requestEmailCode(Request $request, CaptchaService $captcha, EmailOtpService $otp): JsonResponse
    {
        if (is_string($request->input('email'))) {
            $request->merge(['email' => strtolower(trim($request->input('email')))]);
        }
        $data = $request->validate(['resend_token' => ['nullable', 'string', 'max:4096'], 'password' => ['required', 'string', 'min:8', 'max:128', 'confirmed'], 'name' => ['required', 'string', 'max:255'], 'email' => ['required', 'email', 'max:255', 'unique:users,email'], 'birth_date' => ['required', 'date_format:Y-m-d', new MinimumAge(User::MIN_AGE)], 'role' => ['sometimes', Rule::in(['listener', 'artist'])], 'captcha_id' => ['nullable', 'string', 'max:80'], 'captcha_answer' => ['nullable', 'string', 'max:20'], 'turnstile_token' => ['nullable', 'string', 'max:2048']], ['email.unique' => 'Этот email уже зарегистрирован. Войдите в существующий аккаунт.']);
        if (! $this->validResendToken($data['resend_token'] ?? null, $data['email']) && ! $captcha->verify($data['captcha_id'] ?? null, $data['captcha_answer'] ?? null, $data['turnstile_token'] ?? null, $request->ip())) return response()->json(['message' => 'Проверка безопасности не пройдена.', 'errors' => ['captcha_answer' => ['Обновите CAPTCHA и попробуйте снова.']]], 422);
        $otp->requestCode($data['email'], $data['name'], UserRole::Listener->value, $data['birth_date'], Hash::make($data['password']));
        return response()->json(['message' => 'Код подтверждения отправлен на вашу почту.', 'verification_required' => true, 'resend_token' => Crypt::encryptString(json_encode(['email' => strtolower(trim($data['email'])), 'expires_at' => now()->addMinutes(10)->timestamp], JSON_THROW_ON_ERROR)), 'expires_in' => (int) config('services.email_otp.ttl', 10) * 60, 'resend_after' => (int) config('services.email_otp.resend_seconds', 60)], 202);
    }

    private function validResendToken(?string $token, string $email): bool
    {
        if (! $token) return false;
        try {
            $payload = json_decode(Crypt::decryptString($token), true, 512, JSON_THROW_ON_ERROR);
            return is_array($payload)
                && is_string($payload['email'] ?? null)
                && hash_equals($payload['email'], strtolower(trim($email)))
                && is_int($payload['expires_at'] ?? null)
                && $payload['expires_at'] > now()->timestamp;
        } catch (\Illuminate\Contracts\Encryption\DecryptException | \JsonException $exception) {
            return false;
        }
    }

    public function verifyEmailCode(Request $request, EmailOtpService $otp): JsonResponse
    {
        $data = $request->validate(['email' => ['required', 'email', 'max:255'], 'code' => ['required', 'digits:6']]);
        $created = false;
        $user = $otp->verifyCode($data['email'], $data['code'], $created);
        return response()->json(['user' => (new UserResource($user))->resolve($request), 'token' => $user->createToken('email-otp')->plainTextToken, 'new_account' => $created], 201);
    }

    public function login(LoginRequest $request): JsonResponse
    {
        $user = User::where('email', $request->validated('email'))->first();
        if (! $user || ! Hash::check($request->validated('password'), $user->password)) return response()->json(['message' => 'Неверный email или пароль.'], 401);
        if ($user->is_blocked) return response()->json(['message' => 'Пользователь заблокирован.'], 403);
        return response()->json(['user' => (new UserResource($user))->resolve($request), 'token' => $user->createToken('api')->plainTextToken]);
    }
    public function uploadAvatar(Request $request): UserResource
    {
        $data = $request->validate(['avatar' => ['required', 'image', 'mimes:jpg,jpeg,png', 'max:5120']]);
        $path = $data['avatar']->store('avatars', 'public');
        if ($path === false) throw new \RuntimeException('Не удалось сохранить аватар.');
        $user = $request->user(); $previous = $user->avatar_path;
        try { $user->update(['avatar_path' => $path]); } catch (\Throwable $e) { Storage::disk('public')->delete($path); throw $e; }
        if ($previous && str_starts_with($previous, 'avatars/')) Storage::disk('public')->delete($previous);
        return new UserResource($user->refresh());
    }
    public function me(Request $request): UserResource { return new UserResource($request->user()); }
    public function updateProfile(UpdateProfileRequest $request): UserResource { $request->user()->update($request->validated()); return new UserResource($request->user()->refresh()); }
    public function logout(Request $request): JsonResponse { $request->user()->currentAccessToken()?->delete(); return response()->json(['message' => 'Выход выполнен.']); }
}
