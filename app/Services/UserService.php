<?php

namespace App\Services;

use App\Models\User;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/**
 * Профиль пользователя и блокировка администратором (ФТ-14).
 */
class UserService
{
    public function profile(User $user): User
    {
        return $user->loadCount(['followers', 'following']);
    }

    /** Блокировка идемпотентна; администратора заблокировать нельзя. */
    public function block(User $user): User
    {
        if ($user->isAdmin()) {
            throw new ConflictHttpException('Нельзя заблокировать администратора.');
        }

        $user->update(['is_blocked' => true]);
        $user->tokens()->delete();

        return $user;
    }

    public function unblock(User $user): void
    {
        $user->update(['is_blocked' => false]);
    }
}
