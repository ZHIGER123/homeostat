<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\TrackStatsRequest;
use App\Models\Track;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

class TrackStatsController extends Controller
{
    public function index(TrackStatsRequest $request): JsonResponse
    {
        $query = Track::query()->where('user_id', $request->user()->id)
            ->when($request->validated('from'), fn ($q, $date) => $q->whereDate('created_at', '>=', $date))
            ->when($request->validated('to'), fn ($q, $date) => $q->whereDate('created_at', '<=', $date));
        $statuses = (clone $query)->select('status', DB::raw('count(*) as total'))->groupBy('status')->pluck('total', 'status');
        $tracks = (clone $query)->withCount(['likes', 'comments'])->get();

        return response()->json(['data' => [
            'tracks' => $tracks->count(),
            'by_status' => $statuses,
            'plays' => $tracks->sum('plays_count'),
            'likes' => $tracks->sum('likes_count'),
            'comments' => $tracks->sum('comments_count'),
            'period_basis' => 'track_created_at',
        ]]);
    }
}
