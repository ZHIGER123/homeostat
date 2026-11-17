<?php

namespace App\Services;

use App\Models\Playlist;
use App\Models\Track;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/**
 * Плейлисты и их состав с сохранением порядка (ФТ-10).
 */
class PlaylistService
{
    /** @param array<string, mixed> $filters */
    public function paginate(array $filters): LengthAwarePaginator
    {
        return Playlist::query()
            ->with('owner')
            ->withCount('tracks')
            ->when($filters['user_id'] ?? null, fn ($query, $userId) => $query->where('user_id', $userId))
            ->latest()
            ->paginate($filters['per_page'] ?? 20)
            ->withQueryString();
    }

    /** Плейлист с владельцем и треками в порядке position. */
    public function details(Playlist $playlist): Playlist
    {
        return $playlist->load(['owner', 'tracks.artist', 'tracks.genre'])->loadCount('tracks');
    }

    /**
     * @param  array<string, mixed>  $data  user_id, title, description, track_ids (необязательно)
     */
    public function create(array $data): Playlist
    {
        return DB::transaction(function () use ($data) {
            $playlist = Playlist::create([
                'user_id' => $data['user_id'],
                'title' => $data['title'],
                'description' => $data['description'] ?? null,
            ]);

            $position = 1;
            foreach ($data['track_ids'] ?? [] as $trackId) {
                $playlist->tracks()->attach($trackId, ['position' => $position++]);
            }

            return $this->details($playlist);
        });
    }

    /** @param array<string, mixed> $data */
    public function update(Playlist $playlist, array $data): Playlist
    {
        $playlist->update($data);

        return $this->details($playlist);
    }

    public function delete(Playlist $playlist): void
    {
        // Записи playlist_track удаляются каскадом
        $playlist->delete();
    }

    /** Добавление трека в конец плейлиста. */
    public function addTrack(Playlist $playlist, int $trackId): Playlist
    {
        return DB::transaction(function () use ($playlist, $trackId) {
            $track = Track::findOrFail($trackId);

            if ($playlist->tracks()->whereKey($track->id)->exists()) {
                throw new ConflictHttpException('Трек уже есть в плейлисте.');
            }

            $position = (int) DB::table('playlist_track')
                ->where('playlist_id', $playlist->id)
                ->max('position') + 1;

            $playlist->tracks()->attach($track->id, ['position' => $position]);

            return $this->details($playlist);
        });
    }

    /** Удаление трека; оставшиеся треки получают подряд идущие позиции. */
    public function removeTrack(Playlist $playlist, Track $track): void
    {
        DB::transaction(function () use ($playlist, $track) {
            $playlist->tracks()->detach($track->id);
            $this->renumber($playlist, $playlist->tracks()->pluck('tracks.id')->all());
        });
    }

    /**
     * Изменение порядка: список должен содержать ровно те же треки, что и плейлист.
     *
     * @param  list<int>  $trackIds
     */
    public function reorder(Playlist $playlist, array $trackIds): Playlist
    {
        $current = $playlist->tracks()->pluck('tracks.id')->all();

        $given = array_map('intval', $trackIds);
        sort($given);
        sort($current);

        if ($given !== $current) {
            throw ValidationException::withMessages([
                'track_ids' => 'Список должен содержать все треки плейлиста без лишних.',
            ]);
        }

        DB::transaction(fn () => $this->renumber($playlist, array_map('intval', $trackIds)));

        return $this->details($playlist->refresh());
    }

    /** @param list<int> $orderedTrackIds */
    private function renumber(Playlist $playlist, array $orderedTrackIds): void
    {
        foreach ($orderedTrackIds as $index => $trackId) {
            $playlist->tracks()->updateExistingPivot($trackId, ['position' => $index + 1]);
        }
    }
}
