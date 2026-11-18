<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\UserResource;
use App\Models\User;
use App\Services\UserService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class UserController extends Controller
{
    public function __construct(private readonly UserService $users) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        return UserResource::collection($this->users->search($request->string('q')->trim()->value()));
    }

    public function show(User $user): UserResource
    {
        abort_if($user->is_blocked, 404);
        return new UserResource($this->users->profile($user));
    }
}
