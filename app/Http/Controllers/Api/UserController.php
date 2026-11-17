<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\UserResource;
use App\Models\User;
use App\Services\UserService;

class UserController extends Controller
{
    public function __construct(private readonly UserService $users) {}

    /** GET /api/v1/users/{user} — публичный профиль (счётчики подписчиков и подписок). */
    public function show(User $user): UserResource
    {
        return new UserResource($this->users->profile($user));
    }
}
