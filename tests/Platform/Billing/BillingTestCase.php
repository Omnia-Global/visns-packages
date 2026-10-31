<?php

namespace Visnsstudio\VisnsPackages\Tests\Platform\Billing;

use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\Models\Permission;
use Visnsstudio\VisnsPackages\Models\BillingCustomer;
use Visnsstudio\VisnsPackages\Models\BillingFeature;
use Visnsstudio\VisnsPackages\Models\IntegrationSetting;
use Visnsstudio\VisnsPackages\Tests\FakeStripe;
use Visnsstudio\VisnsPackages\Tests\FakesStripe;
use Visnsstudio\VisnsPackages\Tests\Fixtures\Billing\BillingClient;
use Visnsstudio\VisnsPackages\Tests\Fixtures\Billing\PortalBillingController;
use Visnsstudio\VisnsPackages\Tests\Fixtures\Billing\StubBillableResolver;
use Visnsstudio\VisnsPackages\Tests\TestCase;

/**
 * The billing module against a small in-memory Stripe.
 *
 * `$this->subscriptions` and `$this->sessions` are what Stripe holds; a test
 * changes them to say "Stripe now says this" and the next retrieve answers it.
 */
abstract class BillingTestCase extends TestCase
{
    use FakesStripe;

    protected const KEY = 'rk_test_51AbcDefGhiJklMno';

    protected const SECRET = 'whsec_testsecret0123456789';

    protected const PORTAL = '/client/ajax/billing';

    protected FakeStripe $stripe;

    /** @var array<string, array> id => subscription */
    protected array $subscriptions = [];

    /** @var array<string, array> id => checkout session */
    protected array $sessions = [];

    /** @var array<string, array> id => invoice */
    protected array $invoices = [];

    private int $seq = 0;

    protected function defineEnvironment($app)
    {
        parent::defineEnvironment($app);

        $app['config']->set('visns-packages.billing.enabled', true);
        $app['config']->set('visns-packages.billing.hooks.billable', StubBillableResolver::class);
        $app['config']->set('visns-packages.integrations_permission', 'manage integrations');
    }

    protected function defineDatabaseMigrations()
    {
        parent::defineDatabaseMigrations();

        $this->runPackageMigration('2026_08_24_100000_create_integration_settings_table.php');
        $this->runPackageMigration('2026_10_31_100000_create_billing_tables.php');

        Schema::create('billing_test_clients', function (Blueprint $t) {
            $t->id();
            $t->string('name');
            $t->string('email')->nullable();
            $t->timestamps();
        });
    }

    protected function defineRoutes($router)
    {
        // The host's own portal mount of the package controller.
        Route::middleware('web')->prefix(ltrim(self::PORTAL, '/'))->group(function () {
            Route::get('/', [PortalBillingController::class, 'features']);
            Route::post('/checkout', [PortalBillingController::class, 'checkout']);
            Route::get('/checkout/{session}', [PortalBillingController::class, 'checkoutStatus']);
            Route::post('/manage', [PortalBillingController::class, 'manage']);
        });
    }

    protected function setUp(): void
    {
        parent::setUp();

        StubBillableResolver::reset();
        $this->configureStripe();
        $this->stripe = $this->fakeStripe();
        $this->scriptStripe();
    }

    protected function configureStripe(array $credentials = []): void
    {
        $setting = IntegrationSetting::firstOrNew(['provider' => 'stripe']);
        $setting->credentials = array_merge([
            'restricted_key' => self::KEY,
            'webhook_secret' => self::SECRET,
            'livemode' => 'test',
            'tax_rate_id' => 'txr_gst',
        ], $credentials);
        $setting->is_enabled = true;
        $setting->save();
    }

