<?php

namespace App\Http\Controllers\Api;

use App\Enums\TrackStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\IndexTrackRequest;
use App\Http\Requests\StoreTrackRequest;
use App\Http\Requests\UpdateTrackRequest;
use App\Http\Resources\TrackCollection;
use App\Http\Resources\TrackResource;
use App\Models\Track;
use App\Services\TrackService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class TrackController extends Controller
{
    public function __construct(private readonly TrackService $tracks) {}

    /** GET /api/v1/tracks — каталог опубликованных треков (поиск, фильтр, сортировка). */
    public function index(IndexTrackRequest $request): TrackCollection
    {
        return new TrackCollection($this->tracks->catalog($request->validated()));
    }

    /** POST /api/v1/tracks — загрузка трека (создаётся со статусом pending). */
    public function store(StoreTrackRequest $request): JsonResponse
    {
        $track = $this->tracks->create(array_merge($request->validated(), ['user_id' => $request->user()->id]));

        return (new TrackResource($track))->response()->setStatusCode(Response::HTTP_CREATED);
    }

    /** GET /api/v1/tracks/{track} — карточка трека (при отсутствии записи — 404). */
    public function show(Request $request, Track $track): TrackResource
    {
        $user = $request->user('sanctum');
        abort_if($track->status !== TrackStatus::Published && ! ($user && ($user->isAdmin() || $track->user_id === $user->id)), 404);

        return new TrackResource($this->tracks->card($track));
    }

    /** PUT|PATCH /api/v1/tracks/{track} — изменение трека (повторная модерация). */
    public function update(UpdateTrackRequest $request, Track $track): TrackResource
    {
        Gate::authorize('update', $track);

        return new TrackResource($this->tracks->update($track, $request->validated()));
    }

    /** DELETE /api/v1/tracks/{track} — удаление трека. */
    public function destroy(Track $track): Response
    {
        Gate::authorize('delete', $track);
        $this->tracks->delete($track);

        return response()->noContent();
    }

    /** Потоковая отдача доступна только для опубликованных треков. */
    public function stream(Track $track): BinaryFileResponse
    {
        abort_unless($track->status === TrackStatus::Published && Storage::disk('local')->exists($track->audio_path), 404);

        return response()->file(Storage::disk('local')->path($track->audio_path));
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
