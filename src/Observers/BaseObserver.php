<?php

namespace Sanjid29\StarterCore\Observers;

use Illuminate\Database\Eloquent\Model;

abstract class BaseObserver
{
    public function creating(Model $model): void
    {
        $model->created_by = auth()->id();
        $model->updated_by = auth()->id();
    }

    public function created(Model $model): void {}

    public function updating(Model $model): void
    {
        $model->updated_by = auth()->id();
    }

    public function updated(Model $model): void {}

    public function saving(Model $model): void {}

    public function saved(Model $model): void {}

    public function deleting(Model $model): void
    {
        $model->deleted_by = auth()->id();
        $model->saveQuietly();
    }

    public function deleted(Model $model): void {}

    public function restoring(Model $model): void {}

    public function restored(Model $model): void {}

    public function forceDeleting(Model $model): void {}

    public function forceDeleted(Model $model): void {}
}
