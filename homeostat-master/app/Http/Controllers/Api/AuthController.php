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
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;

class AuthController extends Controller
{
    public function register(RegisterRequest $request, CaptchaService $captcha): JsonResponse
    {
        $data = $request->validated();
        if (! $captcha->verify($data['captcha_id'] ?? null, $data['captcha_answer'] ?? null, $data['turnstile_token'] ?? null, $request->ip())) {
            return response()->json(['message' => 'Подтвердите, что вы не бот.', 'errors' => ['captcha_answer' => ['Проверка безопасности не пройдена. Обновите CAPTCHA и попробуйте снова.']]], 422);
        }
        $user = User::create(['name' => $data['name'], 'email' => $data['email'], 'password' => $data['password'], 'role' => $data['role'] ?? UserRole::Listener]);
        return response()->json(['user' => (new UserResource($user))->resolve($request), 'token' => $user->createToken('api')->plainTextToken], 201);
    }
    public function login(LoginRequest $request): JsonResponse
    {
        $user = User::where('email', $request->validated('email'))->first();
        if (! $user || ! Hash::check($request->validated('password'), $user->password)) return response()->json(['message' => 'Неверный email или пароль.'], 401);
        if ($user->is_blocked) return response()->json(['message' => 'Пользователь заблокирован.'], 403);
        return response()->json(['user' => (new UserResource($user))->resolve($request), 'token' => $user->createToken('api')->plainTextToken]);
    }
    public function me(Request $request): UserResource { return new UserResource($request->user()); }
    public function updateProfile(UpdateProfileRequest $request): UserResource { $request->user()->update($request->validated()); return new UserResource($request->user()->refresh()); }
    public function logout(Request $request): JsonResponse { $request->user()->currentAccessToken()?->delete(); return response()->json(['message' => 'Выход выполнен.']); }
}
