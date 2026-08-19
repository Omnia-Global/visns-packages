<?php

namespace Visnsstudio\VisnsPackages\Tests\Platform;

use Illuminate\Http\Request;
use Visnsstudio\VisnsPackages\Middleware\VerifyZoomWebhookSignature;
use Visnsstudio\VisnsPackages\Tests\TestCase;

/**
 * The package fills in middleware aliases its own routes need, but must never
 * take a name the application has already claimed.
 *
 * Providers boot after the application's own middleware registration, so a
 * package that registers unconditionally always wins - silently changing what
 * every route carrying that name actually does.
 */
class MiddlewareAliasTest extends TestCase
{
    private function aliases(): array
    {
        return $this->app['router']->getMiddleware();
    }

    public function test_the_zoom_alias_is_registered_under_both_spellings(): void
    {
        // Applications in the wild use the underscore form; supporting both
        // lets route definitions move across untouched.
        $this->assertSame(
            VerifyZoomWebhookSignature::class,
            $this->aliases()['zoom-webhook']
        );
        $this->assertSame(
            VerifyZoomWebhookSignature::class,
            $this->aliases()['zoom_webhook']
        );
    }

    public function test_the_permission_alias_is_filled_in_when_spatie_is_present(): void
    {
        $this->assertSame(
            \Spatie\Permission\Middleware\PermissionMiddleware::class,
            $this->aliases()['permission']
        );
    }

    public function test_an_application_alias_is_never_overwritten(): void
    {
        $this->assertSame(
            AppOwnedZoomMiddleware::class,
            $this->aliases()['zoom_webhook']
        );
        $this->assertSame(
            AppOwnedPermissionMiddleware::class,
            $this->aliases()['permission']
        );

        // The spelling the application did NOT claim is still filled in.
        $this->assertSame(
            VerifyZoomWebhookSignature::class,
            $this->aliases()['zoom-webhook']
        );
    }

    /**
     * Claim the names before the package provider boots, which is the order a
     * real application's bootstrap produces.
     */
    protected function resolveApplicationCore($app)
    {
        parent::resolveApplicationCore($app);

        if ($this->name() === 'test_an_application_alias_is_never_overwritten') {
            $app['router']->aliasMiddleware(
                'zoom_webhook',
                AppOwnedZoomMiddleware::class
            );
            $app['router']->aliasMiddleware(
                'permission',
                AppOwnedPermissionMiddleware::class
            );
        }
    }
}

/** An application's own wrapper around the webhook check. */
class AppOwnedZoomMiddleware
{
    public function handle(Request $request, \Closure $next)
    {
        return $next($request);
    }
}

/** An application's own permission gate, e.g. one that logs denials. */
class AppOwnedPermissionMiddleware
{
    public function handle(Request $request, \Closure $next)
    {
        return $next($request);
    }
}
