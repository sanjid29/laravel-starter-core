<?php

namespace Sanjid29\StarterCore\Tests\Fixtures;

use Sanjid29\StarterCore\Http\Controllers\BaseDataTableController;

/**
 * Concrete BaseDataTableController used only for route-registration tests.
 */
class WidgetDataTableController extends BaseDataTableController
{
    protected string $routePrefix = 'widgets';
}
