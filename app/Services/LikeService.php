<?php

namespace App\Services;

use App\Enums\TrackStatus;
use App\Models\Like;
use App\Models\Track;
use App\Models\User;
use Illuminate\Pagination\LengthAwarePaginator;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class LikeService
{
    public function like(User $user, Track $track): array
    {
        if ($user->is_blocked) throw new AccessDeniedHttpException('Пользователь заблокирован.');
        if ($track->status !== TrackStatus::Published) throw new NotFoundHttpException('Трек недоступен.');
        $like = Like::firstOrCreate(['user_id' => $user->id, 'track_id' => $track->id]);
        return ['created' => $like->wasRecentlyCreated, 'likes_count' => $track->likes()->count()];
    }

    public function unlike(User $user, Track $track): array
    {
        Like::where('user_id', $user->id)->where('track_id', $track->id)->delete();
        return ['likes_count' => $track->likes()->count()];
    }

    public function likedTracks(User $user, int $perPage = 20): LengthAwarePaginator
    {
        return $user->likedTracks()->published()->with(['artist', 'genre'])->withCount(['likes', 'comments'])->orderByPivot('created_at', 'desc')->paginate($perPage);
    }
}
