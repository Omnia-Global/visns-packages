<?php

namespace Visnsstudio\VisnsPackages\Tests\Platform\Billing;

use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use Stripe\StripeClient;
use Visnsstudio\VisnsPackages\Models\BillingComp;
use Visnsstudio\VisnsPackages\Models\BillingEntitlementLog;
use Visnsstudio\VisnsPackages\Models\BillingFeature;
use Visnsstudio\VisnsPackages\Models\IntegrationSetting;
use Visnsstudio\VisnsPackages\Services\Billing\BillingCatalogue;
use Visnsstudio\VisnsPackages\Services\Billing\Comps;
use Visnsstudio\VisnsPackages\Services\Billing\Entitlements;
use Visnsstudio\VisnsPackages\Services\Billing\Probes;
use Visnsstudio\VisnsPackages\Services\Billing\StripeClientFactory;
use Visnsstudio\VisnsPackages\Services\IntegrationRegistry;
use Visnsstudio\VisnsPackages\Tests\FakeStripe;

class BillingStaffTest extends BillingTestCase
{
    /* ---------------- catalogue sync ---------------- */

    public function test_sync_creates_the_product_and_its_prices(): void
    {
        $feature = $this->newFeature(9900, 99000);
        $this->scriptCatalogue();

        $done = app(BillingCatalogue::class)->sync($feature);

        $this->assertSame(['product' => 'created', 'monthly' => 'created', 'annual' => 'created'], $done);
        $feature->refresh();
        $this->assertSame('prod_1', $feature->stripe_product_id);
        $this->assertNotNull($feature->stripe_price_monthly_id);
        $this->assertNotNull($feature->stripe_price_annual_id);
        $this->assertFalse($feature->stripe_livemode);
        $this->assertNotNull($feature->synced_at);

        $monthly = $this->stripe->sent('POST', '/v1/prices');
        $this->assertSame('prod_1', $monthly['product']);
        $this->assertSame('exclusive', $monthly['tax_behavior']);
        $this->assertSame('visns_fresha_annual', $monthly['lookup_key']);
    }

    public function test_a_changed_amount_makes_a_new_price_and_archives_the_old(): void
    {
        $feature = $this->newFeature(9900, null);
        $this->scriptCatalogue();
        app(BillingCatalogue::class)->sync($feature);
        $old = $feature->refresh()->stripe_price_monthly_id;

        $feature->fill(['monthly_amount_cents' => 12900])->save();
        $done = app(BillingCatalogue::class)->sync($feature);

        $this->assertSame('replaced', $done['monthly']);
        $this->assertNotSame($old, $feature->refresh()->stripe_price_monthly_id);
        $this->assertSame(['active' => 'false'], $this->stripe->sent('POST', '/v1/prices/' . $old));
        $this->assertSame(12900, $this->prices[$feature->stripe_price_monthly_id]['unit_amount']);
        $this->assertFalse($this->prices[$old]['active']);
    }

    public function test_an_unchanged_feature_creates_nothing(): void
    {
        $feature = $this->newFeature(9900, 99000);
        $this->scriptCatalogue();
        app(BillingCatalogue::class)->sync($feature);
        $created = $this->stripe->count('POST', '/v1/prices');

        $done = app(BillingCatalogue::class)->sync($feature->refresh());

        $this->assertSame(['product' => 'updated', 'monthly' => 'unchanged', 'annual' => 'unchanged'], $done);
        $this->assertSame($created, $this->stripe->count('POST', '/v1/prices'));
    }

    public function test_dropping_the_annual_amount_archives_the_annual_price(): void
    {
        $feature = $this->newFeature(9900, 99000);
        $this->scriptCatalogue();
        app(BillingCatalogue::class)->sync($feature);
        $annual = $feature->refresh()->stripe_price_annual_id;

        $feature->fill(['annual_amount_cents' => null])->save();
        $this->assertSame('archived', app(BillingCatalogue::class)->sync($feature)['annual']);
        $this->assertNull($feature->refresh()->stripe_price_annual_id);
        $this->assertFalse($this->prices[$annual]['active']);
    }

