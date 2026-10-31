<?php

namespace Visnsstudio\VisnsPackages\Tests\Platform\Billing;

use Visnsstudio\VisnsPackages\Models\BillingCheckout;
use Visnsstudio\VisnsPackages\Models\BillingCustomer;
use Visnsstudio\VisnsPackages\Services\Billing\Checkouts;
use Visnsstudio\VisnsPackages\Services\Billing\Comps;
use Visnsstudio\VisnsPackages\Services\Billing\Entitlements;
use Visnsstudio\VisnsPackages\Tests\FakeStripe;

/** The buyer's side, through a host's subclass of BillingPortalController. */
class BillingCheckoutTest extends BillingTestCase
{
    public function test_subscribe_builds_a_subscription_checkout_by_stripe_rules(): void
    {
        $client = $this->client();
        $feature = $this->feature();
        $this->signIn($client);

        $response = $this->postJson(self::PORTAL . '/checkout', ['feature' => 'portal_messaging', 'interval' => 'month']);

        $response->assertOk()->assertJson(['ok' => true]);
        $this->assertStringStartsWith('https://checkout.stripe.com/', $response->json('url'));

        $checkout = BillingCheckout::query()->sole();
        $params = $this->stripe->sent('POST', '/v1/checkout/sessions');

        $this->assertSame('subscription', $params['mode']);
        $this->assertArrayNotHasKey('payment_method_types', $params);
        $this->assertSame([['price' => 'price_portal_messaging_m', 'quantity' => 1]], $params['line_items']);
        $this->assertSame((string) $checkout->id, $params['client_reference_id']);
        $this->assertMatchesRegularExpression('/^[a-z_]+_[a-z]{8}$/', $params['integration_identifier']);
        $this->assertSame('https://crm.example.test/client/billing?feature=portal_messaging&checkout={CHECKOUT_SESSION_ID}', $params['success_url']);
        $this->assertSame('https://crm.example.test/client/billing', $params['cancel_url']);
        $this->assertSame(['txr_gst'], $params['subscription_data']['default_tax_rates']);
        $this->assertSame((string) $checkout->id, $params['subscription_data']['metadata']['billing_checkout_id']);
        $this->assertSame('portal_messaging', $params['subscription_data']['metadata']['billing_feature_key']);
        $this->assertSame('auto', $params['customer_update']['name']);

        // The customer is created once, for the client, with an idempotency key.
        $customer = BillingCustomer::query()->sole();
        $this->assertSame($customer->stripe_customer_id, $params['customer']);
        $this->assertSame('harbourside-dental', strtolower(str_replace(' ', '-', $customer->name)));
        $this->assertStringStartsWith('billing-customer-client-' . $client->id . '-test', $this->stripe->sentHeaders('POST', '/v1/customers')['idempotency-key']);

        $this->assertSame('cs_test_2', $checkout->stripe_session_id);
        $this->assertSame(77, $checkout->created_by);
        $this->assertFalse($checkout->livemode);

        // The restricted key and the pinned version went over the wire.
        $headers = $this->stripe->sentHeaders('POST', '/v1/checkout/sessions');
        $this->assertSame('Bearer ' . self::KEY, $headers['authorization']);
        $this->assertSame('2026-10-28.endive', $headers['stripe-version']);
    }

    public function test_the_annual_price_is_used_for_a_year(): void
    {
        $this->signIn($this->client());
        $this->feature();

        $this->postJson(self::PORTAL . '/checkout', ['feature' => 'portal_messaging', 'interval' => 'year'])->assertOk();

        $this->assertSame('price_portal_messaging_y', $this->stripe->sent('POST', '/v1/checkout/sessions')['line_items'][0]['price']);
    }

    public function test_refusals_carry_a_sentence(): void
    {
        $client = $this->client();
        $this->signIn($client);
        $this->feature('door_access', 4900, null, false);
        $this->feature('fresha', 9900, null);

        $this->postJson(self::PORTAL . '/checkout', ['feature' => 'door_access', 'interval' => 'month'])
            ->assertStatus(422)->assertJson(['message' => 'Door Access is not available yet.']);

        $this->postJson(self::PORTAL . '/checkout', ['feature' => 'fresha', 'interval' => 'year'])
            ->assertStatus(422)->assertJson(['message' => 'Fresha is not offered annually.']);

        $this->postJson(self::PORTAL . '/checkout', ['feature' => 'nope', 'interval' => 'month'])->assertStatus(404);

        $this->stripe->assertNotSent('POST', '/v1/checkout/sessions');
    }

    public function test_a_feature_not_yet_pushed_to_this_stripe_mode_cannot_be_bought(): void
    {
        $this->signIn($this->client());
        $feature = $this->feature();
        $feature->forceFill(['stripe_livemode' => true])->save();

        $this->postJson(self::PORTAL . '/checkout', ['feature' => 'portal_messaging', 'interval' => 'month'])
            ->assertStatus(422)->assertJson(['message' => 'Portal Messaging is not ready to buy yet.']);
    }

