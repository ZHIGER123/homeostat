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

class UserLikeController extends Controller
{
    public function __construct(private readonly LikeService $likes) {}

    public function index(User $user): AnonymousResourceCollection
    {
        Gate::authorize('access', $user);
        return TrackResource::collection($this->likes->likedTracks($user));
    }

    public function store(User $user, Track $track): JsonResponse
    {
        Gate::authorize('access', $user);
        $result = $this->likes->like($user, $track);
        return response()->json(['liked' => true, 'likes_count' => $result['likes_count']], $result['created'] ? Response::HTTP_CREATED : Response::HTTP_OK);
    }

    public function destroy(User $user, Track $track): JsonResponse
    {
        Gate::authorize('access', $user);
        $result = $this->likes->unlike($user, $track);
        return response()->json(['liked' => false, 'likes_count' => $result['likes_count']]);
    }
}
