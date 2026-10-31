<?php

namespace Visnsstudio\VisnsPackages\Tests\Platform\Billing;

use Visnsstudio\VisnsPackages\Services\IntegrationRegistry;
use Visnsstudio\VisnsPackages\Tests\FakeStripe;

/** Off by default: no webhook, no staff API, no integration card, no portal. */
class BillingDisabledTest extends BillingTestCase
{
    protected function defineEnvironment($app)
    {
        parent::defineEnvironment($app);

        $app['config']->set('visns-packages.billing.enabled', false);
    }

    public function test_nothing_is_registered(): void
    {
        $this->webhook('invoice.paid', FakeStripe::invoice('in_1', 'sub_1'))->assertNotFound();
        $this->actingAs($this->staff('Settings'))->getJson('/ajax/billing/features')->assertNotFound();
        $this->assertNull(app(IntegrationRegistry::class)->definition('stripe'));
    }

    public function test_a_mounted_portal_controller_answers_404(): void
    {
        $this->signIn($this->client());
        $this->feature();

        $this->getJson(self::PORTAL)->assertNotFound();
        $this->postJson(self::PORTAL . '/checkout', ['feature' => 'portal_messaging', 'interval' => 'month'])->assertNotFound();
    }
}
