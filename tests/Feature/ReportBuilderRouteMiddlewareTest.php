<?php

namespace Tests\Feature;

use Orchestra\Testbench\TestCase;
use Visnsstudio\VisnsPackages\VisnsPackagesServiceProvider;

/**
 * The report builder endpoints expose the database schema and execute SELECT
 * queries built from the request payload, so they must never be reachable
 * without authentication. This locks that in.
 */
class ReportBuilderRouteMiddlewareTest extends TestCase
{
    /** @var array|null */
    private $originalArgv;

    protected function setUp(): void
    {
        // The service provider skips route registration while running in the
        // console unless the command is route:list, so pretend to be it.
        $this->originalArgv = $_SERVER['argv'] ?? null;
        $_SERVER['argv'] = ['artisan', 'route:list'];

        parent::setUp();
    }

    protected function tearDown(): void
    {
        if ($this->originalArgv === null) {
            unset($_SERVER['argv']);
        } else {
            $_SERVER['argv'] = $this->originalArgv;
        }

        parent::tearDown();
    }

    protected function getPackageProviders($app)
    {
        return [VisnsPackagesServiceProvider::class];
    }

    /**
     * Middleware recorded on the route action.
     *
     * `gatherMiddleware()` is avoided on purpose: it resolves the controller,
     * which extends the host application's base controller and therefore does
     * not exist when the package is tested standalone.
     *
     * @param \Illuminate\Routing\Route $route
     * @return array
     */
    private function middlewareFor($route): array
    {
        return (array) ($route->getAction('middleware') ?? []);
    }

    /** @test */
    public function every_report_builder_route_requires_authentication()
    {
        $reportBuilderRoutes = [];

        foreach (app('router')->getRoutes() as $route) {
            if (strpos($route->uri(), 'reportBuilder') !== false) {
                $key =
                    implode('|', $route->methods()) . ' ' . $route->uri();
                $reportBuilderRoutes[$key] = $this->middlewareFor($route);
            }
        }

        $this->assertNotEmpty(
            $reportBuilderRoutes,
            'No report builder routes were registered'
        );

        foreach ($reportBuilderRoutes as $key => $middleware) {
            $this->assertContains(
                'auth',
                $middleware,
                "Route [{$key}] is missing the auth middleware"
            );
            $this->assertContains(
                'web',
                $middleware,
                "Route [{$key}] is missing the web middleware"
            );
        }
    }

    /** @test */
    public function it_covers_the_execute_endpoint_specifically()
    {
        $execute = null;

        foreach (app('router')->getRoutes() as $route) {
            if ($route->uri() === 'ajax/reportBuilder/execute') {
                $execute = $route;
                break;
            }
        }

        $this->assertNotNull($execute, 'The execute route is not registered');
        $this->assertContains('auth', $this->middlewareFor($execute));
    }

    /** @test */
    public function it_leaves_the_other_package_routes_unauthenticated()
    {
        // Sign-in has to stay reachable without a session: the report builder
        // group must not have widened the middleware of the other routes.
        $login = null;

        foreach (app('router')->getRoutes() as $route) {
            if ($route->uri() === 'login/authenticate') {
                $login = $route;
                break;
            }
        }

        $this->assertNotNull($login, 'The login route is not registered');
        $this->assertNotContains('auth', $this->middlewareFor($login));
    }
}
