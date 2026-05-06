<?php

namespace Sanjid29\StarterCore\Traits;

use Illuminate\Database\Eloquent\Relations\BelongsToMany;

trait HasTags
{
    use HasAssociations;

    /**
     * The Tag model class. Defaults to App\Models\Tag.
     * Override via config('starter-core.tag_model').
     */
    private static function tagModelClass(): string
    {
        return config('starter-core.tag_model', 'App\\Models\\Tag');
    }

    public function tags(): BelongsToMany
    {
        return $this->associates(static::tagModelClass());
    }

    public function tagsOfType(string $type): BelongsToMany
    {
        return $this->associates(static::tagModelClass())->where('type', $type);
    }

    /**
     * Sync tags of a specific type without touching tags of other types.
     *
     * @param  array<int>  $tagIds
     */
    public function syncTagsOfType(string $type, array $tagIds): void
    {
        $tagModel = static::tagModelClass();
        $tagTable = (new $tagModel)->getTable();

        $preserveIds = $this->tags()
            ->where('type', '!=', $type)
            ->pluck("{$tagTable}.id")
            ->all();

        $this->associates($tagModel)->sync(array_merge($preserveIds, $tagIds));
    }
}
