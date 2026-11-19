<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class TrackResource extends JsonResource
{
    /**
     * Путь к аудиофайлу (audio_path) намеренно не отдаётся: файл доступен
     * только через потоковый маршрут (ТЗ, п. 5).
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'title' => $this->title,
            'description' => $this->description,
            'cover_path' => $this->cover_path,
            'duration' => $this->duration,
            'plays_count' => $this->plays_count,
            'is_spotlight' => $this->is_spotlight, 'is_boosted' => $this->is_boosted, 'comments_enabled' => $this->comments_enabled,
            'status' => $this->status,
            'reject_reason' => $this->reject_reason,
            'created_at' => $this->created_at,
            'artist' => new UserResource($this->whenLoaded('artist')),
            'genre' => new GenreResource($this->whenLoaded('genre')),
            'comments' => CommentResource::collection($this->whenLoaded('comments')),
            'likes_count' => $this->whenCounted('likes'),
            'comments_count' => $this->whenCounted('comments'),
            // Позиция трека, когда ресурс отдаётся в составе плейлиста
            'position' => $this->whenPivotLoaded('playlist_track', fn () => $this->pivot->position),
        ];
    }
}
