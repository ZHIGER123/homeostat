<?php

namespace App\Policies;

use App\Models\User;

class UserPolicy
{
    public function access(User $user, User $target): bool
    {
        return $user->isAdmin() || $user->is($target);
    }
}
