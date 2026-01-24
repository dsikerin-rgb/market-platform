<?php
# app/Policies/TaskPolicy.php

namespace App\Policies;

use App\Models\Task;
use App\Models\User;

class TaskPolicy
{
    public function viewAny(User $user): bool
    {
        if ($this->isMerchant($user)) {
            return false;
        }

        if ($user->isSuperAdmin()) {
            return true;
        }

        return $user->market_id && $user->hasAnyRole(['market-admin', 'market-maintenance']);
    }

    public function view(User $user, Task $task): bool
    {
        if ($user->isSuperAdmin()) {
            return true;
        }

        return $this->viewAny($user) && $this->sameMarket($user, $task);
    }

    public function create(User $user): bool
    {
        return $this->viewAny($user);
    }

    public function update(User $user, Task $task): bool
    {
        if ($user->isSuperAdmin()) {
            return true;
        }

        return $this->viewAny($user)
            && $this->sameMarket($user, $task)
            && $user->hasAnyRole(['market-admin', 'market-maintenance']);
    }

    public function delete(User $user, Task $task): bool
    {
        if ($user->isSuperAdmin()) {
            return true;
        }

        return $this->sameMarket($user, $task) && $user->hasRole('market-admin');
    }

    private function sameMarket(User $user, Task $task): bool
    {
        return (bool) $user->market_id && $task->market_id === $user->market_id;
    }

    private function isMerchant(User $user): bool
    {
        return $user->hasAnyRole(['merchant', 'merchant-user']);
    }
}