    /* ---------------- staff API ---------------- */

    public function test_the_staff_api_needs_the_manage_permission(): void
    {
        $this->actingAs($this->staff())->getJson('/ajax/billing/features')->assertStatus(403);
        $this->actingAs($this->staff('Settings'))->getJson('/ajax/billing/features')->assertOk();
    }

    public function test_staff_create_edit_and_sync_a_feature(): void
    {
        $staff = $this->staff('Settings');
        $this->scriptCatalogue();

        $created = $this->actingAs($staff)->postJson('/ajax/billing/features', [
            'key' => 'fresha_bookings',
            'name' => 'Fresha bookings',
            'monthly_amount_cents' => 9900,
            'available' => false,
            // Not fillable: ignored.
            'stripe_product_id' => 'prod_forged',
        ])->assertStatus(201);

        $id = $created->json('data.id');
        $this->assertNull($created->json('data.stripe_product_id'));
        $this->assertSame('aud', $created->json('data.currency'));
        $this->assertTrue($created->json('data.needs_sync'));

        $this->actingAs($staff)->postJson('/ajax/billing/features', ['key' => 'Bad Key', 'name' => 'x', 'monthly_amount_cents' => 9900])->assertStatus(422);
        $this->actingAs($staff)->postJson('/ajax/billing/features', ['key' => 'cheap', 'name' => 'x', 'monthly_amount_cents' => 10])->assertStatus(422);

        $this->actingAs($staff)->postJson("/ajax/billing/features/{$id}", ['name' => 'Fresha online bookings', 'key' => 'renamed'])
            ->assertOk()->assertJsonPath('data.name', 'Fresha online bookings')->assertJsonPath('data.key', 'fresha_bookings');

        $this->actingAs($staff)->postJson("/ajax/billing/features/{$id}/sync")
            ->assertOk()->assertJson(['ok' => true, 'done' => ['product' => 'created', 'monthly' => 'created', 'annual' => 'none']])
            ->assertJsonPath('data.needs_sync', false);
    }

    public function test_sync_without_stripe_says_unconfigured(): void
    {
        $this->configureStripe(['restricted_key' => '']);
        $feature = $this->newFeature(9900, null);

        $this->actingAs($this->staff('Settings'))->postJson("/ajax/billing/features/{$feature->id}/sync")
            ->assertOk()->assertExactJson(['ok' => false, 'reason' => 'unconfigured']);
    }

    public function test_staff_grant_and_end_a_comp_and_the_rows_stay(): void
    {
        $staff = $this->staff('Settings');
        $client = $this->client();
        $this->feature();

        $this->actingAs($staff)->postJson("/ajax/billing/billables/client/{$client->id}/comps", ['feature' => 'portal_messaging'])->assertStatus(422);

        $comp = $this->actingAs($staff)->postJson("/ajax/billing/billables/client/{$client->id}/comps", [
            'feature' => 'portal_messaging',
            'reason' => 'Enabled before billing',
        ])->assertStatus(201)->json('data');

        $this->assertTrue(app(Entitlements::class)->has($client, 'portal_messaging'));
        $this->assertSame($staff->id, BillingComp::query()->find($comp['id'])->granted_by);

        $this->actingAs($staff)->postJson("/ajax/billing/billables/client/{$client->id}/comps", ['feature' => 'portal_messaging', 'reason' => 'Again'])
            ->assertStatus(409);

        $this->actingAs($staff)->getJson("/ajax/billing/billables/client/{$client->id}")
            ->assertOk()->assertJsonPath('data.entitlements.portal_messaging.source', 'comp');

        $this->actingAs($staff)->postJson("/ajax/billing/comps/{$comp['id']}/end")->assertOk();
        $this->actingAs($staff)->postJson("/ajax/billing/comps/{$comp['id']}/end")->assertStatus(409);

        $this->assertFalse(app(Entitlements::class)->has($client, 'portal_messaging'));
        $this->assertSame(1, BillingComp::query()->count());
        $this->assertNotNull(BillingComp::query()->first()->ends_at);
        $this->assertSame(['on', 'off'], BillingEntitlementLog::query()->orderBy('id')->pluck('state')->all());
    }

