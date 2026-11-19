<?php

namespace App\Services;

use App\Enums\TrackStatus;
use App\Exceptions\BusinessException;
use App\Models\Track;
use App\Models\User;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class TrackService
{
    public const MAX_PENDING_PER_ARTIST = 10;
    public const PLAY_THROTTLE_SECONDS = 30;

    public function __construct(private readonly UploadQuotaService $quota) {}

    public function catalog(array $filters): LengthAwarePaginator
    {
        $sort = $filters['sort'] ?? 'created_at'; $direction = $filters['direction'] ?? 'desc';
        return Track::query()->published()->with(['artist', 'genre'])->withCount(['likes', 'comments'])
            ->when($filters['q'] ?? null, function ($query, string $term) { $query->where(fn ($query) => $query->where('title', 'like', "%{$term}%")->orWhereHas('artist', fn ($artist) => $artist->where('name', 'like', "%{$term}%"))); })
            ->when($filters['genre_id'] ?? null, fn ($query, $genreId) => $query->where('genre_id', $genreId))
            ->when(isset($filters['min_plays']), fn ($query) => $query->where('plays_count', '>=', $filters['min_plays']))
            ->orderBy($sort, $direction)->orderByDesc('id')->paginate($filters['per_page'] ?? 20)->withQueryString();
    }

    public function card(Track $track): Track { return $track->load(['artist', 'genre'])->loadCount(['likes', 'comments']); }

    public function create(array $data): Track
    {
        $stored = [];
        try {
            $track = DB::transaction(function () use ($data, &$stored) {
                $artist = User::whereKey($data['user_id'])->lockForUpdate()->firstOrFail();
                if ($artist->is_blocked) throw new AccessDeniedHttpException('Заблокированный пользователь не может загружать треки.');
                $this->quota->assertCanUpload($artist, (int) $data['duration']);
                $pendingLimit = config('plans.'.$artist->currentPlan().'.max_pending', self::MAX_PENDING_PER_ARTIST);
                if ($pendingLimit !== null && $artist->tracks()->where('status', TrackStatus::Pending)->count() >= $pendingLimit) throw new BusinessException('Превышен лимит треков на модерации.');
                $audio = $data['audio']->store('tracks', 'local');
                if ($audio === false) throw new \RuntimeException('Не удалось сохранить аудиофайл.');
                $stored[] = ['local', $audio]; $cover = null;
                if (isset($data['cover'])) { $cover = $data['cover']->store('covers', 'public'); if ($cover === false) throw new \RuntimeException('Не удалось сохранить обложку.'); $stored[] = ['public', $cover]; }
                return Track::create(['user_id' => $artist->id, 'genre_id' => $data['genre_id'], 'title' => $data['title'], 'description' => $data['description'] ?? null, 'duration' => $data['duration'], 'audio_path' => $audio, 'cover_path' => $cover, 'status' => TrackStatus::Pending, 'plays_count' => 0]);
            });
        } catch (\Throwable $exception) { foreach ($stored as [$disk, $path]) Storage::disk($disk)->delete($path); throw $exception; }
        Log::info('track.created', ['track_id' => $track->id, 'user_id' => $track->user_id, 'status' => $track->status->value, 'plan' => User::find($track->user_id)?->currentPlan()]);
        return $this->card($track);
    }

    public function update(Track $track, array $data): Track
    {
        $updated = DB::transaction(function () use ($track, $data) {
            $artist = User::whereKey($track->user_id)->lockForUpdate()->firstOrFail(); $locked = Track::whereKey($track->id)->lockForUpdate()->firstOrFail(); $oldDuration = (int) $locked->duration; $locked->fill($data);
            if ($locked->isDirty()) {
                if ((int) $locked->duration > $oldDuration) $this->quota->assertCanUpload($artist, (int) $locked->duration - $oldDuration);
                if ($locked->status !== TrackStatus::Pending) { $pendingLimit = config('plans.'.$artist->currentPlan().'.max_pending', self::MAX_PENDING_PER_ARTIST); if ($pendingLimit !== null && $artist->tracks()->where('status', TrackStatus::Pending)->count() >= $pendingLimit) throw new BusinessException('Превышен лимит треков на модерации.'); }
                $locked->status = TrackStatus::Pending; $locked->reject_reason = null; $locked->save();
            }
            return $locked;
        });
        return $this->card($updated);
    }

    public function delete(Track $track): void { $audio = $track->audio_path; $cover = $track->cover_path; $track->delete(); Storage::disk('local')->delete($audio); if ($cover) Storage::disk('public')->delete($cover); }

    public function registerPlay(Track $track, string $clientKey): bool
    {
        if ($track->status !== TrackStatus::Published) throw new NotFoundHttpException('Трек недоступен для прослушивания.');
        $key = sprintf('track-play:%d:%s', $track->id, $clientKey);
        if (! Cache::add($key, true, now()->addSeconds(self::PLAY_THROTTLE_SECONDS))) return false;
        $track->increment('plays_count');
        $track->refresh();
        return true;
    }
}
