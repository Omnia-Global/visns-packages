<?php

namespace Visnsstudio\VisnsPackages\Tests\Platform\Billing;

use Illuminate\Support\Facades\Event;
use Visnsstudio\VisnsPackages\Events\BillingAttention;
use Visnsstudio\VisnsPackages\Models\BillingCheckout;
use Visnsstudio\VisnsPackages\Models\BillingCustomer;
use Visnsstudio\VisnsPackages\Models\BillingEntitlementLog;
use Visnsstudio\VisnsPackages\Models\BillingEvent;
use Visnsstudio\VisnsPackages\Models\BillingSubscription;
use Visnsstudio\VisnsPackages\Services\Billing\Entitlements;
use Visnsstudio\VisnsPackages\Tests\FakeStripe;

class BillingWebhookTest extends BillingTestCase
{
    /* ---------------- signature ---------------- */

    public function test_a_signed_event_is_accepted_and_ledgered(): void
    {
        $response = $this->webhook('product.created', ['id' => 'prod_x', 'object' => 'product']);

        $response->assertOk()->assertJson(['ok' => true, 'outcome' => 'ignored']);
        $this->assertSame(1, BillingEvent::query()->count());
        $this->assertSame('ignored', BillingEvent::query()->first()->outcome);
    }

    public function test_a_tampered_body_is_refused(): void
    {
        $event = $this->stripeEvent('invoice.paid', FakeStripe::invoice('in_1', 'sub_1'));
        $signature = $this->stripeSignature(json_encode($event), self::SECRET);
        $event['data']['object']['amount_paid'] = 1;

        $this->postStripeEvent($event, self::SECRET, null, null, $signature)->assertStatus(400);
        $this->assertSame(0, BillingEvent::query()->count());
    }

    public function test_a_signature_from_another_secret_is_refused(): void
    {
        $this->postStripeEvent($this->stripeEvent('invoice.paid', FakeStripe::invoice('in_1', 'sub_1')), 'whsec_somebody_else')
            ->assertStatus(400);
    }

    public function test_a_stale_signature_is_refused(): void
    {
        $this->postStripeEvent($this->stripeEvent('invoice.paid', FakeStripe::invoice('in_1', 'sub_1')), self::SECRET, time() - 301)
            ->assertStatus(400);

        $this->postStripeEvent($this->stripeEvent('product.created', ['id' => 'prod_x', 'object' => 'product']), self::SECRET, time() - 200)
            ->assertOk();
    }

    public function test_no_secret_configured_refuses_everything(): void
    {
        $this->configureStripe(['webhook_secret' => '']);

        $this->webhook('invoice.paid', FakeStripe::invoice('in_1', 'sub_1'))->assertStatus(401);
        $this->assertSame(0, BillingEvent::query()->count());
    }

    public function test_the_webhook_needs_no_session_or_csrf(): void
    {
        // A web POST without a token would be 419 before anything ran.
        $this->webhook('product.created', ['id' => 'prod_x', 'object' => 'product'])->assertOk();
    }

    /* ---------------- idempotency and ordering ---------------- */

    public function test_a_redelivered_event_is_a_duplicate_and_changes_nothing(): void
    {
        $client = $this->client();
        $feature = $this->feature();
        $this->mapCustomer($client, 'cus_1');
        $this->stripeHasSubscription('sub_1', $feature, 'cus_1');

        $event = $this->stripeEvent('invoice.paid', FakeStripe::invoice('in_1', 'sub_1'));

        $this->postStripeEvent($event, self::SECRET)->assertOk()->assertJson(['outcome' => 'applied']);
        $this->postStripeEvent($event, self::SECRET)->assertOk()->assertJson(['outcome' => 'duplicate']);

        $this->assertSame(1, BillingEvent::query()->count());
        $this->assertSame(2, BillingEvent::query()->first()->deliveries);
        $this->assertSame(1, BillingSubscription::query()->count());
        $this->assertSame(1, $this->stripe->count('GET', '/v1/subscriptions/*'));
        $this->assertSame(1, BillingEntitlementLog::query()->count());
    }

    public function test_a_failed_event_answers_500_and_its_redelivery_runs_again(): void
    {
        $client = $this->client();
        $feature = $this->feature();
        $this->mapCustomer($client, 'cus_1');

        // Stripe has no such subscription yet: the handler throws.
        $event = $this->stripeEvent('invoice.paid', FakeStripe::invoice('in_1', 'sub_1'));
        $this->postStripeEvent($event, self::SECRET)->assertStatus(500);

        $row = BillingEvent::query()->first();
        $this->assertSame('failed', $row->outcome);
        $this->assertStringContainsString('InvalidRequestException', $row->error);

        $this->stripeHasSubscription('sub_1', $feature, 'cus_1');
        $this->postStripeEvent($event, self::SECRET)->assertOk()->assertJson(['outcome' => 'applied']);

        $this->assertSame('applied', $row->refresh()->outcome);
        $this->assertTrue(app(Entitlements::class)->has($client, 'portal_messaging'));
    }