    public function test_a_comp_with_an_end_date_lapses_by_itself(): void
    {
        $client = $this->client();
        $feature = $this->feature();

        app(Comps::class)->grant($client, $feature, 'Trial for a month', now()->addDays(30));
        $this->assertTrue(app(Entitlements::class)->has($client, 'portal_messaging'));

        $this->travel(31)->days();
        $this->assertFalse(app(Entitlements::class)->has($client, 'portal_messaging'));
    }

    public function test_a_comp_is_refused_while_stripe_bills_for_it(): void
    {
        $client = $this->client();
        $feature = $this->feature();
        $this->mapCustomer($client, 'cus_1');
        $this->stripeHasSubscription('sub_1', $feature, 'cus_1');
        $this->webhook('invoice.paid', FakeStripe::invoice('in_1', 'sub_1'))->assertOk();

        $this->actingAs($this->staff('Settings'))
            ->postJson("/ajax/billing/billables/client/{$client->id}/comps", ['feature' => 'portal_messaging', 'reason' => 'Goodwill'])
            ->assertStatus(409)->assertJson(['message' => 'Portal Messaging is already paid for through Stripe.']);
    }

    public function test_an_unknown_billable_is_404(): void
    {
        $staff = $this->staff('Settings');

        $this->actingAs($staff)->getJson('/ajax/billing/billables/client/999')->assertStatus(404);
        $this->actingAs($staff)->getJson('/ajax/billing/billables/user/' . $staff->id)->assertStatus(404);
    }

    public function test_the_ledger_lists_events_without_payloads(): void
    {
        $this->webhook('product.created', ['id' => 'prod_x', 'object' => 'product', 'name' => 'Secret plan name']);

        $page = $this->actingAs($this->staff('Settings'))->getJson('/ajax/billing/events')->assertOk();

        $this->assertSame('product.created', $page->json('data.0.type'));
        $this->assertSame(['object' => 'prod_x'], $page->json('data.0.object_ids'));
        $this->assertStringNotContainsString('Secret plan name', $page->getContent());
    }

    public function test_status_reports_the_connection_without_secrets(): void
    {
        $response = $this->actingAs($this->staff('Settings'))->getJson('/ajax/billing/status')->assertOk();

        $response->assertJson(['data' => ['configured' => true, 'livemode' => false, 'webhook_secret_set' => true, 'api_version' => '2026-10-28.endive', 'webhook_url' => 'https://crm.example.test/api/billing/stripe/webhook']]);
        $this->assertStringNotContainsString(self::KEY, $response->getContent());
        $this->assertStringNotContainsString(self::SECRET, $response->getContent());
    }

    /* ---------------- integration settings ---------------- */

    public function test_the_package_declares_the_stripe_integration(): void
    {
        $definition = app(IntegrationRegistry::class)->definition('stripe');

        $this->assertSame('api_key', $definition['driver']);
        $this->assertSame(['restricted_key', 'webhook_secret', 'livemode', 'tax_rate_id', 'portal_configuration_id'], array_keys($definition['fields']));
        $this->assertTrue($definition['fields']['restricted_key']['secret']);
    }

    public function test_a_secret_key_is_refused_at_save_time(): void
    {
        $admin = $this->staff('manage integrations');

        $this->actingAs($admin)->putJson('/ajax/integrations/stripe', ['credentials' => ['restricted_key' => 'sk_live_51Danger']])
            ->assertStatus(422)->assertJsonPath('message', 'That is a secret key (sk_). Create a restricted key (rk_) in the Stripe Dashboard with only the permissions billing needs, and paste that instead.');

        $this->assertSame(self::KEY, IntegrationSetting::forProvider('stripe')->credential('restricted_key'));

        $this->actingAs($admin)->putJson('/ajax/integrations/stripe', ['credentials' => ['restricted_key' => 'rk_live_51Fine']])
            ->assertStatus(422)->assertJsonPath('message', 'The key is a live key but the mode says test. Choose live, or paste the sandbox key.');

        $this->actingAs($admin)->putJson('/ajax/integrations/stripe', ['credentials' => ['restricted_key' => 'rk_live_51Fine', 'livemode' => 'live']])
            ->assertOk();

        $this->assertTrue(app(StripeClientFactory::class)->livemode());
    }

