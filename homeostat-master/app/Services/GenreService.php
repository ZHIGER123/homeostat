<?php

namespace App\Services;

use App\Enums\TrackStatus;
use App\Models\Genre;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/**
 * Справочник жанров: чтение, создание, изменение, удаление.
 */
class GenreService
{
    /** @return Collection<int, Genre> */
    public function all(): Collection
    {
        return $this->withPublishedCount(Genre::query())->orderBy('name')->get();
    }

    public function find(Genre $genre): Genre
    {
        return $genre->loadCount(['tracks' => fn ($q) => $q->where('status', TrackStatus::Published)]);
    }

    /** @param array<string, mixed> $data */
    public function create(array $data): Genre
    {
        return Genre::create($data);
    }

    /** @param array<string, mixed> $data */
    public function update(Genre $genre, array $data): Genre
    {
        $genre->update($data);

        return $genre->refresh();
    }

    /**
     * Внешний ключ tracks.genre_id — RESTRICT, поэтому жанр с треками удалить нельзя.
     */
    public function delete(Genre $genre): void
    {
        if ($genre->tracks()->exists()) {
            throw new ConflictHttpException('Нельзя удалить жанр, к которому привязаны треки.');
        }

        $genre->delete();
    }

    private function withPublishedCount(Builder $query): Builder
    {
        return $query->withCount(['tracks' => fn ($q) => $q->where('status', TrackStatus::Published)]);
    }
}
