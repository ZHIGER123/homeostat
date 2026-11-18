<?php

namespace App\Services;

use App\Models\Track;
use App\Models\User;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

/**
 * Подписки на артистов и персональная лента (ФТ-11).
 */
class FollowService
{
    /**
     * Подписка идемпотентна. Возвращает true, если подписка создана сейчас.
     */
    public function follow(User $follower, User $artist): bool
    {
        if ($follower->is_blocked) {
            throw new AccessDeniedHttpException('Пользователь заблокирован.');
        }
        if ($follower->is($artist)) {
            throw ValidationException::withMessages(['artist' => 'Нельзя подписаться на самого себя.']);
        }
        if (! $artist->isArtist()) {
            throw ValidationException::withMessages(['artist' => 'Подписаться можно только на исполнителя.']);
        }

        return count($follower->following()->syncWithoutDetaching([$artist->id])['attached']) > 0;
    }

    public function unfollow(User $follower, User $artist): void
    {
        $follower->following()->detach($artist->id);
    }

    /** Лента новых опубликованных треков артистов, на которых подписан пользователь. */
    public function feed(User $user, int $perPage = 20): LengthAwarePaginator
    {
        return Track::query()
            ->published()
            ->whereIn('user_id', $user->following()->select('users.id'))
            ->with(['artist', 'genre'])
            ->withCount(['likes', 'comments'])
            ->latest()
            ->paginate($perPage);
    }
}
