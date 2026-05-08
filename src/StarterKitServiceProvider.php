<?php

namespace Sanjid29\StarterCore;

use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Sanjid29\StarterCore\Console\Commands\MakeCrudModule;
use Sanjid29\StarterCore\Console\Commands\MakeService;
use Sanjid29\StarterCore\Console\Commands\RemoveCrudModule;
use Sanjid29\StarterCore\Http\Middleware\FeatureEnabled;
use Sanjid29\StarterCore\Http\Middleware\HandleImpersonation;
use Sanjid29\StarterCore\Http\Middleware\HasPermission;
use Sanjid29\StarterCore\Http\Middleware\HasRole;

class StarterKitServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/starter-core.php', 'starter-core');
    }

    public function boot(): void
    {
        $this->registerMiddleware();
        $this->registerCrudModuleMacro();
        $this->registerGateBefore();

        if ($this->app->runningInConsole()) {
            $this->commands([MakeCrudModule::class, RemoveCrudModule::class, MakeService::class]);
            $this->publishConfig();
            $this->publishMigrations();
            $this->publishStubs();
        }
    }

    // ─────────────────────────────────────────────
    // Middleware
    // ─────────────────────────────────────────────

    private function registerMiddleware(): void
    {
        $router = $this->app['router'];

        $router->aliasMiddleware('feature', FeatureEnabled::class);
        $router->aliasMiddleware('permission', HasPermission::class);
        $router->aliasMiddleware('role', HasRole::class);
        $router->pushMiddlewareToGroup('web', HandleImpersonation::class);
    }

    // ─────────────────────────────────────────────
    // Route macro — Route::crudModule()
    // ─────────────────────────────────────────────

    private function registerCrudModuleMacro(): void
    {
        Route::macro('crudModule', function (
            string $prefix,
            string $controller,
            ?string $dtController = null,
            ?\Closure $extras = null
        ): void {
            Route::prefix($prefix)
                ->name($prefix.'.')
                ->middleware("permission:{$prefix}.view")
                ->group(function () use ($controller, $dtController, $prefix, $extras): void {
                    if ($dtController) {
                        Route::get('/datatable', [$dtController, 'datatable'])
                            ->name('datatable')
                            ->middleware("permission:{$prefix}.view-grid");
                    }

                    Route::get('/', [$controller, 'index'])
                        ->name('index')
                        ->middleware("permission:{$prefix}.view-grid");

                    Route::get('/create', [$controller, 'create'])
                        ->name('create')
                        ->middleware("permission:{$prefix}.create");

                    Route::post('/', [$controller, 'store'])
                        ->name('store')
                        ->middleware("permission:{$prefix}.create");

                    Route::get('/select', [$controller, 'selectOptions'])
                        ->name('select');

                    // Recycle bin — static paths must come before {record} wildcard
                    Route::get('/recycle-bin', [$controller, 'recycleBin'])
                        ->name('recycle-bin')
                        ->middleware("permission:{$prefix}.restore");

                    if ($dtController) {
                        Route::get('/recycle-bin/datatable', [$dtController, 'recycleBinDatatable'])
                            ->name('recycle-bin.datatable')
                            ->middleware("permission:{$prefix}.restore");
                    }

                    // Extra static routes must be registered before {record} wildcard
                    if ($extras) {
                        $extras();
                    }

                    Route::get('/{record}', [$controller, 'show'])->name('show');

                    Route::get('/{record}/edit', [$controller, 'edit'])
                        ->name('edit')
                        ->middleware("permission:{$prefix}.update");

                    Route::put('/{record}', [$controller, 'update'])
                        ->name('update')
                        ->middleware("permission:{$prefix}.update");

                    Route::delete('/{record}', [$controller, 'destroy'])
                        ->name('destroy')
                        ->middleware("permission:{$prefix}.delete");

                    Route::post('/{record}/restore', [$controller, 'restore'])
                        ->name('restore')
                        ->middleware("permission:{$prefix}.restore");

                    Route::delete('/{record}/force-delete', [$controller, 'forceDelete'])
                        ->name('force-delete')
                        ->middleware("permission:{$prefix}.force-delete");
                });
        });
    }

    // ─────────────────────────────────────────────
    // Gate — Superuser bypass
    // ─────────────────────────────────────────────

    private function registerGateBefore(): void
    {
        Gate::before(function (mixed $user, string $ability): ?bool {
            if (method_exists($user, 'hasRole') && $user->hasRole(config('starter-core.superuser_role', 'Superuser'))) {
                return true;
            }

            return null;
        });
    }

    // ─────────────────────────────────────────────
    // Publishable config
    // ─────────────────────────────────────────────

    private function publishConfig(): void
    {
        $this->publishes([
            __DIR__.'/../config/starter-core.php' => config_path('starter-core.php'),
        ], 'starter-core-config');
    }

    // ─────────────────────────────────────────────
    // Publishable migrations
    // ─────────────────────────────────────────────

    private function publishMigrations(): void
    {
        $this->publishes([
            __DIR__.'/../database/migrations' => database_path('migrations'),
        ], 'starter-core-migrations');
    }

    // ─────────────────────────────────────────────
    // Publishable stubs
    // ─────────────────────────────────────────────

    private function publishStubs(): void
    {
        $this->publishes([
            __DIR__.'/../stubs' => base_path('stubs'),
        ], 'starter-core-stubs');
    }
}