    public function test_invoice_paid_before_checkout_completed_converges(): void
    {
        $client = $this->client();
        $feature = $this->feature();
        $this->mapCustomer($client, 'cus_1');
        $checkout = $this->openCheckout($client, $feature, 'cs_test_9');
        $this->stripeHasSubscription('sub_1', $feature, 'cus_1');
        $this->stripeHasSession('cs_test_9', 'sub_1', 'paid', ['client_reference_id' => (string) $checkout->id]);

        // The renewal-shaped event first.
        $this->webhook('invoice.paid', FakeStripe::invoice('in_1', 'sub_1'))->assertOk();
        $this->assertTrue(app(Entitlements::class)->has($client, 'portal_messaging'));

        $this->webhook('checkout.session.completed', $this->sessions['cs_test_9'])->assertOk()->assertJson(['outcome' => 'applied']);

        $this->assertSame(1, BillingSubscription::query()->count());
        $this->assertSame('complete', $checkout->refresh()->status);
        $this->assertNotNull($checkout->fulfilled_at);
        $this->assertSame('sub_1', $checkout->stripe_subscription_id);
        $this->assertSame(1, BillingEntitlementLog::query()->where('state', 'on')->count());
    }

    public function test_events_resolve_through_the_graph_not_metadata(): void
    {
        $client = $this->client();
        $stranger = $this->client('Somebody Else');
        $feature = $this->feature();
        $this->mapCustomer($client, 'cus_1');

        // Metadata names another client; the customer mapping wins.
        $this->stripeHasSubscription('sub_1', $feature, 'cus_1', 'active', ['metadata' => ['billable_type' => 'client', 'billable_id' => (string) $stranger->id]]);

        $this->webhook('invoice.paid', FakeStripe::invoice('in_1', 'sub_1'))->assertOk();

        $row = BillingSubscription::query()->first();
        $this->assertSame($client->id, $row->billable_id);
        $this->assertFalse(app(Entitlements::class)->has($stranger, 'portal_messaging'));
    }

    public function test_metadata_is_the_fallback_when_the_graph_has_no_answer(): void
    {
        $client = $this->client();
        $feature = $this->feature();

        $this->stripeHasSubscription('sub_1', $feature, 'cus_unknown', 'active', ['metadata' => ['billable_type' => 'client', 'billable_id' => (string) $client->id]]);
        $this->webhook('invoice.paid', FakeStripe::invoice('in_1', 'sub_1'))->assertOk()->assertJson(['outcome' => 'applied']);

        $this->assertTrue(app(Entitlements::class)->has($client, 'portal_messaging'));
    }

    public function test_an_unmappable_subscription_is_ignored(): void
    {
        $feature = $this->feature();
        $this->stripeHasSubscription('sub_1', $feature, 'cus_unknown');

        $this->webhook('invoice.paid', FakeStripe::invoice('in_1', 'sub_1'))->assertOk()->assertJson(['outcome' => 'ignored']);
        $this->assertSame(0, BillingSubscription::query()->count());
    }

    /* ---------------- fulfilment ---------------- */

    public function test_a_completed_but_unpaid_checkout_grants_nothing_until_the_debit_clears(): void
    {
        $client = $this->client();
        $feature = $this->feature();
        $this->mapCustomer($client, 'cus_1');
        $checkout = $this->openCheckout($client, $feature, 'cs_test_9');

        // BECS: the session completes, the money has not moved.
        $this->stripeHasSubscription('sub_1', $feature, 'cus_1', 'active');
        $this->stripeHasSession('cs_test_9', 'sub_1', 'unpaid', ['status' => 'complete', 'client_reference_id' => (string) $checkout->id]);

        $this->webhook('checkout.session.completed', $this->sessions['cs_test_9'])->assertOk();
        $this->webhook('customer.subscription.created', $this->subscriptions['sub_1'])->assertOk();

        $this->assertFalse(app(Entitlements::class)->has($client, 'portal_messaging'));
        $this->assertSame('open', $checkout->refresh()->status);
        $this->assertSame('unpaid', $checkout->payment_status);

        // The debit clears.
        $this->sessions['cs_test_9']['payment_status'] = 'paid';
        $this->webhook('checkout.session.async_payment_succeeded', $this->sessions['cs_test_9'])->assertOk();

        $this->assertTrue(app(Entitlements::class)->has($client, 'portal_messaging'));
        $this->assertSame('complete', $checkout->refresh()->status);
    }

