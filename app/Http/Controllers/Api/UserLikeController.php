<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\TrackResource;
use App\Models\Track;
use App\Models\User;
use App\Services\LikeService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;

/**
 * Вложенный ресурс: лайки пользователя (/users/{user}/likes/{track}).
 * Доступ к {user} проверяется политикой по токену.
 */
class UserLikeController extends Controller
{
    public function __construct(private readonly LikeService $likes) {}

    /** GET /api/v1/users/{user}/likes — понравившиеся треки. */
    public function index(User $user): AnonymousResourceCollection
    {
        Gate::authorize('access', $user);

        return TrackResource::collection($this->likes->likedTracks($user));
    }

    public function status(User $user, Track $track): JsonResponse
    {
        Gate::authorize('access', $user);
        return response()->json(['liked' => $user->likedTracks()->whereKey($track->id)->exists()]);
    }

    /** PUT /api/v1/users/{user}/likes/{track} — поставить лайк (201 — создан, 200 — уже был). */
    public function store(User $user, Track $track): JsonResponse
    {
        Gate::authorize('access', $user);
        $result = $this->likes->like($user, $track);

        return response()->json(
            ['liked' => true, 'likes_count' => $result['likes_count']],
            $result['created'] ? Response::HTTP_CREATED : Response::HTTP_OK,
        );
    }

    /** DELETE /api/v1/users/{user}/likes/{track} — снять лайк. */
    public function destroy(User $user, Track $track): Response
    {
        Gate::authorize('access', $user);
        $this->likes->unlike($user, $track);

        return response()->noContent();
    }
}
