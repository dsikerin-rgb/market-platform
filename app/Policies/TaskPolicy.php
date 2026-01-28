<?php

# app/Policies/TaskPolicy.php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Task;
use App\Models\User;

class TaskPolicy
{
    /**
     * Доступ к разделу "Задачи" (меню/список).
     * Реальные записи дальше режутся view() и запросом в Resource.
     */
    public function viewAny(User $user): bool
    {
        if ($this->isMerchant($user)) {
            return false;
        }

        if ($user->isSuperAdmin()) {
            return true;
        }

        // Любая внутренняя роль рынка (engineer/it/охрана/и т.д.) может иметь доступ к задачам,
        // но видеть будет только "вовлечённые" (см. view()).
        return (bool) $user->market_id;
    }

    /**
     * Просмотр конкретной задачи:
     * - super-admin: всё
     * - market-admin: все задачи рынка
     * - прочие: только вовлечённые (постановщик / исполнитель / участник)
     *
     * История будет доступна только в рамках открытой задачи => достаточно ограничить view().
     */
    public function view(User $user, Task $task): bool
    {
        if ($this->isMerchant($user)) {
            return false;
        }

        if ($user->isSuperAdmin()) {
            return true;
        }

        if (! $this->sameMarket($user, $task)) {
            return false;
        }

        if ($user->hasRole('market-admin')) {
            return true;
        }

        return $this->isInvolved($user, $task);
    }

    /**
     * Создание задачи (MVP): любой сотрудник рынка.
     * Если захочешь ограничить — скажешь, ужмём до нужных ролей.
     */
    public function create(User $user): bool
    {
        return $this->viewAny($user);
    }

    /**
     * Важно: Filament использует update() как доступ к Edit-странице/сохранению.
     * Чтобы "вовлечённые" могли открыть задачу (и увидеть историю), даём update тем, кто вовлечён.
     * Что именно можно менять — разруливается отдельными ability ниже (updateCore/updateStatus/...)
     * и UI (readonly placeholders).
     */
    public function update(User $user, Task $task): bool
    {
        if ($this->isMerchant($user)) {
            return false;
        }

        if ($user->isSuperAdmin()) {
            return true;
        }

        if (! $this->sameMarket($user, $task)) {
            return false;
        }

        if ($user->hasRole('market-admin')) {
            return true;
        }

        // Любая роль рынка может работать с задачами, но только если вовлечена
        return $this->isInvolved($user, $task);
    }

    /**
     * "Управленческое ядро" задачи: название/описание/приоритет/дедлайн/исполнитель.
     * MVP:
     * - market-admin: всегда
     * - постановщик: только пока задача NEW
     */
    public function updateCore(User $user, Task $task): bool
    {
        if ($this->isMerchant($user)) {
            return false;
        }

        if ($user->isSuperAdmin()) {
            return true;
        }

        if (! $this->sameMarket($user, $task)) {
            return false;
        }

        if ($user->hasRole('market-admin')) {
            return true;
        }

        return $this->isCreator($user, $task) && $this->isNew($task);
    }

    /**
     * Статус:
     * - market-admin: всегда
     * - исполнитель/соисполнитель: да
     * - постановщик: только пока NEW (например, отменить пока не приняли)
     */
    public function updateStatus(User $user, Task $task): bool
    {
        if ($this->isMerchant($user)) {
            return false;
        }

        if ($user->isSuperAdmin()) {
            return true;
        }

        if (! $this->sameMarket($user, $task)) {
            return false;
        }

        if ($user->hasRole('market-admin')) {
            return true;
        }

        if ($this->isAssignee($user, $task) || $this->isCoexecutor($user, $task)) {
            return true;
        }

        return $this->isCreator($user, $task) && $this->isNew($task);
    }

    /**
     * "Принять": только назначенному исполнителю и только из NEW.
     */
    public function accept(User $user, Task $task): bool
    {
        if ($this->isMerchant($user)) {
            return false;
        }

        if ($user->isSuperAdmin()) {
            return true;
        }

        if (! $this->sameMarket($user, $task)) {
            return false;
        }

        return $this->isAssignee($user, $task) && $this->isNew($task);
    }

    /**
     * Соисполнители (делегирование):
     * - market-admin: всегда
     * - постановщик: только пока NEW
     */
    public function manageCoexecutors(User $user, Task $task): bool
    {
        if ($this->isMerchant($user)) {
            return false;
        }

        if ($user->isSuperAdmin()) {
            return true;
        }

        if (! $this->sameMarket($user, $task)) {
            return false;
        }

        if ($user->hasRole('market-admin')) {
            return true;
        }

        return $this->isCreator($user, $task) && $this->isNew($task);
    }

    /**
     * Наблюдатели (информирование):
     * - market-admin: всегда
     * - постановщик / исполнитель / соисполнитель: да
     */
    public function manageObservers(User $user, Task $task): bool
    {
        if ($this->isMerchant($user)) {
            return false;
        }

        if ($user->isSuperAdmin()) {
            return true;
        }

        if (! $this->sameMarket($user, $task)) {
            return false;
        }

        if ($user->hasRole('market-admin')) {
            return true;
        }

        return $this->isCreator($user, $task)
            || $this->isAssignee($user, $task)
            || $this->isCoexecutor($user, $task);
    }

    public function delete(User $user, Task $task): bool
    {
        if ($this->isMerchant($user)) {
            return false;
        }

        if ($user->isSuperAdmin()) {
            return true;
        }

        return $this->sameMarket($user, $task) && $user->hasRole('market-admin');
    }

    private function sameMarket(User $user, Task $task): bool
    {
        return (bool) $user->market_id && (int) $task->market_id === (int) $user->market_id;
    }

    private function isMerchant(User $user): bool
    {
        return $user->hasAnyRole(['merchant', 'merchant-user']);
    }

    private function isCreator(User $user, Task $task): bool
    {
        return (int) $task->created_by_user_id === (int) $user->id;
    }

    private function isAssignee(User $user, Task $task): bool
    {
        return (int) $task->assignee_id === (int) $user->id;
    }

    private function isCoexecutor(User $user, Task $task): bool
    {
        return $task->participantEntries()
            ->where('user_id', (int) $user->id)
            ->where('role', Task::PARTICIPANT_ROLE_COEXECUTOR)
            ->exists();
    }

    private function isInvolved(User $user, Task $task): bool
    {
        if ($this->isCreator($user, $task) || $this->isAssignee($user, $task)) {
            return true;
        }

        // observer/coexecutor (любой участник)
        return $task->participantEntries()
            ->where('user_id', (int) $user->id)
            ->exists();
    }

    private function isNew(Task $task): bool
    {
        return (string) $task->status === (string) Task::STATUS_NEW;
    }
}
