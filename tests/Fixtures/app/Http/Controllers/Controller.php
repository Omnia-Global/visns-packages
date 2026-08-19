<?php

namespace App\Http\Controllers;

use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Foundation\Validation\ValidatesRequests;
use Illuminate\Routing\Controller as BaseController;

/**
 * Test-only stand-in for the host application's base controller.
 *
 * Every controller in this package extends `\App\Http\Controllers\Controller`,
 * because it is designed to be dropped into a Laravel application that has one.
 * The package test suite has no application, so it supplies this fixture (via
 * the `App\` entry in composer's autoload-dev) rather than changing the
 * controllers to extend something else - the base class an application's
 * middleware and traits hang off is part of how these controllers behave.
 */
class Controller extends BaseController
{
    use AuthorizesRequests, ValidatesRequests;
}