    public function test_an_async_payment_failure_is_recorded_and_grants_nothing(): void
    {
        $client = $this->client();
        $feature = $this->feature();
        $this->mapCustomer($client, 'cus_1');
        $checkout = $this->openCheckout($client, $feature, 'cs_test_9');
        $this->stripeHasSession('cs_test_9', 'sub_1', 'unpaid', ['status' => 'complete', 'client_reference_id' => (string) $checkout->id]);

        $this->webhook('checkout.session.async_payment_failed', $this->sessions['cs_test_9'])->assertOk();

        $this->assertSame('failed', $checkout->refresh()->status);
        $this->assertSame('unpaid', $checkout->payment_status);
        $this->assertFalse(app(Entitlements::class)->has($client, 'portal_messaging'));
    }

    public function test_an_expired_checkout_is_closed(): void
    {
        $client = $this->client();
        $feature = $this->feature();
        $checkout = $this->openCheckout($client, $feature, 'cs_test_9');

        $this->webhook('checkout.session.expired', FakeStripe::checkoutSession('cs_test_9', 'unpaid', ['status' => 'expired']))->assertOk();

        $this->assertSame('expired', $checkout->refresh()->status);
    }

    /* ---------------- the entitlement rule ---------------- */

    public function test_past_due_keeps_access_and_canceled_ends_it(): void
    {
        [$client] = $this->paidSubscriber();

        $this->subscriptions['sub_1']['status'] = 'past_due';
        $this->webhook('invoice.payment_failed', FakeStripe::invoice('in_2', 'sub_1', 'open', 0))->assertOk();

        $this->assertSame('past_due', BillingSubscription::query()->first()->status);
        $this->assertTrue(app(Entitlements::class)->has($client, 'portal_messaging'));

        $this->subscriptions['sub_1']['status'] = 'canceled';
        $this->subscriptions['sub_1']['canceled_at'] = time();
        $this->webhook('customer.subscription.deleted', $this->subscriptions['sub_1'])->assertOk();

        $this->assertFalse(app(Entitlements::class)->has($client, 'portal_messaging'));
        $this->assertSame(['on', 'off'], BillingEntitlementLog::query()->orderBy('id')->pluck('state')->all());
    }

    public function test_unpaid_ends_access(): void
    {
        [$client] = $this->paidSubscriber();

        $this->subscriptions['sub_1']['status'] = 'unpaid';
        $this->webhook('customer.subscription.updated', $this->subscriptions['sub_1'])->assertOk();

        $this->assertFalse(app(Entitlements::class)->has($client, 'portal_messaging'));
        $this->assertSame('unpaid', app(Entitlements::class)->why($client, 'portal_messaging')['status']);
    }

    public function test_handlers_read_stripe_not_the_event_snapshot(): void
    {
        [$client] = $this->paidSubscriber();

        // A late, stale "canceled" snapshot; Stripe says it is active.
        $stale = $this->subscriptions['sub_1'];
        $stale['status'] = 'canceled';
        $this->webhook('customer.subscription.updated', $stale)->assertOk();

        $this->assertSame('active', BillingSubscription::query()->first()->status);
        $this->assertTrue(app(Entitlements::class)->has($client, 'portal_messaging'));
    }

    public function test_a_scheduled_cancel_is_mirrored_and_keeps_access(): void
    {
        [$client] = $this->paidSubscriber();
        $end = $this->subscriptions['sub_1']['items']['data'][0]['current_period_end'];

        $this->subscriptions['sub_1']['cancel_at_period_end'] = true;
        $this->webhook('customer.subscription.updated', $this->subscriptions['sub_1'])->assertOk();

        $row = BillingSubscription::query()->first();
        $this->assertSame($end, $row->cancel_at->getTimestamp());
        $this->assertTrue(app(Entitlements::class)->has($client, 'portal_messaging'));
    }

    public function test_a_lapsed_period_ends_access_even_if_the_status_lags(): void
    {
        [$client] = $this->paidSubscriber();

        $this->travel(31)->days();

        $this->assertFalse(app(Entitlements::class)->has($client, 'portal_messaging'));
    }

    public function test_a_sandbox_subscription_never_counts_on_a_live_key(): void
    {
        [$client] = $this->paidSubscriber();

        $this->configureStripe(['restricted_key' => 'rk_live_51Live', 'livemode' => 'live']);

        $this->assertFalse(app(Entitlements::class)->has($client, 'portal_messaging'));
    }

