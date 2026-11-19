<?php
namespace App\Services;

use App\Enums\TrackStatus;
use App\Models\Track;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class ArtistFeatureService
{
    private function ownedLocked(User $user, Track $track): array
    {
        $owner = User::whereKey($user->id)->lockForUpdate()->firstOrFail();
        $locked = Track::whereKey($track->id)->lockForUpdate()->firstOrFail();
        abort_unless($locked->user_id === $owner->id, 403, 'Управлять можно только собственным треком.');
        return [$owner, $locked];
    }
    public function spotlight(User $user, Track $track, bool $enabled): Track
    {
        return DB::transaction(function () use ($user, $track, $enabled) {
            [$owner, $locked] = $this->ownedLocked($user, $track);
            if ($enabled && ! $locked->is_spotlight) {
                abort_unless($locked->status === TrackStatus::Published, 409, 'Закрепить можно только опубликованный трек.');
                $limit = $owner->currentPlan() === 'free' ? 1 : 5;
                abort_if($owner->tracks()->where('is_spotlight', true)->count() >= $limit, 409, 'Достигнут лимит закреплённых треков.');
            }
            $locked->forceFill(['is_spotlight' => $enabled])->save(); return $locked;
        });
    }
    public function boost(User $user, Track $track, bool $enabled): Track
    {
        return DB::transaction(function () use ($user, $track, $enabled) {
            [$owner, $locked] = $this->ownedLocked($user, $track); $plan = $owner->currentPlan();
            if ($enabled && ! $locked->is_boosted) {
                abort_unless($locked->status === TrackStatus::Published, 409, 'Продвигать можно только опубликованный трек.');
                abort_if(! in_array($plan, ['artist', 'artist_pro'], true), 403, 'Буст доступен на тарифах Artist и Artist Pro.');
                $used = DB::table('artist_feature_usage')->where('user_id', $owner->id)->where('feature', 'boost')->where('created_at', '>=', now()->startOfMonth())->count();
                abort_if($plan === 'artist' && $used >= 2, 409, 'Вы использовали 2 буста в текущем месяце.');
                DB::table('artist_feature_usage')->insert(['user_id'=>$owner->id, 'track_id'=>$locked->id, 'feature'=>'boost', 'created_at'=>now()]);
            }
            $locked->forceFill(['is_boosted' => $enabled])->save(); return $locked;
        });
    }
    public function comments(User $user, Track $track, bool $enabled): Track
    {
        return DB::transaction(function () use ($user, $track, $enabled) {
            [$owner, $locked] = $this->ownedLocked($user, $track);
            if (! $enabled) abort_unless(in_array($owner->currentPlan(), ['artist', 'artist_pro'], true), 403, 'Отключение комментариев доступно на тарифах Artist и Artist Pro.');
            $locked->forceFill(['comments_enabled' => $enabled])->save(); return $locked;
        });
    }
}
