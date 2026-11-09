<?php

namespace Sanjid29\StarterCore\Policies;

use Illuminate\Database\Eloquent\Model;

/**
 * Routes check whether someone may do an action at all, with a `permission:`
 * middleware. A policy only decides whether that action is allowed on one
 * particular record, such as a user stronger than the person asking.
 *
 * Everything is allowed by default, so `can('update', $record)` doesn't mean the
 * person holds the update permission. To decide whether to show a button, check
 * both: `can('tags.update') && can('update', $tag)`.
 *
 * Gate::before() in StarterKitServiceProvider lets Superusers through first.
 */
abstract class BasePolicy
{
    public function view(mixed $user, Model $model): bool
    {
        return true;
    }

    public function update(mixed $user, Model $model): bool
    {
        return true;
    }

    public function delete(mixed $user, Model $model): bool
    {
        return true;
    }

    public function restore(mixed $user, Model $model): bool
    {
        return true;
    }

    public function forceDelete(mixed $user, Model $model): bool
    {
        return true;
    }
}
