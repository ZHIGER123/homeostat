<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class UserResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id, 'name' => $this->name,
            'email' => $this->when($request->user()?->is($this->resource) || $request->user()?->isAdmin(), $this->email),
            'role' => $this->role, 'plan' => $this->currentPlan(), 'bio' => $this->bio, 'avatar_path' => $this->avatar_path,
            'social_links' => $this->social_links ?? [],
            'is_blocked' => $this->is_blocked, 'created_at' => $this->created_at,
            'tracks' => TrackResource::collection($this->whenLoaded('tracks')),
            'playlists' => PlaylistResource::collection($this->whenLoaded('playlists')),
            'followers_count' => $this->whenCounted('followers'), 'following_count' => $this->whenCounted('following'),
        ];
    }
}
