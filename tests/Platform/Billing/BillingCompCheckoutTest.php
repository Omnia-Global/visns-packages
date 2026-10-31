<?php

namespace Visnsstudio\VisnsPackages\Tests\Platform\Billing;

use Illuminate\Support\Facades\Event;
use Stripe\StripeClient;
use Visnsstudio\VisnsPackages\Events\BillingAttention;
use Visnsstudio\VisnsPackages\Models\BillingCheckout;
use Visnsstudio\VisnsPackages\Models\BillingComp;
use Visnsstudio\VisnsPackages\Models\BillingCustomer;
use Visnsstudio\VisnsPackages\Models\BillingEntitlementLog;
use Visnsstudio\VisnsPackages\Models\BillingEvent;
use Visnsstudio\VisnsPackages\Models\BillingSubscription;
use Visnsstudio\VisnsPackages\Services\Billing\Comps;
use Visnsstudio\VisnsPackages\Services\Billing\Entitlements;
use Visnsstudio\VisnsPackages\Services\Billing\StripeClientFactory;
use Visnsstudio\VisnsPackages\Tests\FakeStripe;

/** Subscribing before a dated comp (the `migrated` notice period) runs out. */
class BillingCompCheckoutTest extends BillingTestCase
{
    public function test_checkout_over_a_dated_comp_starts_billing_when_the_comp_ends(): void
    {
        $client = $this->client();
        $feature = $this->feature();
        $comp = app(Comps::class)->grant($client, $feature, 'Enabled before billing', now()->addDays(30), null, BillingComp::SOURCE_MIGRATED)->refresh();
        $this->signIn($client);

        $list = $this->getJson(self::PORTAL)->assertOk();
        $list->assertJsonPath('features.0.can_subscribe', true)
            ->assertJsonPath('features.0.included_until', $comp->ends_at->toIso8601String())
            ->assertJsonPath('features.0.billing_starts_at', $comp->ends_at->toIso8601String())
            ->assertJsonPath('features.0.state.source', 'comp');

        $this->postJson(self::PORTAL . '/checkout', ['feature' => 'portal_messaging', 'interval' => 'month'])->assertOk()->assertJson(['ok' => true]);

        $params = $this->stripe->sent('POST', '/v1/checkout/sessions');
        $this->assertSame((string) $comp->ends_at->getTimestamp(), (string) $params['subscription_data']['trial_end']);
        $this->assertFalse($this->hasKey($params, 'payment_method_types'));

        $checkout = BillingCheckout::query()->sole();
        $this->assertSame($comp->id, $checkout->comp_id);
        $this->assertSame($comp->ends_at->getTimestamp(), $checkout->trial_end->getTimestamp());
    }

    public function test_a_comp_a_day_from_ending_gets_no_trial(): void
    {
        $client = $this->client();
        $feature = $this->feature();
        app(Comps::class)->grant($client, $feature, 'Enabled before billing', now()->addDay());
        $this->signIn($client);

        $this->getJson(self::PORTAL)->assertOk()
            ->assertJsonPath('features.0.can_subscribe', true)
            ->assertJsonPath('features.0.billing_starts_at', null);

        $this->postJson(self::PORTAL . '/checkout', ['feature' => 'portal_messaging', 'interval' => 'month'])->assertOk();

        $this->assertArrayNotHasKey('trial_end', $this->stripe->sent('POST', '/v1/checkout/sessions')['subscription_data']);
        $this->assertNull(BillingCheckout::query()->sole()->trial_end);
    }

    public function test_an_open_ended_comp_still_refuses_checkout(): void
    {
        $client = $this->client();
        $feature = $this->feature();
        app(Comps::class)->grant($client, $feature, 'Launch partner');
        $this->signIn($client);

        $this->getJson(self::PORTAL)->assertOk()
            ->assertJsonPath('features.0.can_subscribe', false)
            ->assertJsonPath('features.0.included_until', null);

        $this->postJson(self::PORTAL . '/checkout', ['feature' => 'portal_messaging', 'interval' => 'month'])
            ->assertStatus(409)->assertJson(['message' => 'Portal Messaging is already included.']);
        $this->stripe->assertNotSent('POST', '/v1/checkout/sessions');
    }

