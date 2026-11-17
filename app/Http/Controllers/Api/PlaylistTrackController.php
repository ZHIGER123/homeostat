<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\AddPlaylistTrackRequest;
use App\Http\Requests\ReorderPlaylistTracksRequest;
use App\Http\Resources\PlaylistResource;
use App\Models\Playlist;
use App\Models\Track;
use App\Services\PlaylistService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;

/**
 * Вложенный ресурс: состав плейлиста (/playlists/{playlist}/tracks).
 */
class PlaylistTrackController extends Controller
{
    public function __construct(private readonly PlaylistService $playlists) {}

    /** POST /api/v1/playlists/{playlist}/tracks — добавить трек в конец плейлиста. */
    public function store(AddPlaylistTrackRequest $request, Playlist $playlist): JsonResponse
    {
        $playlist = $this->playlists->addTrack($playlist, $request->integer('track_id'));

        return (new PlaylistResource($playlist))->response()->setStatusCode(Response::HTTP_CREATED);
    }

    /** PUT /api/v1/playlists/{playlist}/tracks — задать новый порядок треков. */
    public function reorder(ReorderPlaylistTracksRequest $request, Playlist $playlist): PlaylistResource
    {
        return new PlaylistResource(
            $this->playlists->reorder($playlist, $request->validated('track_ids'))
        );
    }

    /** DELETE /api/v1/playlists/{playlist}/tracks/{track} — убрать трек (404, если его нет в плейлисте). */
    public function destroy(Playlist $playlist, Track $track): Response
    {
        $this->playlists->removeTrack($playlist, $track);

        return response()->noContent();
    }
}
