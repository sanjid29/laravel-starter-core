<?php

namespace Sanjid29\StarterCore\Tests\Fixtures;

use Sanjid29\StarterCore\Http\Controllers\BaseController;

/**
 * Concrete BaseController used only for route-registration tests.
 */
class WidgetController extends BaseController
{
    protected string $routePrefix = 'widgets';

    protected string $viewPrefix = 'widgets';

    protected string $resourceName = 'Widget';
}
