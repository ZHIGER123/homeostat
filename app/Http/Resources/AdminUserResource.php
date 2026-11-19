<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** Only returned by role:admin protected routes. Never serialize credentials. */
class AdminUserResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id, 'name' => $this->name, 'email' => $this->email,
            'role' => $this->role, 'bio' => $this->bio, 'is_blocked' => $this->is_blocked,
            'birth_date' => $this->birth_date?->format('Y-m-d'),
            'age_confirmed_at' => $this->age_confirmed_at?->toIso8601String(),
            'email_verified_at' => $this->email_verified_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
            'tracks_count' => $this->whenCounted('tracks'),
            'playlists_count' => $this->whenCounted('playlists'),
            'followers_count' => $this->whenCounted('followers'),
        ];
    }
}
