<?php

namespace Sanjid29\StarterCore\Tests;

use Orchestra\Testbench\TestCase as Orchestra;
use Sanjid29\StarterCore\StarterKitServiceProvider;

abstract class TestCase extends Orchestra
{
    /**
     * Register the package's service provider for every test.
     */
    protected function getPackageProviders($app): array
    {
        return [
            StarterKitServiceProvider::class,
        ];
    }
}
