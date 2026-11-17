<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreCommentRequest;
use App\Http\Resources\CommentResource;
use App\Models\Track;
use App\Services\CommentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;

/**
 * Вложенный ресурс: комментарии трека (/tracks/{track}/comments).
 */
class TrackCommentController extends Controller
{
    public function __construct(private readonly CommentService $comments) {}

    /** GET /api/v1/tracks/{track}/comments */
    public function index(Track $track): AnonymousResourceCollection
    {
        return CommentResource::collection($this->comments->forTrack($track));
    }

    /** POST /api/v1/tracks/{track}/comments */
    public function store(StoreCommentRequest $request, Track $track): JsonResponse
    {
        $comment = $this->comments->create($track, $request->validated());

        return (new CommentResource($comment))->response()->setStatusCode(Response::HTTP_CREATED);
    }
}
