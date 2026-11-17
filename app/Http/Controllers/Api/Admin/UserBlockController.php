<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Http\Resources\UserResource;
use App\Models\User;
use App\Services\UserService;
use Illuminate\Http\Response;

class UserBlockController extends Controller
{
    public function __construct(private readonly UserService $users) {}

    /** PUT /api/v1/admin/users/{user}/block — заблокировать пользователя. */
    public function store(User $user): UserResource
    {
        return new UserResource($this->users->block($user));
    }

    /** DELETE /api/v1/admin/users/{user}/block — снять блокировку. */
    public function destroy(User $user): Response
    {
        $this->users->unblock($user);

        return response()->noContent();
    }
}
