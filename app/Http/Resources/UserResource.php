<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class UserResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'email' => $this->when($request->user()?->is($this->resource) || $request->user()?->isAdmin(), $this->email),
            'role' => $this->role,
            'plan' => $this->currentPlan(),
            'bio' => $this->bio,
            'avatar_path' => $this->avatar_path,
            'is_blocked' => $this->is_blocked,
            'created_at' => $this->created_at,
            'tracks' => TrackResource::collection($this->whenLoaded('tracks')),
            'liked_tracks' => TrackResource::collection($this->whenLoaded('likedTracks')),
            'playlists' => PlaylistResource::collection($this->whenLoaded('playlists')),
            'followers_count' => $this->whenCounted('followers'),
            'following_count' => $this->whenCounted('following'),
            'tracks_count' => $this->whenCounted('tracks'),
        ];
    }
}