    public function test_a_secret_key_from_anywhere_never_builds_a_client(): void
    {
        $this->configureStripe(['restricted_key' => 'sk_test_51Danger']);

        $this->assertFalse(app(StripeClientFactory::class)->configured());
        $this->expectException(\Visnsstudio\VisnsPackages\Exceptions\Billing\BillingUnconfigured::class);
        app(StripeClientFactory::class)->make();
    }

    public function test_the_probe_reads_the_balance_and_checks_the_gst_rate(): void
    {
        $this->stripe->on('GET', '/v1/balance', ['object' => 'balance', 'available' => [], 'pending' => []]);
        $this->stripe->on('GET', '/v1/tax_rates/txr_gst', ['id' => 'txr_gst', 'object' => 'tax_rate', 'active' => true, 'percentage' => 10.0, 'inclusive' => false, 'country' => 'AU']);
        $this->stripe->on('GET', '/v1/tax_rates/txr_inc', ['id' => 'txr_inc', 'object' => 'tax_rate', 'active' => true, 'percentage' => 10.0, 'inclusive' => true]);

        $ok = Probes::stripe(['restricted_key' => self::KEY, 'webhook_secret' => self::SECRET, 'tax_rate_id' => 'txr_gst']);
        $this->assertTrue($ok['success']);
        $this->assertSame('Connected to Stripe in test mode. GST rate txr_gst is 10% exclusive.', $ok['message']);

        $inclusive = Probes::stripe(['restricted_key' => self::KEY, 'webhook_secret' => self::SECRET, 'tax_rate_id' => 'txr_inc']);
        $this->assertFalse($inclusive['success']);

        $secret = Probes::stripe(['restricted_key' => 'sk_test_51Danger', 'webhook_secret' => self::SECRET]);
        $this->assertFalse($secret['success']);
        $this->assertStringContainsString('secret key', $secret['message']);

        $this->actingAs($this->staff('Settings'))->postJson('/ajax/billing/probe')->assertOk()->assertJson(['ok' => true]);
    }

    public function test_the_probe_explains_a_missing_permission(): void
    {
        $this->stripe->on('GET', '/v1/balance', FakeStripe::error(403, 'The provided key does not have the required permissions', 'invalid_request_error'));

        $result = Probes::stripe(['restricted_key' => self::KEY, 'webhook_secret' => self::SECRET]);

        $this->assertFalse($result['success']);
        $this->assertSame('The key cannot read the balance. Give the restricted key Balance read.', $result['message']);
    }

    /* ---------------- secrets never reach logs ---------------- */

    public function test_logs_carry_no_keys_secrets_urls_or_payloads(): void
    {
        $logged = [];
        Event::listen(MessageLogged::class, function (MessageLogged $m) use (&$logged) {
            $logged[] = $m->message . ' ' . json_encode($m->context);
        });

        $client = $this->client();
        $feature = $this->feature();
        $this->signIn($client);

        $url = $this->postJson(self::PORTAL . '/checkout', ['feature' => 'portal_messaging', 'interval' => 'month'])->json('url');
        $session = \Visnsstudio\VisnsPackages\Models\BillingCheckout::query()->sole()->stripe_session_id;
        $this->stripeHasSubscription('sub_1', $feature, 'cus_test_1');
        $this->sessions[$session] = array_replace($this->sessions[$session], ['payment_status' => 'paid', 'status' => 'complete', 'subscription' => 'sub_1']);
        $this->webhook('checkout.session.completed', $this->sessions[$session] + ['customer_details' => ['email' => 'buyer@harbourside.test']])->assertOk();

        // A refused delivery and a failing one log too.
        $this->postStripeEvent($this->stripeEvent('invoice.paid', FakeStripe::invoice('in_1', 'sub_1')), 'whsec_wrong')->assertStatus(400);
        $this->webhook('invoice.paid', FakeStripe::invoice('in_2', 'sub_missing'))->assertStatus(500);

        Log::info('marker');
        $all = implode("\n", $logged);

        $this->assertStringContainsString('billing.checkout_started', $all);
        $this->assertStringContainsString('billing.webhook_failed', $all);
        foreach ([self::KEY, self::SECRET, $url, 'checkout.stripe.com', 'buyer@harbourside.test', 'harbourside.test', '"data"'] as $needle) {
            $this->assertStringNotContainsString($needle, $all, "A log line carried {$needle}");
        }
    }

