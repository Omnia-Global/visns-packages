<?php

namespace Visnsstudio\VisnsPackages\Tests\Platform\CallQueue;

use Visnsstudio\VisnsPackages\Tests\TestCase;

/**
 * A deployment running the call queue without the Zoom Phone roster.
 *
 * The endpoint has to be absent rather than empty: an application that has not
 * opted into presence should not grow a route that answers "nobody is on the
 * phone", which is indistinguishable from a quiet office.
 */
class PhonePresenceDisabledTest extends TestCase
{
    protected function defineEnvironment($app)
    {
        parent::defineEnvironment($app);

        $app['config']->set('visns-packages.call_queue.enabled', true);
        $app['config']->set('visns-packages.call_queue.presence.enabled', false);
    }

    public function test_the_roster_route_is_not_registered(): void
    {
        $this->assertFalse(
            collect(app('router')->getRoutes())->contains(
                fn ($route) => $route->uri() === 'ajax/call-queue/presence'
            )
        );
    }

    public function test_the_queue_snapshot_still_is(): void
    {
        $this->assertTrue(
            collect(app('router')->getRoutes())->contains(
                fn ($route) => $route->uri() === 'ajax/call-queue/live'
            )
        );
    }
}