    /** The Stripe the tests share. Scripts added later answer first only for new paths. */
    protected function scriptStripe(): void
    {
        $this->stripe->on('POST', '/v1/customers', fn ($p) => FakeStripe::customer('cus_test_' . (++$this->seq), [
            'email' => $p['email'] ?? null,
            'name' => $p['name'] ?? null,
        ]));

        $this->stripe->on('POST', '/v1/checkout/sessions/*/expire', function ($p, $r) {
            $id = explode('/', $r['path'])[4];
            $this->sessions[$id]['status'] = 'expired';

            return $this->sessions[$id];
        });

        $this->stripe->on('POST', '/v1/checkout/sessions', function ($p) {
            $id = 'cs_test_' . (++$this->seq);
            $this->sessions[$id] = FakeStripe::checkoutSession($id, 'unpaid', [
                'status' => 'open',
                'client_reference_id' => $p['client_reference_id'] ?? null,
                'customer' => $p['customer'] ?? null,
            ]);

            return $this->sessions[$id];
        });

        $this->stripe->on('GET', '/v1/checkout/sessions/*', function ($p, $r) {
            $id = basename($r['path']);
            $session = $this->sessions[$id] ?? null;

            if (! $session) {
                return FakeStripe::error(404, 'No such checkout.session', 'invalid_request_error', 'resource_missing');
            }

            if (in_array('subscription', (array) ($p['expand'] ?? []), true) && is_string($session['subscription'] ?? null)) {
                $session['subscription'] = $this->subscriptions[$session['subscription']] ?? $session['subscription'];
            }

            return $session;
        });

        $this->stripe->on('GET', '/v1/subscriptions/*', fn ($p, $r) => $this->subscriptions[basename($r['path'])]
            ?? FakeStripe::error(404, 'No such subscription', 'invalid_request_error', 'resource_missing'));

        $this->stripe->on('GET', '/v1/invoices/*', fn ($p, $r) => $this->invoices[basename($r['path'])]
            ?? FakeStripe::error(404, 'No such invoice', 'invalid_request_error', 'resource_missing'));
    }

    protected function client(string $name = 'Harbourside Dental'): BillingClient
    {
        return BillingClient::create(['name' => $name, 'email' => strtolower(str_replace(' ', '', $name)) . '@example.test']);
    }

    /** A catalogue feature already pushed to the sandbox. */
    protected function feature(string $key = 'portal_messaging', int $monthly = 9900, ?int $annual = 99000, bool $available = true): BillingFeature
    {
        $feature = new BillingFeature();
        $feature->forceFill([
            'key' => $key,
            'currency' => 'aud',
            'stripe_product_id' => 'prod_' . $key,
            'stripe_price_monthly_id' => 'price_' . $key . '_m',
            'stripe_price_annual_id' => $annual ? 'price_' . $key . '_y' : null,
            'stripe_livemode' => false,
            'synced_at' => now(),
        ]);
        $feature->fill([
            'name' => ucwords(str_replace('_', ' ', $key)),
            'blurb' => 'A thing worth having.',
            'monthly_amount_cents' => $monthly,
            'annual_amount_cents' => $annual,
            'available' => $available,
        ]);
        $feature->save();

        return $feature;
    }

    protected function mapCustomer(BillingClient $client, string $stripeId = 'cus_mapped'): BillingCustomer
    {
        $row = new BillingCustomer();
        $row->forceFill([
            'billable_type' => 'client',
            'billable_id' => $client->id,
            'stripe_customer_id' => $stripeId,
            'livemode' => false,
        ])->save();

        return $row;
    }

    /** Stripe now holds this subscription for that feature. */
    protected function stripeHasSubscription(string $id, BillingFeature $feature, string $customer, string $status = 'active', array $overrides = []): array
    {
        return $this->subscriptions[$id] = FakeStripe::subscription($id, $customer, $feature->stripe_price_monthly_id, $feature->stripe_product_id, $status, $overrides);
    }

    /** Stripe now holds a session for this subscription, at this payment status. */
    protected function stripeHasSession(string $id, ?string $subscription, string $paymentStatus, array $overrides = []): array
    {
        return $this->sessions[$id] = FakeStripe::checkoutSession($id, $paymentStatus, ['subscription' => $subscription] + $overrides);
    }

    protected function webhook(string $type, array $object, array $overrides = [])
    {
        return $this->postStripeEvent($this->stripeEvent($type, $object, $overrides), self::SECRET);
    }

    protected function staff(string ...$permissions): User
    {
        static $n = 0;
        $n++;

        $user = User::create([
            'name' => 'Sam Staff' . $n,
            'email' => 'sam' . $n . '@omnia.test',
            'password' => Hash::make('correct-horse'),
        ]);

        foreach ($permissions as $permission) {
            Permission::findOrCreate($permission, 'web');
            $user->givePermissionTo($permission);
        }

        return $user;
    }

    protected function signIn(BillingClient $client, bool $mayManage = true): void
    {
        StubBillableResolver::$sessionClientId = $client->id;
        StubBillableResolver::$mayManage = $mayManage;
    }
}
