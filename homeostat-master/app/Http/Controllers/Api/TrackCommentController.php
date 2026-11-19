<?php

namespace App\Http\Controllers\Api;

use App\Enums\TrackStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreCommentRequest;
use App\Http\Resources\CommentResource;
use App\Models\Track;
use App\Services\CommentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;

/**
 * Вложенный ресурс: комментарии трека (/tracks/{track}/comments).
 */
class TrackCommentController extends Controller
{
    public function __construct(private readonly CommentService $comments) {}

    /** GET /api/v1/tracks/{track}/comments */
    public function index(Request $request, Track $track): AnonymousResourceCollection
    {
        $user = $request->user('sanctum');
        abort_if($track->status !== TrackStatus::Published && ! ($user && ($user->isAdmin() || $track->user_id === $user->id)), 404);

        return CommentResource::collection($this->comments->forTrack($track));
    }

    /** POST /api/v1/tracks/{track}/comments */
    public function store(StoreCommentRequest $request, Track $track): JsonResponse
    {
        $comment = $this->comments->create($track, array_merge($request->validated(), ['user_id' => $request->user()->id]));

        return (new CommentResource($comment))->response()->setStatusCode(Response::HTTP_CREATED);
    }
}
