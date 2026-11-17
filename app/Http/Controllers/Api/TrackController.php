<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\IndexTrackRequest;
use App\Http\Requests\StoreTrackRequest;
use App\Http\Requests\UpdateTrackRequest;
use App\Http\Resources\TrackResource;
use App\Models\Track;
use App\Services\TrackService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;

class TrackController extends Controller
{
    public function __construct(private readonly TrackService $tracks) {}

    /** GET /api/v1/tracks — каталог опубликованных треков (поиск, фильтр, сортировка). */
    public function index(IndexTrackRequest $request): AnonymousResourceCollection
    {
        return TrackResource::collection($this->tracks->catalog($request->validated()));
    }

    /** POST /api/v1/tracks — загрузка трека (создаётся со статусом pending). */
    public function store(StoreTrackRequest $request): JsonResponse
    {
        $track = $this->tracks->create($request->validated());

        return (new TrackResource($track))->response()->setStatusCode(Response::HTTP_CREATED);
    }

    /** GET /api/v1/tracks/{track} — карточка трека (при отсутствии записи — 404). */
    public function show(Track $track): TrackResource
    {
        return new TrackResource($this->tracks->card($track));
    }

    /** PUT|PATCH /api/v1/tracks/{track} — изменение трека (повторная модерация). */
    public function update(UpdateTrackRequest $request, Track $track): TrackResource
    {
        return new TrackResource($this->tracks->update($track, $request->validated()));
    }

    /** DELETE /api/v1/tracks/{track} — удаление трека. */
    public function destroy(Track $track): Response
    {
        $this->tracks->delete($track);

        return response()->noContent();
    }

    /** POST /api/v1/tracks/{track}/play — учёт прослушивания (не чаще 1 раза в 30 с). */
    public function play(Request $request, Track $track): JsonResponse
    {
        $clientKey = (string) ($request->user()?->getKey() ?? $request->ip());

        $counted = $this->tracks->registerPlay($track, $clientKey);

        return response()->json([
            'counted' => $counted,
            'plays_count' => $track->plays_count,
        ]);
    }
}
