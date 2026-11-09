<?php

namespace Sanjid29\StarterCore\Tests\Feature;

use Illuminate\Support\Facades\Route;
use PHPUnit\Framework\Attributes\Test;
use Sanjid29\StarterCore\Tests\Fixtures\WidgetController;
use Sanjid29\StarterCore\Tests\Fixtures\WidgetDataTableController;
use Sanjid29\StarterCore\Tests\TestCase;

class RouteMacroTest extends TestCase
{
    private function registerModule(string $prefix, bool $public = false, bool $withDataTable = true): void
    {
        Route::crudModule(
            $prefix,
            WidgetController::class,
            $withDataTable ? WidgetDataTableController::class : null,
            public: $public,
        );
    }

    /**
     * All registered route names. Read from each route's own getName() rather than
     * the collection's name index, which is stale until a request is matched.
     *
     * @return array<int, string>
     */
    private function routeNames(): array
    {
        return collect(Route::getRoutes()->getRoutes())
            ->map(fn ($route) => $route->getName())
            ->filter()
            ->values()
            ->all();
    }

    /**
     * @return array<int, string>
     */
    private function middlewareFor(string $name): array
    {
        $route = collect(Route::getRoutes()->getRoutes())
            ->first(fn ($route) => $route->getName() === $name);

        $this->assertNotNull($route, "Route {$name} is not registered");

        return $route->gatherMiddleware();
    }

    #[Test]
    public function it_registers_the_full_set_of_named_routes(): void
    {
        $this->registerModule('widgets');
        $names = $this->routeNames();

        $expected = [
            'index', 'create', 'store', 'show', 'edit', 'update', 'destroy',
            'select', 'json', 'datatable', 'recycle-bin', 'recycle-bin.datatable',
            'restore', 'force-delete',
        ];

        foreach ($expected as $name) {
            $this->assertContains("widgets.{$name}", $names, "Missing route widgets.{$name}");
        }
    }

    #[Test]
    public function destructive_routes_are_permission_guarded(): void
    {
        $this->registerModule('widgets');

        $this->assertContains('permission:widgets.delete', $this->middlewareFor('widgets.destroy'));
    }

    #[Test]
    public function select_is_guarded_by_view_permission_by_default(): void
    {
        $this->registerModule('widgets');
        $middleware = $this->middlewareFor('widgets.select');

        $this->assertContains('permission:widgets.view', $middleware);
        $this->assertNotContains('throttle:api', $middleware);
    }

    #[Test]
    public function public_flag_exposes_select_and_json_behind_throttle_without_permission(): void
    {
        $this->registerModule('gadgets', public: true);

        $select = $this->middlewareFor('gadgets.select');
        $json = $this->middlewareFor('gadgets.json');

        $this->assertContains('throttle:api', $select);
        $this->assertNotContains('permission:gadgets.view', $select);

        $this->assertContains('throttle:api', $json);
        $this->assertNotContains('permission:gadgets.view-grid', $json);
    }

    #[Test]
    public function json_route_is_only_registered_when_a_datatable_controller_is_given(): void
    {
        $this->registerModule('thingers', withDataTable: false);
        $names = $this->routeNames();

        $this->assertContains('thingers.index', $names);
        $this->assertNotContains('thingers.json', $names);
        $this->assertNotContains('thingers.datatable', $names);
    }
}
