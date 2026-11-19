<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\UserResource;
use App\Models\User;
use App\Services\UserService;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class UserController extends Controller
{
    public function __construct(private readonly UserService $users) {}

    public function avatar(User $user): BinaryFileResponse
    {
        $path = $user->avatar_path;
        abort_unless($path && str_starts_with($path, 'avatars/') && Storage::disk('public')->exists($path), 404);
        return response()->file(Storage::disk('public')->path($path));
    }

    /** GET /api/v1/users/{user} — публичный профиль (счётчики подписчиков и подписок). */
    public function show(User $user): UserResource
    {
        return new UserResource($this->users->profile($user));
    }
}
