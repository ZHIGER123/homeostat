<?php

namespace App\Http\Controllers\Api\Admin;

use App\Enums\TrackStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\ModerateTrackRequest;
use App\Http\Requests\ModerationQueueRequest;
use App\Http\Resources\TrackResource;
use App\Models\Track;
use App\Services\TrackModerationService;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class TrackModerationController extends Controller
{
    public function __construct(private readonly TrackModerationService $moderation) {}

    /** GET /api/v1/admin/tracks?status=pending — очередь модерации. */
    public function index(ModerationQueueRequest $request): AnonymousResourceCollection
    {
        $status = $request->enum('status', TrackStatus::class);

        return TrackResource::collection(
            $this->moderation->queue($status, $request->integer('per_page', 20))
        );
    }

    /** PATCH /api/v1/admin/tracks/{track}/status — одобрить или отклонить трек. */
    public function updateStatus(ModerateTrackRequest $request, Track $track): TrackResource
    {
        $track = $this->moderation->decide(
            $track,
            TrackStatus::from($request->validated('status')),
            $request->validated('reason'),
        );

        return new TrackResource($track);
    }
}
