<?php

namespace Sanjid29\StarterCore\Tests\Feature;

use Illuminate\Support\Facades\Route;
use PHPUnit\Framework\Attributes\Test;
use Sanjid29\StarterCore\Tests\Fixtures\WidgetController;
use Sanjid29\StarterCore\Tests\Fixtures\WidgetDataTableController;
use Sanjid29\StarterCore\Tests\TestCase;

class RouteMacroTest extends TestCase
{
    #[Test]
    public function it_registers_the_full_set_of_named_routes(): void
    {
        Route::crudModule('widgets', WidgetController::class, WidgetDataTableController::class);

        $expected = [
            'index', 'create', 'store', 'show', 'edit', 'update', 'destroy',
            'select', 'json', 'datatable', 'recycle-bin', 'recycle-bin.datatable',
            'restore', 'force-delete',
        ];

        foreach ($expected as $name) {
            $this->assertTrue(Route::has("widgets.{$name}"), "Missing route widgets.{$name}");
        }
    }

    #[Test]
    public function destructive_routes_are_permission_guarded(): void
    {
        Route::crudModule('widgets', WidgetController::class, WidgetDataTableController::class);

        $middleware = Route::getRoutes()->getByName('widgets.destroy')->gatherMiddleware();

        $this->assertContains('permission:widgets.delete', $middleware);
    }

    #[Test]
    public function select_is_guarded_by_view_permission_by_default(): void
    {
        Route::crudModule('widgets', WidgetController::class, WidgetDataTableController::class);

        $middleware = Route::getRoutes()->getByName('widgets.select')->gatherMiddleware();

        $this->assertContains('permission:widgets.view', $middleware);
        $this->assertNotContains('throttle:api', $middleware);
    }

    #[Test]
    public function public_flag_exposes_select_and_json_behind_throttle_without_permission(): void
    {
        Route::crudModule('gadgets', WidgetController::class, WidgetDataTableController::class, public: true);

        $select = Route::getRoutes()->getByName('gadgets.select')->gatherMiddleware();
        $json = Route::getRoutes()->getByName('gadgets.json')->gatherMiddleware();

        $this->assertContains('throttle:api', $select);
        $this->assertNotContains('permission:gadgets.view', $select);

        $this->assertContains('throttle:api', $json);
        $this->assertNotContains('permission:gadgets.view-grid', $json);
    }

    #[Test]
    public function json_route_is_only_registered_when_a_datatable_controller_is_given(): void
    {
        Route::crudModule('thingers', WidgetController::class);

        $this->assertTrue(Route::has('thingers.index'));
        $this->assertFalse(Route::has('thingers.json'));
        $this->assertFalse(Route::has('thingers.datatable'));
    }
}
