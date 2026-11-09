<?php

namespace Sanjid29\StarterCore\Tests\Feature;

use PHPUnit\Framework\Attributes\Test;
use Sanjid29\StarterCore\Tests\TestCase;

class ServiceProviderTest extends TestCase
{
    #[Test]
    public function it_merges_the_package_config(): void
    {
        $this->assertSame('Superuser', config('starter-core.superuser_role'));
        $this->assertSame('App\\Models\\Tag', config('starter-core.tag_model'));
    }

    #[Test]
    public function it_registers_the_middleware_aliases(): void
    {
        $aliases = $this->app['router']->getMiddleware();

        $this->assertArrayHasKey('permission', $aliases);
        $this->assertArrayHasKey('role', $aliases);
        $this->assertArrayHasKey('feature', $aliases);
    }

    #[Test]
    public function it_registers_the_crud_module_route_macro(): void
    {
        $this->assertTrue(\Illuminate\Support\Facades\Route::hasMacro('crudModule'));
    }

    #[Test]
    public function it_registers_the_artisan_commands(): void
    {
        $commands = array_keys($this->app[\Illuminate\Contracts\Console\Kernel::class]->all());

        $this->assertContains('make:crud-module', $commands);
        $this->assertContains('remove:crud-module', $commands);
        $this->assertContains('make:service', $commands);
    }
}
