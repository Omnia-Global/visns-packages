<?php

namespace Visnsstudio\VisnsPackages\Tests\Platform\Billing;

use Visnsstudio\VisnsPackages\Models\BillingSubscription;
use Visnsstudio\VisnsPackages\Services\Billing\Quantities;
use Visnsstudio\VisnsPackages\Tests\FakeStripe;
use Visnsstudio\VisnsPackages\Tests\Fixtures\Billing\StubQuantityResolver;

/** Per-unit features: the host's count at Checkout and on the live subscription. */
class BillingQuantitiesTest extends BillingTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        StubQuantityResolver::$count = 0;
        config(['visns-packages.billing.hooks.quantity' => StubQuantityResolver::class]);
    }

    public function test_checkout_sends_the_hosts_count_and_at_least_one(): void
    {
        $client = $this->client();
        $this->feature();
        $this->signIn($client);

        StubQuantityResolver::$count = 3;
        $this->postJson(self::PORTAL . '/checkout', ['feature' => 'portal_messaging', 'interval' => 'month'])->assertOk();
        $this->assertSame('3', (string) $this->stripe->sent('POST', '/v1/checkout/sessions')['line_items'][0]['quantity']);
    }

    public function test_checkout_with_no_units_yet_bills_one(): void
    {
        $client = $this->client();
        $this->feature();
        $this->signIn($client);

        $this->postJson(self::PORTAL . '/checkout', ['feature' => 'portal_messaging', 'interval' => 'month'])->assertOk();
        $this->assertSame('1', (string) $this->stripe->sent('POST', '/v1/checkout/sessions')['line_items'][0]['quantity']);
    }

    public function test_a_flat_feature_is_one_without_a_hook_or_when_the_hook_says_null(): void
    {
        $client = $this->client();
        $flat = $this->feature('door_access');

        StubQuantityResolver::$count = 7;
        $this->assertSame(1, app(Quantities::class)->for($client, $flat));
        $this->assertSame(['ok' => true, 'changed' => false, 'quantity' => 1, 'from' => null, 'reason' => 'flat'], app(Quantities::class)->sync($client, $flat));

        config(['visns-packages.billing.hooks.quantity' => null]);
        $this->assertSame(1, app(Quantities::class)->for($client, $this->feature()));
    }

    public function test_sync_changes_the_item_quantity_with_prorations_only_when_it_differs(): void
    {
        [$client, $feature] = $this->subscribed();
        $item = $this->subscriptions['sub_1']['items']['data'][0]['id'];

        $this->stripe->on('POST', '/v1/subscription_items/*', function ($p, $r) {
            $this->subscriptions['sub_1']['items']['data'][0]['quantity'] = (int) $p['quantity'];

            return $this->subscriptions['sub_1']['items']['data'][0];
        });

        StubQuantityResolver::$count = 4;
        $answer = app(Quantities::class)->sync($client, $feature);

        $this->assertSame(['ok' => true, 'changed' => true, 'quantity' => 4, 'from' => 1, 'reason' => null], $answer);
        $this->stripe->assertSent('POST', '/v1/subscription_items/' . $item, function ($p) {
            $this->assertSame('4', (string) $p['quantity']);
            $this->assertSame('create_prorations', $p['proration_behavior']);

            return true;
        });

        // Already right: nothing sent.
        $this->assertFalse(app(Quantities::class)->sync($client, $feature)['changed']);
        $this->assertSame(1, $this->stripe->count('POST', '/v1/subscription_items/' . $item));

        // Every unit gone: the subscription keeps one.
        StubQuantityResolver::$count = 0;
        $this->assertSame(1, app(Quantities::class)->sync($client, $feature)['quantity']);
        $this->assertSame(1, $this->subscriptions['sub_1']['items']['data'][0]['quantity']);
    }

    public function test_dry_run_reports_without_writing(): void
    {
        [$client, $feature] = $this->subscribed();
        StubQuantityResolver::$count = 2;

        $answer = app(Quantities::class)->sync($client, $feature, true);

        $this->assertSame(['ok' => true, 'changed' => false, 'quantity' => 2, 'from' => 1, 'reason' => null], $answer);
        $this->stripe->assertNotSent('POST', '/v1/subscription_items/*');
    }

    public function test_nothing_live_and_a_stripe_failure_are_answers_never_throws(): void
    {
        $client = $this->client();
        $feature = $this->feature();
        StubQuantityResolver::$count = 2;

        $this->assertSame('none', app(Quantities::class)->sync($client, $feature)['reason']);

        [$client, $feature] = $this->subscribed($client, $feature);
        $this->stripe->on('POST', '/v1/subscription_items/*', FakeStripe::error(500, 'Stripe is down', 'api_error'));

        $answer = app(Quantities::class)->sync($client, $feature);
        $this->assertFalse($answer['ok']);
        $this->assertSame('stripe', $answer['reason']);
    }

    private function subscribed($client = null, $feature = null): array
    {
        $client ??= $this->client();
        $feature ??= $this->feature();
        $this->mapCustomer($client, 'cus_1');
        $this->stripeHasSubscription('sub_1', $feature, 'cus_1');

        $row = new BillingSubscription();
        $row->forceFill([
            'billable_type' => 'client',
            'billable_id' => $client->id,
            'feature_id' => $feature->id,
            'stripe_subscription_id' => 'sub_1',
            'stripe_customer_id' => 'cus_1',
            'stripe_price_id' => $feature->stripe_price_monthly_id,
            'interval' => 'month',
            'status' => 'active',
            'livemode' => false,
            'first_paid_at' => now(),
            'current_period_end' => now()->addMonth(),
        ])->save();

        return [$client, $feature];
    }
}
