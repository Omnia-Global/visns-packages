<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The billing module's seven tables (Stripe subscriptions).
 *
 * No foreign keys, the messaging module's rule: the mirror, the comps and the
 * two ledgers are evidence of who paid for what, and a cascade from a deleted
 * client or feature would take that evidence with it. The billable party is a
 * morph (`billable_type`, `billable_id`) because the package never names the
 * host's client model. Every table and every later column is guarded, so a
 * re-publish is safe.
 */
return new class extends Migration {
    public function up(): void
    {
        $features = $this->table('features', 'billing_features');
        $customers = $this->table('customers', 'billing_customers');
        $subscriptions = $this->table('subscriptions', 'billing_subscriptions');
        $checkouts = $this->table('checkouts', 'billing_checkouts');
        $comps = $this->table('comps', 'billing_comps');
        $events = $this->table('events', 'billing_events');
        $log = $this->table('entitlement_log', 'billing_entitlement_log');

        if (! Schema::hasTable($features)) {
            Schema::create($features, function (Blueprint $t) {
                $t->id();
                $t->string('key', 64)->unique();
                $t->string('name', 191);
                $t->string('blurb', 1000)->nullable();

                // Ex GST, in the smallest unit of `currency`.
                $t->unsignedInteger('monthly_amount_cents');
                $t->unsignedInteger('annual_amount_cents')->nullable();
                $t->string('currency', 3)->default('aud');

                // Listed but not subscribable ("coming soon") while false.
                $t->boolean('available')->default(false);
                $t->integer('sort_order')->default(0);

                // Written by BillingCatalogue::sync() alone. `stripe_livemode`
                // says which Stripe mode the ids belong to: a sandbox id means
                // nothing to the live account.
                $t->string('stripe_product_id', 64)->nullable();
                $t->string('stripe_price_monthly_id', 64)->nullable();
                $t->string('stripe_price_annual_id', 64)->nullable();
                $t->boolean('stripe_livemode')->nullable();
                $t->timestamp('synced_at')->nullable();

                $t->timestamps();
            });
        }

        if (! Schema::hasTable($customers)) {
            Schema::create($customers, function (Blueprint $t) {
                $t->id();
                $t->string('billable_type', 191);
                $t->unsignedBigInteger('billable_id');
                $t->string('stripe_customer_id', 64)->unique();

                // A sandbox and the live account give one client two customer
                // ids: the reason this is a table and not a column.
                $t->boolean('livemode');
                $t->string('email', 191)->nullable();
                $t->string('name', 191)->nullable();
                $t->timestamps();

                $t->unique(['billable_type', 'billable_id', 'livemode'], 'billing_customers_billable_mode_unique');
            });
        }

        if (! Schema::hasTable($subscriptions)) {
            Schema::create($subscriptions, function (Blueprint $t) {
                $t->id();
                $t->string('billable_type', 191);
                $t->unsignedBigInteger('billable_id');
                $t->unsignedBigInteger('feature_id')->nullable()->index();

                // A mirror of Stripe's subscription, written only from a fresh
                // retrieve by the webhook handlers (SubscriptionSync).
                $t->string('stripe_subscription_id', 64)->unique();
                $t->string('stripe_customer_id', 64)->nullable()->index();
                $t->string('stripe_price_id', 64)->nullable();
                $t->string('interval', 8)->nullable();

                // Stripe's own word: incomplete, incomplete_expired, trialing,
                // active, past_due, canceled, unpaid, paused.
                $t->string('status', 32)->index();
                $t->boolean('livemode');
                $t->timestamp('current_period_end')->nullable();
                $t->timestamp('cancel_at')->nullable();
                $t->timestamp('canceled_at')->nullable();

                // The first settled payment. No entitlement before it, however
                // Stripe words the status of an unsettled direct debit.
                $t->timestamp('first_paid_at')->nullable();
                $t->string('latest_invoice_id', 64)->nullable();
                $t->timestamp('last_event_at')->nullable();
                $t->timestamps();

                $t->index(['billable_type', 'billable_id'], 'billing_subscriptions_billable_index');
            });
        }

        if (! Schema::hasTable($checkouts)) {
            Schema::create($checkouts, function (Blueprint $t) {
                $t->id();
                $t->string('billable_type', 191);
                $t->unsignedBigInteger('billable_id');
                $t->unsignedBigInteger('feature_id')->index();
                $t->string('interval', 8);
                $t->string('stripe_session_id', 255)->nullable()->unique();

                // open | complete | expired | failed
                $t->string('status', 16)->default('open')->index();
                $t->string('payment_status', 32)->nullable();
                $t->boolean('livemode');
                $t->string('stripe_subscription_id', 64)->nullable();

                // A checkout started over a dated comp: the comp it replaces,
                // and the trial end sent to Stripe so billing starts when the
                // comp would have ended (null when it was under 48 hours away).
                $t->unsignedBigInteger('comp_id')->nullable();
                $t->timestamp('trial_end')->nullable();
                $t->unsignedBigInteger('created_by')->nullable();
                $t->timestamp('fulfilled_at')->nullable();
                $t->timestamps();

                $t->index(['billable_type', 'billable_id'], 'billing_checkouts_billable_index');
            });
        }

        if (! Schema::hasTable($comps)) {
            Schema::create($comps, function (Blueprint $t) {
                $t->id();
                $t->string('billable_type', 191);
                $t->unsignedBigInteger('billable_id');
                $t->unsignedBigInteger('feature_id')->index();
                $t->string('reason', 500);
                $t->timestamp('starts_at');

                // Append-only: ending a comp sets this, nothing deletes a row.
                $t->timestamp('ends_at')->nullable();
                $t->unsignedBigInteger('granted_by')->nullable();
                $t->unsignedBigInteger('ended_by')->nullable();

                // Why it ended: staff, or first_payment when a subscription's
                // first settled payment took over from it.
                $t->string('end_reason', 64)->nullable();

                // staff | migrated
                $t->string('source', 16)->default('staff');
                $t->timestamps();

                $t->index(['billable_type', 'billable_id'], 'billing_comps_billable_index');
            });
        }

        if (! Schema::hasTable($events)) {
            Schema::create($events, function (Blueprint $t) {
                $t->id();

                // Stripe delivers at least once; this is how a retry is not a
                // second grant. No payload is ever stored.
                $t->string('stripe_event_id', 64)->unique();
                $t->string('type', 64)->index();
                $t->boolean('livemode');
                $t->timestamp('received_at');
                $t->timestamp('processed_at')->nullable();

                // applied | ignored | failed. A redelivery answers `duplicate`
                // and bumps `deliveries`; a failed one is processed again.
                $t->string('outcome', 16)->nullable()->index();
                $t->json('object_ids')->nullable();
                $t->string('error', 500)->nullable();
                $t->unsignedInteger('deliveries')->default(1);
            });
        }

        if (! Schema::hasTable($log)) {
            Schema::create($log, function (Blueprint $t) {
                $t->id();
                $t->string('billable_type', 191);
                $t->unsignedBigInteger('billable_id');
                $t->unsignedBigInteger('feature_id');

                // on | off, one row per change.
                $t->string('state', 8);
                $t->string('reason', 64);
                $t->timestamp('at');

                $t->index(['billable_type', 'billable_id', 'feature_id'], 'billing_entitlement_log_lookup_index');
            });
        }
    }

    public function down(): void
    {
        foreach ([
            ['entitlement_log', 'billing_entitlement_log'],
            ['events', 'billing_events'],
            ['comps', 'billing_comps'],
            ['checkouts', 'billing_checkouts'],
            ['subscriptions', 'billing_subscriptions'],
            ['customers', 'billing_customers'],
            ['features', 'billing_features'],
        ] as [$key, $default]) {
            Schema::dropIfExists($this->table($key, $default));
        }
    }

    private function table(string $key, string $default): string
    {
        return (string) config('visns-packages.billing.tables.' . $key, $default);
    }
};