    public function test_the_comp_bridges_the_trial_and_the_first_payment_ends_it(): void
    {
        $client = $this->client();
        $feature = $this->feature();
        $comp = app(Comps::class)->grant($client, $feature, 'Enabled before billing', now()->addDays(30))->refresh();
        $this->signIn($client);
        $entitled = fn () => app(Entitlements::class)->has($client, 'portal_messaging');

        $this->postJson(self::PORTAL . '/checkout', ['feature' => 'portal_messaging', 'interval' => 'month'])->assertOk();
        $checkout = BillingCheckout::query()->sole();
        $customer = BillingCustomer::query()->sole()->stripe_customer_id;
        $trialEnd = $checkout->trial_end->getTimestamp();
        $this->assertTrue($entitled());

        // The trial checkout completes with nothing to pay.
        $this->stripeHasSubscription('sub_1', $feature, $customer, 'trialing', [
            'trial_end' => $trialEnd,
            'items' => ['data' => [['current_period_end' => $trialEnd]]],
        ]);
        $this->sessions[$checkout->stripe_session_id] = array_replace($this->sessions[$checkout->stripe_session_id], [
            'status' => 'complete', 'payment_status' => 'no_payment_required', 'subscription' => 'sub_1',
        ]);
        $this->webhook('checkout.session.completed', $this->sessions[$checkout->stripe_session_id])->assertOk();

        $this->assertSame('complete', $checkout->refresh()->status);
        $row = BillingSubscription::query()->sole();
        $this->assertSame('trialing', $row->status);
        $this->assertNull($row->first_paid_at);
        $this->assertSame($trialEnd + 7 * 86400, $comp->refresh()->ends_at->getTimestamp());
        $this->assertTrue($entitled());
        $this->assertSame('comp', app(Entitlements::class)->why($client, 'portal_messaging')['source']);

        // A second Subscribe now refuses: Stripe holds a subscription.
        $this->postJson(self::PORTAL . '/checkout', ['feature' => 'portal_messaging', 'interval' => 'month'])->assertStatus(409);
        $this->getJson(self::PORTAL)->assertJsonPath('features.0.can_subscribe', false);

        // The trial ends; the debit takes a few days. The cushion holds access.
        $this->travelTo(\Illuminate\Support\Carbon::createFromTimestamp($trialEnd)->addDays(3));
        $this->assertTrue($entitled());

        // The first real invoice is paid.
        $this->subscriptions['sub_1']['status'] = 'active';
        $this->subscriptions['sub_1']['items']['data'][0]['current_period_end'] = now()->addDays(30)->getTimestamp();
        $this->webhook('invoice.paid', FakeStripe::invoice('in_1', 'sub_1', 'paid', 10890))->assertOk();

        $comp->refresh();
        $this->assertNotNull(BillingSubscription::query()->sole()->first_paid_at);
        $this->assertFalse($comp->ends_at->isFuture());
        $this->assertNull($comp->ended_by);
        $this->assertSame('first_payment', $comp->end_reason);
        $this->assertTrue($entitled());
        $this->assertSame('subscription', app(Entitlements::class)->why($client, 'portal_messaging')['source']);

        // Access never dropped: one "on", no "off".
        $this->assertSame(['on'], BillingEntitlementLog::query()->orderBy('id')->pluck('state')->all());
        $this->assertSame(1, BillingComp::query()->count());
    }

    public function test_no_payment_required_without_our_trial_grants_nothing(): void
    {
        $client = $this->client();
        $feature = $this->feature();
        $this->signIn($client);

        // A plain checkout (no comp, no trial) that Stripe says needed no payment.
        $this->postJson(self::PORTAL . '/checkout', ['feature' => 'portal_messaging', 'interval' => 'month'])->assertOk();
        $checkout = BillingCheckout::query()->sole();
        $this->stripeHasSubscription('sub_1', $feature, BillingCustomer::query()->sole()->stripe_customer_id, 'active');
        $this->sessions[$checkout->stripe_session_id] = array_replace($this->sessions[$checkout->stripe_session_id], [
            'status' => 'complete', 'payment_status' => 'no_payment_required', 'subscription' => 'sub_1',
        ]);

        $this->webhook('checkout.session.completed', $this->sessions[$checkout->stripe_session_id])->assertOk();

        $this->assertSame('open', $checkout->refresh()->status);
        $this->assertSame(0, BillingSubscription::query()->count());
        $this->assertFalse(app(Entitlements::class)->has($client, 'portal_messaging'));
    }

    public function test_a_zero_invoice_does_not_end_the_comp(): void
    {
        $client = $this->client();
        $feature = $this->feature();
        $comp = app(Comps::class)->grant($client, $feature, 'Enabled before billing', now()->addDays(30));
        $this->mapCustomer($client, 'cus_1');
        $this->stripeHasSubscription('sub_1', $feature, 'cus_1', 'trialing');

        $this->webhook('invoice.paid', FakeStripe::invoice('in_0', 'sub_1', 'paid', 0))->assertOk();

        $this->assertNull($comp->refresh()->end_reason);
        $this->assertTrue($comp->isCurrent());
    }

    public function test_a_failed_handler_raises_attention_without_the_payload(): void
    {
        Event::fake([BillingAttention::class]);
        $this->mapCustomer($this->client(), 'cus_1');
        $this->feature();

        $event = $this->stripeEvent('invoice.paid', FakeStripe::invoice('in_1', 'sub_missing'));
        $this->postStripeEvent($event, self::SECRET)->assertStatus(500);

        $this->assertSame('failed', BillingEvent::query()->sole()->outcome);
        Event::assertDispatched(BillingAttention::class, function (BillingAttention $a) use ($event) {
            return $a->kind === 'handler_failed'
                && $a->stripeEventId === $event['id']
                && $a->eventType === 'invoice.paid'
                && $a->stripeObjectId === 'in_1'
                && $a->subscriptionId === null;
        });
    }

    public function test_the_fake_refuses_a_trial_under_48_hours(): void
    {
        $client = new StripeClient(['api_key' => self::KEY, 'stripe_version' => StripeClientFactory::API_VERSION]);

        try {
            $client->checkout->sessions->create([
                'mode' => 'subscription',
                'line_items' => [['price' => 'price_1', 'quantity' => 1]],
                'subscription_data' => ['trial_end' => time() + 3600],
            ]);
            $this->fail('Stripe would refuse it.');
        } catch (\Stripe\Exception\InvalidRequestException $e) {
            $this->assertStringContainsString('48 hours', $e->getMessage());
        }

        $this->stripe->violations = [];
    }

    private function hasKey(array $params, string $key): bool
    {
        foreach ($params as $name => $value) {
            if ($name === $key || (is_array($value) && $this->hasKey($value, $key))) {
                return true;
            }
        }

        return false;
    }
}