    /* ---------------- money trouble and customer details ---------------- */

    public function test_a_dispute_resolves_to_the_subscription_and_revokes_nothing(): void
    {
        Event::fake([BillingAttention::class]);
        [$client] = $this->paidSubscriber();

        $this->stripe->on('GET', '/v1/invoice_payments', fn ($p) => ($p['payment']['payment_intent'] ?? null) === 'pi_1'
            ? ['object' => 'list', 'data' => [['id' => 'inpay_1', 'object' => 'invoice_payment', 'invoice' => 'in_1', 'status' => 'paid']]]
            : ['object' => 'list', 'data' => []]);
        $this->invoices['in_1'] = FakeStripe::invoice('in_1', 'sub_1');

        $this->webhook('charge.dispute.created', ['id' => 'dp_1', 'object' => 'dispute', 'charge' => 'ch_1', 'payment_intent' => 'pi_1', 'livemode' => false])
            ->assertOk()->assertJson(['outcome' => 'applied']);

        Event::assertDispatched(BillingAttention::class, fn ($e) => $e->kind === 'disputed' && $e->stripeSubscriptionId === 'sub_1' && $e->billableId === $client->id);
        $this->assertTrue(app(Entitlements::class)->has($client, 'portal_messaging'));
        $this->assertSame('sub_1', BillingEvent::query()->where('type', 'charge.dispute.created')->first()->object_ids['subscription']);
    }

    public function test_a_refund_and_a_fraud_warning_raise_attention(): void
    {
        Event::fake([BillingAttention::class]);
        $this->paidSubscriber();

        $this->stripe->on('GET', '/v1/charges/ch_1', ['id' => 'ch_1', 'object' => 'charge', 'payment_intent' => 'pi_1']);
        $this->stripe->on('GET', '/v1/invoice_payments', ['object' => 'list', 'data' => [['id' => 'inpay_1', 'object' => 'invoice_payment', 'invoice' => 'in_1', 'status' => 'paid']]]);
        $this->invoices['in_1'] = FakeStripe::invoice('in_1', 'sub_1');

        $this->webhook('charge.refunded', ['id' => 'ch_1', 'object' => 'charge', 'payment_intent' => 'pi_1', 'livemode' => false])->assertOk();
        $this->webhook('radar.early_fraud_warning.created', ['id' => 'issfr_1', 'object' => 'radar.early_fraud_warning', 'charge' => 'ch_1', 'payment_intent' => null, 'livemode' => false])->assertOk();

        Event::assertDispatched(BillingAttention::class, fn ($e) => $e->kind === 'refunded' && $e->stripeSubscriptionId === 'sub_1');
        Event::assertDispatched(BillingAttention::class, fn ($e) => $e->kind === 'fraud_warning' && $e->stripeSubscriptionId === 'sub_1');
    }

    public function test_customer_updated_mirrors_only_name_and_email(): void
    {
        $client = $this->client();
        $mapping = $this->mapCustomer($client, 'cus_1');

        $this->webhook('customer.updated', FakeStripe::customer('cus_1', ['email' => 'accounts@harbourside.test', 'name' => 'Harbourside Pty Ltd', 'address' => ['line1' => '1 Hay St']]))
            ->assertOk()->assertJson(['outcome' => 'applied']);

        $mapping->refresh();
        $this->assertSame('accounts@harbourside.test', $mapping->email);
        $this->assertSame('Harbourside Pty Ltd', $mapping->name);
        $this->assertSame('cus_1', $mapping->stripe_customer_id);
        $this->assertSame(1, BillingCustomer::query()->count());
    }

    /* ------------------------------------------------------------------- */

    /** A client with a paid, mirrored subscription sub_1 on cus_1. */
    protected function paidSubscriber(): array
    {
        $client = $this->client();
        $feature = $this->feature();
        $this->mapCustomer($client, 'cus_1');
        $this->stripeHasSubscription('sub_1', $feature, 'cus_1');

        $this->webhook('invoice.paid', FakeStripe::invoice('in_1', 'sub_1'))->assertOk();
        $this->assertTrue(app(Entitlements::class)->has($client, 'portal_messaging'));

        return [$client, $feature];
    }

    protected function openCheckout($client, $feature, string $sessionId): BillingCheckout
    {
        $checkout = new BillingCheckout();
        $checkout->forceFill([
            'billable_type' => 'client',
            'billable_id' => $client->id,
            'feature_id' => $feature->id,
            'interval' => 'month',
            'status' => 'open',
            'livemode' => false,
            'stripe_session_id' => $sessionId,
        ])->save();

        return $checkout;
    }
}
