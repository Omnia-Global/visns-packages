<?php

namespace Visnsstudio\VisnsPackages\Tests\Platform\Messaging;

/**
 * The bulk sub-module ships disabled, and a disabled sub-module has no
 * endpoints at all.
 *
 * Its own class rather than a method on MessagingCampaignTest precisely because
 * routes are registered at BOOT: a test that switched `bulk.enabled` in its body
 * would be asserting against a route table built before the switch was touched,
 * and would pass for the wrong reason.
 *
 * The opt-out routes are asserted here too, from the other direction. They are
 * part of messaging itself - the module's compliance floor - so they are present
 * in exactly this configuration, with bulk off.
 */
class MessagingCampaignDisabledTest extends MessagingTestCase
{
    public function test_no_campaign_route_exists_while_the_sub_module_is_off(): void
    {
        $admin = $this->admin();

        foreach ([
            ['get', '/campaigns'],
            ['get', '/campaigns/1'],
            ['get', '/campaigns/1/recipients'],
        ] as [$verb, $uri]) {
            $this->actingAs($admin)
                ->{$verb . 'Json'}(self::BASE . $uri)
                ->assertStatus(404);
        }

        foreach ([
            '/campaigns',
            '/campaigns/preview',
            '/campaigns/1/start',
            '/campaigns/1/pause',
            '/campaigns/1/cancel',
            '/campaigns/1/retry-failed',
        ] as $uri) {
            $this->actingAs($admin)->postJson(self::BASE . $uri, [])->assertStatus(404);
        }

        $this->actingAs($admin)->deleteJson(self::BASE . '/campaigns/1')->assertStatus(404);
    }

    public function test_the_opt_out_routes_are_there_anyway(): void
    {
        // Not behind `bulk.enabled`. An unsubscribe facility is the module's
        // compliance floor rather than a feature of the bulk sub-module, and a
        // practice that never sends a campaign still texts clients.
        $this->actingAs($this->admin())
            ->getJson(self::BASE . '/opt-outs')
            ->assertOk();
    }

    public function test_the_command_says_so_rather_than_not_existing(): void
    {
        // Registered unconditionally, like the module's other three: a command
        // that did not exist would answer "command not found" to somebody trying
        // to find out why nothing is sending.
        $this->artisan('sms:send-campaigns')
            ->expectsOutputToContain('Bulk campaigns are disabled')
            ->assertSuccessful();
    }
}
