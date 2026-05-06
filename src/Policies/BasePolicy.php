<?php

namespace Sanjid29\StarterCore\Policies;

use Illuminate\Database\Eloquent\Model;

abstract class BasePolicy
{
    /**
     * Superusers bypass all policy checks.
     * Works with any auth user that has Spatie's HasRoles trait.
     */
    public function before(mixed $user, string $ability): ?bool
    {
        if (method_exists($user, 'hasRole') && $user->hasRole(config('starter-core.superuser_role', 'Superuser'))) {
            return true;
        }

        return null;
    }

    /**
     * The Spatie permission resource prefix, e.g. 'users', 'leave-types'.
     */
    abstract protected function resource(): string;

    public function viewAny(mixed $user): bool
    {
        return $user->can("{$this->resource()}.view-grid");
    }

    public function view(mixed $user, Model $model): bool
    {
        return $user->can("{$this->resource()}.view");
    }

    public function create(mixed $user): bool
    {
        return $user->can("{$this->resource()}.create");
    }

    public function update(mixed $user, Model $model): bool
    {
        return $user->can("{$this->resource()}.update");
    }

    public function delete(mixed $user, Model $model): bool
    {
        return $user->can("{$this->resource()}.delete");
    }

    public function recycleBin(mixed $user): bool
    {
        return $user->can("{$this->resource()}.restore");
    }

    public function restore(mixed $user, Model $model): bool
    {
        return $user->can("{$this->resource()}.restore");
    }

    public function forceDelete(mixed $user, Model $model): bool
    {
        return $user->can("{$this->resource()}.force-delete");
    }
}
