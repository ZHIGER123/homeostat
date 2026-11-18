<?php

namespace App\Services;

use App\Enums\TrackStatus;
use App\Models\User;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

class UserService
{
    public function search(?string $term, int $perPage = 20)
    {
        return User::query()
            ->where('is_blocked', false)
            ->when($term, fn ($query) => $query->where('name', 'like', '%'.addcslashes($term, '%_').'%'))
            ->withCount(['followers', 'following', 'tracks'])
            ->orderBy('name')
            ->paginate($perPage);
    }

    public function profile(User $user): User
    {
        return $user->load([
            'tracks' => fn ($query) => $query->published()->with(['artist', 'genre'])->withCount(['likes', 'comments'])->latest(),
            'likedTracks' => fn ($query) => $query->published()->with(['artist', 'genre'])->withCount(['likes', 'comments'])->latest(),
            'playlists',
        ])->loadCount(['followers', 'following']);
    }

    public function block(User $user): User
    {
        if ($user->isAdmin()) throw new ConflictHttpException('Нельзя заблокировать администратора.');
        $user->update(['is_blocked' => true]);
        return $user;
    }

    public function unblock(User $user): void { $user->update(['is_blocked' => false]); }
}
