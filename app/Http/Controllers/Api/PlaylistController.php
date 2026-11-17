<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\IndexPlaylistRequest;
use App\Http\Requests\StorePlaylistRequest;
use App\Http\Requests\UpdatePlaylistRequest;
use App\Http\Resources\PlaylistResource;
use App\Models\Playlist;
use App\Services\PlaylistService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;

class PlaylistController extends Controller
{
    public function __construct(private readonly PlaylistService $playlists) {}

    /** GET /api/v1/playlists?user_id= — список плейлистов. */
    public function index(IndexPlaylistRequest $request): AnonymousResourceCollection
    {
        return PlaylistResource::collection($this->playlists->paginate($request->validated()));
    }

    /** POST /api/v1/playlists — создание плейлиста. */
    public function store(StorePlaylistRequest $request): JsonResponse
    {
        $playlist = $this->playlists->create($request->validated());

        return (new PlaylistResource($playlist))->response()->setStatusCode(Response::HTTP_CREATED);
    }

    /** GET /api/v1/playlists/{playlist} — плейлист с треками в порядке position. */
    public function show(Playlist $playlist): PlaylistResource
    {
        return new PlaylistResource($this->playlists->details($playlist));
    }

    /** PUT|PATCH /api/v1/playlists/{playlist} — переименование и описание. */
    public function update(UpdatePlaylistRequest $request, Playlist $playlist): PlaylistResource
    {
        return new PlaylistResource($this->playlists->update($playlist, $request->validated()));
    }

    /** DELETE /api/v1/playlists/{playlist} */
    public function destroy(Playlist $playlist): Response
    {
        $this->playlists->delete($playlist);

        return response()->noContent();
    }
}
