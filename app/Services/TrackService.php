<?php

namespace App\Services;

use App\Enums\TrackStatus;
use App\Enums\UserRole;
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

/**
 * Бизнес-логика треков: каталог, загрузка, изменение, удаление, прослушивания.
 */
class TrackService
{
    /** Максимум треков одного артиста в статусе pending (ТЗ, п. 5). */
    public const MAX_PENDING_PER_ARTIST = 10;

    /** Не чаще одного учтённого прослушивания от одного клиента за N секунд (ФТ-7). */
    public const PLAY_THROTTLE_SECONDS = 30;

    /**
     * Каталог опубликованных треков: поиск по названию и имени артиста,
     * фильтр по жанру, сортировка, пагинация (ФТ-5, ФТ-6).
     *
     * @param  array<string, mixed>  $filters
     */
    public function catalog(array $filters): LengthAwarePaginator
    {
        $sort = $filters['sort'] ?? 'created_at';
        $direction = $filters['direction'] ?? 'desc';

        return Track::query()
            ->published()
            ->with(['artist', 'genre'])
            ->withCount(['likes', 'comments'])
            ->when($filters['q'] ?? null, function ($query, string $term) {
                $query->where(function ($query) use ($term) {
                    $query->where('title', 'like', "%{$term}%")
                        ->orWhereHas('artist', fn ($artist) => $artist->where('name', 'like', "%{$term}%"));
                });
            })
            ->when($filters['genre_id'] ?? null, fn ($query, $genreId) => $query->where('genre_id', $genreId))
            ->when(isset($filters['min_plays']), fn ($query) => $query->where('plays_count', '>=', $filters['min_plays']))
            ->orderBy($sort, $direction)
            ->orderByDesc('id')
            ->paginate($filters['per_page'] ?? 20)
            ->withQueryString();
    }

    /** Карточка трека с исполнителем, жанром и счётчиками. */
    public function card(Track $track): Track
    {
        return $track->load(['artist', 'genre'])->loadCount(['likes', 'comments']);
    }

    /**
     * Загрузка трека артистом: статус всегда pending, лимит треков на модерации.
     *
     * @param  array<string, mixed>  $data
     */
    public function create(array $data): Track
    {
        $stored = [];
        try {
            $track = DB::transaction(function () use ($data, &$stored) {
                // Блокировка строки артиста сериализует проверку лимита и создание.
                $artist = User::whereKey($data['user_id'])->lockForUpdate()->firstOrFail();
                if ($artist->is_blocked || $artist->role !== UserRole::Artist) {
                    throw new AccessDeniedHttpException('Загружать треки может только незаблокированный исполнитель.');
                }
                if ($artist->tracks()->where('status', TrackStatus::Pending)->count() >= self::MAX_PENDING_PER_ARTIST) {
                    throw new BusinessException('Превышен лимит треков на модерации ('.self::MAX_PENDING_PER_ARTIST.').');
                }
                $audio = $data['audio']->store('tracks', 'local');
                if ($audio === false) {
                    throw new \RuntimeException('Не удалось сохранить аудиофайл.');
                }
                $stored[] = ['local', $audio];
                $cover = null;
                if (isset($data['cover'])) {
                    $cover = $data['cover']->store('covers', 'public');
                    if ($cover === false) {
                        throw new \RuntimeException('Не удалось сохранить обложку.');
                    }
                    $stored[] = ['public', $cover];
                }

                return Track::create([
                    'user_id' => $artist->id, 'genre_id' => $data['genre_id'], 'title' => $data['title'],
                    'description' => $data['description'] ?? null, 'duration' => $data['duration'],
                    'audio_path' => $audio, 'cover_path' => $cover,
                    'status' => TrackStatus::Pending, 'plays_count' => 0,
                ]);
            });
        } catch (\Throwable $exception) {
            foreach ($stored as [$disk, $path]) {
                Storage::disk($disk)->delete($path);
            }
            throw $exception;
        }

        Log::info('track.created', [
            'track_id' => $track->id,
            'user_id' => $track->user_id,
            'status' => $track->status->value,
        ]);

        return $this->card($track);
    }

    /**
     * Изменение трека. Если данные реально изменились, опубликованный или
     * отклонённый трек повторно уходит на модерацию (ФТ-4).
     *
     * @param  array<string, mixed>  $data
     */
    public function update(Track $track, array $data): Track
    {
        $updated = DB::transaction(function () use ($track, $data) {
            $artist = User::whereKey($track->user_id)->lockForUpdate()->firstOrFail();
            $locked = Track::whereKey($track->id)->lockForUpdate()->firstOrFail();
            $locked->fill($data);
            if ($locked->isDirty()) {
                if ($locked->status !== TrackStatus::Pending) {
                    if ($artist->tracks()->where('status', TrackStatus::Pending)->count() >= self::MAX_PENDING_PER_ARTIST) {
                        throw new BusinessException('Превышен лимит треков на модерации ('.self::MAX_PENDING_PER_ARTIST.').');
                    }
                    if (! $locked->status->canTransitionTo(TrackStatus::Pending)) {
                        throw new BusinessException('Недопустимый переход статуса.');
                    }
                }
                $locked->status = TrackStatus::Pending;
                $locked->reject_reason = null;
                $locked->save();
            }

            return $locked;
        });

        return $this->card($updated);
    }

    public function delete(Track $track): void
    {
        // Комментарии, лайки и записи плейлистов удаляются каскадом.
        $audio = $track->audio_path;
        $cover = $track->cover_path;
        $track->delete();
        Storage::disk('local')->delete($audio);
        if ($cover) {
            Storage::disk('public')->delete($cover);
        }
    }

    /**
     * Учёт прослушивания: не чаще одного раза за 30 секунд от одного
     * пользователя/IP на трек. Возвращает false, если прослушивание не засчитано.
     */
    public function registerPlay(Track $track, string $clientKey): bool
    {
        if ($track->status !== TrackStatus::Published) {
            throw new NotFoundHttpException('Трек недоступен для прослушивания.');
        }

        $key = sprintf('track-play:%d:%s', $track->id, $clientKey);

        if (! Cache::add($key, true, now()->addSeconds(self::PLAY_THROTTLE_SECONDS))) {
            return false;
        }

        $track->increment('plays_count');

        return true;
    }
}

