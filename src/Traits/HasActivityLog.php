<?php

namespace Sanjid29\StarterCore\Traits;

use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

/**
 * Adds automatic activity logging to any Eloquent model.
 *
 * Override getActivitylogOptions() in your model to customise behaviour.
 */
trait HasActivityLog
{
    use LogsActivity;

    public function getActivitylogOptions(): LogOptions
    {
        $logName = strtolower(class_basename($this));

        return LogOptions::defaults()
            ->logFillable()
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs()
            ->useLogName($logName)
            ->setDescriptionForEvent(
                fn (string $eventName) => ucfirst($logName).' was '.$eventName
            );
    }
}