    public function test_already_included_is_a_409(): void
    {
        $client = $this->client();
        $feature = $this->feature();
        $this->signIn($client);
        app(Comps::class)->grant($client, $feature, 'Enabled before billing');

        $this->postJson(self::PORTAL . '/checkout', ['feature' => 'portal_messaging', 'interval' => 'month'])
            ->assertStatus(409)->assertJson(['message' => 'Portal Messaging is already included.']);
    }

    public function test_an_open_checkout_is_resumed_not_duplicated(): void
    {
        $this->signIn($this->client());
        $this->feature();

        $first = $this->postJson(self::PORTAL . '/checkout', ['feature' => 'portal_messaging', 'interval' => 'month'])->json('url');
        $second = $this->postJson(self::PORTAL . '/checkout', ['feature' => 'portal_messaging', 'interval' => 'month'])->json('url');

        $this->assertSame($first, $second);
        $this->assertSame(1, $this->stripe->count('POST', '/v1/checkout/sessions'));
        $this->assertSame(1, BillingCheckout::query()->count());
    }

    public function test_switching_interval_expires_the_open_session(): void
    {
        $this->signIn($this->client());
        $this->feature();

        $this->postJson(self::PORTAL . '/checkout', ['feature' => 'portal_messaging', 'interval' => 'month'])->assertOk();
        $this->postJson(self::PORTAL . '/checkout', ['feature' => 'portal_messaging', 'interval' => 'year'])->assertOk();

        $this->stripe->assertSent('POST', '/v1/checkout/sessions/*/expire');
        $this->assertSame(['expired', 'open'], BillingCheckout::query()->orderBy('id')->pluck('status')->all());
    }

    public function test_a_debit_still_clearing_blocks_a_second_checkout(): void
    {
        $this->signIn($this->client());
        $this->feature();

        $this->postJson(self::PORTAL . '/checkout', ['feature' => 'portal_messaging', 'interval' => 'month'])->assertOk();
        $session = BillingCheckout::query()->sole()->stripe_session_id;
        $this->sessions[$session]['status'] = 'complete';

        $this->postJson(self::PORTAL . '/checkout', ['feature' => 'portal_messaging', 'interval' => 'month'])
            ->assertStatus(409)->assertJson(['message' => 'A payment for Portal Messaging is still being confirmed.']);
    }

    public function test_the_success_poll_fulfils_through_the_same_function(): void
    {
        $client = $this->client();
        $feature = $this->feature();
        $this->signIn($client);

        $this->postJson(self::PORTAL . '/checkout', ['feature' => 'portal_messaging', 'interval' => 'month'])->assertOk();
        $checkout = BillingCheckout::query()->sole();
        $customer = BillingCustomer::query()->sole()->stripe_customer_id;

        $this->getJson(self::PORTAL . '/checkout/' . $checkout->stripe_session_id)
            ->assertOk()->assertJson(['status' => 'open', 'entitled' => false]);

        // Paid, before the webhook arrives.
        $this->stripeHasSubscription('sub_9', $feature, $customer);
        $this->sessions[$checkout->stripe_session_id]['payment_status'] = 'paid';
        $this->sessions[$checkout->stripe_session_id]['status'] = 'complete';
        $this->sessions[$checkout->stripe_session_id]['subscription'] = 'sub_9';

        $this->getJson(self::PORTAL . '/checkout/' . $checkout->stripe_session_id)
            ->assertOk()->assertJson(['status' => 'complete', 'payment_status' => 'paid', 'feature' => 'portal_messaging', 'entitled' => true]);

        // The webhook then changes nothing.
        $this->webhook('checkout.session.completed', $this->sessions[$checkout->stripe_session_id])->assertOk();
        $this->assertTrue(app(Entitlements::class)->has($client, 'portal_messaging'));
        $this->assertSame(1, \Visnsstudio\VisnsPackages\Models\BillingSubscription::query()->count());
    }

    public function test_another_partys_checkout_is_the_same_404_as_an_invented_one(): void
    {
        $mine = $this->client();
        $theirs = $this->client('Other Clinic');
        $this->feature();

        $this->signIn($theirs);
        $this->postJson(self::PORTAL . '/checkout', ['feature' => 'portal_messaging', 'interval' => 'month'])->assertOk();
        $session = BillingCheckout::query()->sole()->stripe_session_id;

        $this->signIn($mine);
        $foreign = $this->getJson(self::PORTAL . '/checkout/' . $session);
        $invented = $this->getJson(self::PORTAL . '/checkout/cs_test_invented');

        $foreign->assertStatus(404);
        $this->assertSame($invented->getContent(), $foreign->getContent());
        $this->assertSame($invented->status(), $foreign->status());
    }

