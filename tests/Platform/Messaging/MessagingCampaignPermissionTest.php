<?php

namespace Visnsstudio\VisnsPackages\Tests\Platform\Messaging;

/**
 * `messaging.bulk.permission`: a grant of its own for the bulk endpoints.
 *
 * Its own class because routes are registered at BOOT, so the permission name
 * has to be in config before the application is built - a test that set it in
 * its body would be asserting against a route table that had never heard of it.
 *
 * Worth separating in a real deployment for the reason the config block gives:
 * "may run the inbox" and "may text every client at once" are different risks,
 * and only one of them is undoable.
 */
class MessagingCampaignPermissionTest extends MessagingTestCase
{
    protected function defineEnvironment($app)
    {
        parent::defineEnvironment($app);

        $app['config']->set('visns-packages.messaging.bulk.enabled', true);
        $app['config']->set('visns-packages.messaging.bulk.permission', 'Messaging Bulk');
    }

    public function test_the_manage_permission_is_no_longer_enough(): void
    {
        $this->actingAs($this->admin())
            ->getJson(self::BASE . '/campaigns')
            ->assertStatus(403);
    }

    public function test_the_named_permission_opens_it(): void
    {
        $bulk = $this->staffWith('Messaging Access', 'Messaging Bulk');

        $this->actingAs($bulk)->getJson(self::BASE . '/campaigns')->assertOk();
    }

    public function test_the_opt_out_register_is_still_on_manage(): void
    {
        // The two are separate gates. Somebody who may send a campaign is not
        // thereby somebody who may take a client off the unsubscribe list.
        $this->actingAs($this->admin())->getJson(self::BASE . '/opt-outs')->assertOk();
    }
}
