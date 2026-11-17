<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\TrackResource;
use App\Models\User;
use App\Services\FollowService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;

/**
 * Подписки пользователя (/users/{user}/following/{artist}) и лента (/users/{user}/feed).
 */
class UserFollowController extends Controller
{
    public function __construct(private readonly FollowService $follows) {}

    /** PUT /api/v1/users/{user}/following/{artist} — подписаться (201 — создана, 200 — уже была). */
    public function store(User $user, User $artist): JsonResponse
    {
        $created = $this->follows->follow($user, $artist);

        return response()->json(
            ['following' => true],
            $created ? Response::HTTP_CREATED : Response::HTTP_OK,
        );
    }

    /** DELETE /api/v1/users/{user}/following/{artist} — отписаться. */
    public function destroy(User $user, User $artist): Response
    {
        $this->follows->unfollow($user, $artist);

        return response()->noContent();
    }

    /** GET /api/v1/users/{user}/feed — новые треки подписок. */
    public function feed(User $user): AnonymousResourceCollection
    {
        return TrackResource::collection($this->follows->feed($user));
    }
}