    public function test_a_person_who_may_not_manage_billing_gets_a_sentence(): void
    {
        $this->signIn($this->client(), false);
        $this->feature();

        $this->postJson(self::PORTAL . '/checkout', ['feature' => 'portal_messaging', 'interval' => 'month'])
            ->assertStatus(403)->assertJsonPath('message', 'Only a billing administrator for your organisation can change its subscriptions. Ask your account administrator.');

        $this->postJson(self::PORTAL . '/manage')->assertStatus(403);

        // Still sees the list, read-only.
        $this->getJson(self::PORTAL)->assertOk()->assertJson(['can_manage' => false]);
        $this->stripe->assertNotSent('POST', '/v1/customers');
    }

    public function test_no_party_in_the_session_is_404(): void
    {
        $this->feature();

        $this->getJson(self::PORTAL)->assertStatus(404);
        $this->postJson(self::PORTAL . '/checkout', ['feature' => 'portal_messaging', 'interval' => 'month'])->assertStatus(404);
    }

    public function test_unconfigured_stripe_answers_ok_false(): void
    {
        $this->configureStripe(['restricted_key' => '']);
        $this->signIn($this->client());
        $this->feature();

        $this->postJson(self::PORTAL . '/checkout', ['feature' => 'portal_messaging', 'interval' => 'month'])
            ->assertOk()->assertExactJson(['ok' => false, 'reason' => 'unconfigured']);

        $this->getJson(self::PORTAL)->assertOk()->assertJson(['configured' => false, 'features' => [['key' => 'portal_messaging', 'purchasable' => false]]]);
    }

    public function test_stripe_down_answers_ok_false(): void
    {
        $this->signIn($this->client());
        $this->feature();
        $this->stripe = $this->fakeStripe();
        $this->stripe->on('POST', '/v1/customers', FakeStripe::error(500, 'Something went wrong', 'api_error'));

        $this->postJson(self::PORTAL . '/checkout', ['feature' => 'portal_messaging', 'interval' => 'month'])
            ->assertOk()->assertExactJson(['ok' => false, 'reason' => 'stripe_unavailable']);
    }

    public function test_the_feature_list_says_what_each_costs_and_where_it_stands(): void
    {
        $client = $this->client();
        $this->feature('portal_messaging', 9900, 99000);
        $this->feature('door_access', 4900, null, false);
        $this->signIn($client);
        app(Comps::class)->grant($client, \Visnsstudio\VisnsPackages\Models\BillingFeature::query()->where('key', 'portal_messaging')->first(), 'Launch partner');

        $response = $this->getJson(self::PORTAL)->assertOk();

        $response->assertJson([
            'ok' => true,
            'configured' => true,
            'can_manage' => true,
            'has_billing_account' => false,
            'features' => [
                ['key' => 'portal_messaging', 'monthly_amount_cents' => 9900, 'annual_amount_cents' => 99000, 'intervals' => ['month', 'year'], 'purchasable' => true, 'state' => ['entitled' => true, 'source' => 'comp', 'comp_reason' => 'Launch partner']],
                ['key' => 'door_access', 'available' => false, 'purchasable' => false, 'intervals' => ['month'], 'state' => ['entitled' => false]],
            ],
        ]);
    }

    public function test_manage_billing_opens_the_customer_portal(): void
    {
        $client = $this->client();
        $this->signIn($client);
        $this->postJson(self::PORTAL . '/manage')->assertStatus(422)->assertJson(['message' => 'There is no billing account yet. Subscribe to a feature first.']);

        $this->mapCustomer($client, 'cus_1');
        $this->stripe->on('POST', '/v1/billing_portal/sessions', ['id' => 'bps_1', 'object' => 'billing_portal.session', 'url' => 'https://billing.stripe.com/p/session/test_1']);

        $this->postJson(self::PORTAL . '/manage')->assertOk()->assertJson(['ok' => true, 'url' => 'https://billing.stripe.com/p/session/test_1']);

        $params = $this->stripe->sent('POST', '/v1/billing_portal/sessions');
        $this->assertSame('cus_1', $params['customer']);
        $this->assertSame('https://crm.example.test/portal/portal?section=billing', $params['return_url']);
    }

    public function test_a_success_url_without_the_placeholder_gets_one(): void
    {
        $client = $this->client();
        $feature = $this->feature();

        app(Checkouts::class)->start($client, $feature, 'month', 'https://crm.example.test/done', 'https://crm.example.test/back');

        $this->assertSame('https://crm.example.test/done?checkout={CHECKOUT_SESSION_ID}', $this->stripe->sent('POST', '/v1/checkout/sessions')['success_url']);
    }
}