    /* ---------------- the fake keeps Stripe's rules ---------------- */

    public function test_the_fake_refuses_payment_method_types(): void
    {
        $client = new StripeClient(['api_key' => self::KEY, 'stripe_version' => StripeClientFactory::API_VERSION]);

        try {
            $client->checkout->sessions->create(['mode' => 'subscription', 'line_items' => [['price' => 'price_1', 'quantity' => 1]], 'payment_method_types' => ['card']]);
            $this->fail('Stripe would refuse it.');
        } catch (\Stripe\Exception\InvalidRequestException $e) {
            $this->assertStringContainsString('payment_method_types', $e->getMessage());
        }

        $this->assertCount(1, $this->stripe->violations);
        $this->stripe->violations = [];
    }

    public function test_the_fake_throws_on_an_unscripted_request_and_refuses_a_price_edit(): void
    {
        $client = new StripeClient(['api_key' => self::KEY, 'stripe_version' => StripeClientFactory::API_VERSION]);

        try {
            $client->payouts->all();
            $this->fail('An unscripted request must throw.');
        } catch (\LogicException $e) {
            $this->assertSame('Unscripted Stripe request: GET /v1/payouts', $e->getMessage());
        }

        try {
            $client->prices->update('price_1', ['unit_amount' => 100]);
        } catch (\Stripe\Exception\InvalidRequestException $e) {
            $this->assertStringContainsString('cannot be changed', $e->getMessage());
        }

        $secretKey = new StripeClient(['api_key' => 'sk_test_51Danger', 'stripe_version' => StripeClientFactory::API_VERSION]);
        try {
            $secretKey->balance->retrieve();
        } catch (\Stripe\Exception\InvalidRequestException $e) {
            $this->assertStringContainsString('restricted keys', $e->getMessage());
        }

        $this->assertCount(3, $this->stripe->violations);
        $this->stripe->violations = [];
    }

    /* ------------------------------------------------------------------- */

    /** @var array<string, array> */
    protected array $prices = [];

    protected function newFeature(int $monthly, ?int $annual): BillingFeature
    {
        $feature = new BillingFeature();
        $feature->forceFill(['key' => 'fresha', 'currency' => 'aud']);
        $feature->fill(['name' => 'Fresha', 'blurb' => 'Online bookings', 'monthly_amount_cents' => $monthly, 'annual_amount_cents' => $annual, 'available' => true]);
        $feature->save();

        return $feature;
    }

    protected function scriptCatalogue(): void
    {
        $products = 0;

        $this->stripe->on('POST', '/v1/products', function ($p) use (&$products) {
            return ['id' => 'prod_' . (++$products), 'object' => 'product', 'name' => $p['name'], 'active' => true];
        });
        $this->stripe->on('POST', '/v1/products/*', fn ($p, $r) => ['id' => basename($r['path']), 'object' => 'product', 'active' => true]);

        $this->stripe->on('POST', '/v1/prices', function ($p) {
            $id = 'price_' . (count($this->prices) + 1);

            return $this->prices[$id] = FakeStripe::price($id, $p['product'], (int) $p['unit_amount'], $p['recurring']['interval']);
        });
        $this->stripe->on('GET', '/v1/prices/*', fn ($p, $r) => $this->prices[basename($r['path'])] ?? FakeStripe::error(404, 'No such price', 'invalid_request_error', 'resource_missing'));
        $this->stripe->on('POST', '/v1/prices/*', function ($p, $r) {
            $id = basename($r['path']);
            $this->prices[$id]['active'] = ($p['active'] ?? 'true') !== 'false';

            return $this->prices[$id];
        });
    }
}
