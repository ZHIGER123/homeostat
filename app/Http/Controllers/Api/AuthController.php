<?php

namespace App\Http\Controllers\Api;

use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Http\Requests\LoginRequest;
use App\Http\Requests\RegisterRequest;
use App\Http\Requests\UpdateProfileRequest;
use App\Http\Resources\UserResource;
use App\Models\User;
use App\Services\CaptchaService;
use App\Services\EmailOtpService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;

class AuthController extends Controller
{
    public function register(RegisterRequest $request, CaptchaService $captcha): JsonResponse
    {
        $data = $request->validated();
        if (! $captcha->verify($data['captcha_id'] ?? null, $data['captcha_answer'] ?? null, $data['turnstile_token'] ?? null, $request->ip())) return response()->json(['message' => 'Подтвердите, что вы не бот.', 'errors' => ['captcha_answer' => ['Проверка безопасности не пройдена. Обновите CAPTCHA и попробуйте снова.']]], 422);
        $user = User::create(['name' => $data['name'], 'email' => $data['email'], 'password' => $data['password'], 'role' => UserRole::Listener]);
        return response()->json(['user' => (new UserResource($user))->resolve($request), 'token' => $user->createToken('api')->plainTextToken], 201);
    }

    public function requestEmailCode(Request $request, CaptchaService $captcha, EmailOtpService $otp): JsonResponse
    {
        $data = $request->validate(['name' => ['required', 'string', 'max:255'], 'email' => ['required', 'email', 'max:255'], 'role' => ['sometimes', Rule::in(['listener', 'artist'])], 'captcha_id' => ['nullable', 'string', 'max:80'], 'captcha_answer' => ['nullable', 'string', 'max:20'], 'turnstile_token' => ['nullable', 'string', 'max:2048']]);
        if (! $captcha->verify($data['captcha_id'] ?? null, $data['captcha_answer'] ?? null, $data['turnstile_token'] ?? null, $request->ip())) return response()->json(['message' => 'Проверка безопасности не пройдена.', 'errors' => ['captcha_answer' => ['Обновите CAPTCHA и попробуйте снова.']]], 422);
        $otp->requestCode($data['email'], $data['name'], UserRole::Listener->value);
        return response()->json(['message' => 'Если адрес допустим, письмо с кодом отправлено.'], 202);
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
