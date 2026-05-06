<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Superuser Role Name
    |--------------------------------------------------------------------------
    | The role name that bypasses all gate and policy checks globally.
    */

    'superuser_role' => 'Superuser',

    /*
    |--------------------------------------------------------------------------
    | Tag Model
    |--------------------------------------------------------------------------
    | The Eloquent model used by the HasTags trait. Must use HasAssociations
    | and have a `type` column for multi-type tag support.
    */

    'tag_model' => 'App\\Models\\Tag',

    /*
    |--------------------------------------------------------------------------
    | Feature Flags (standalone fallback)
    |--------------------------------------------------------------------------
    | Used by the `feature:` middleware when the host app does not provide a
    | setting() helper. Each key maps to a feature name:
    |
    |   Route::middleware('feature:notifications')
    |   -> checks features.notifications below
    |
    | When a setting() helper is present (e.g. from the Laravel Starter Kit),
    | it takes precedence over these values.
    */

    'features' => [
        // 'notifications' => false,
        // 'registration'  => true,
        // '2fa'           => false,
    ],

];
