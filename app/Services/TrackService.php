<?php

namespace App\Services;

use App\Enums\TrackStatus;
use App\Enums\UserRole;
use App\Models\Track;
use App\Models\User;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
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
        $artist = User::findOrFail($data['user_id']);

        if ($artist->is_blocked) {
            throw new AccessDeniedHttpException('Пользователь заблокирован.');
        }
        if ($artist->role !== UserRole::Artist) {
            throw new AccessDeniedHttpException('Загружать треки может только исполнитель.');
        }

        $pending = $artist->tracks()->where('status', TrackStatus::Pending)->count();
        if ($pending >= self::MAX_PENDING_PER_ARTIST) {
            throw new ConflictHttpException(
                'Превышен лимит треков на модерации ('.self::MAX_PENDING_PER_ARTIST.').'
            );
        }

        $track = DB::transaction(fn () => Track::create($data + [
            'status' => TrackStatus::Pending,
            'plays_count' => 0,
        ]));

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
        $track->fill($data);

        if ($track->isDirty()) {
            $track->status = TrackStatus::Pending;
            $track->reject_reason = null;
            $track->save();
        }

        return $this->card($track);
    }

    public function delete(Track $track): void
    {
        // Комментарии, лайки и записи плейлистов удаляются каскадом (FK ON DELETE CASCADE)
        $track->delete();
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
