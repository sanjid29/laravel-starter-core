<?php

namespace Sanjid29\StarterCore\Traits;

use Illuminate\Database\Eloquent\Relations\BelongsToMany;

trait HasAssociations
{
    public function associates(string $dictionaryClass): BelongsToMany
    {
        return $this->belongsToMany($dictionaryClass, 'associables', 'associated_id', 'associable_id')
            ->wherePivot('associated_type', static::class)
            ->wherePivot('associable_type', $dictionaryClass)
            ->withTimestamps();
    }
}
