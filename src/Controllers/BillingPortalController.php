<?php

namespace Visnsstudio\VisnsPackages\Controllers;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Visnsstudio\VisnsPackages\Exceptions\Billing\BillingRefusal;
use Visnsstudio\VisnsPackages\Exceptions\Billing\BillingUnconfigured;
use Visnsstudio\VisnsPackages\Models\BillingFeature;
use Visnsstudio\VisnsPackages\Services\Billing\Billables;
use Visnsstudio\VisnsPackages\Services\Billing\BillingCustomers;
use Visnsstudio\VisnsPackages\Services\Billing\Checkouts;
use Visnsstudio\VisnsPackages\Services\Billing\CustomerPortal;
use Visnsstudio\VisnsPackages\Services\Billing\Entitlements;
use Visnsstudio\VisnsPackages\Services\Billing\StripeClientFactory;
use Visnsstudio\VisnsPackages\Support\BillingResponses;
use Visnsstudio\VisnsPackages\Support\ModuleConfig;

/**
 * The buyer's side of billing: the feature list, Subscribe, the success-page
 * poll and Manage billing.
 *
 * The package registers NO route for this. A host application extends it,
 * points the hooks at its own portal session, and mounts the four actions
 * behind its own client guard, the way the messaging module's campaign screen
 * is mounted for a client:
 *
 *     Route::middleware(['web', 'auth:portal'])->prefix('portal/ajax/billing')->group(function () {
 *         Route::get('/', [PortalBillingController::class, 'features']);
 *         Route::post('/checkout', [PortalBillingController::class, 'checkout']);
 *         Route::get('/checkout/{session}', [PortalBillingController::class, 'checkoutStatus']);
 *         Route::post('/manage', [PortalBillingController::class, 'manage']);
 *     });
 *
 * Every read narrows by the party billable() answers first; another party's
 * checkout is the same 404 as one that never existed.
 */
class BillingPortalController extends \App\Http\Controllers\Controller
{
    use BillingResponses;

    public function __construct(
        protected Billables $billables,
        protected Entitlements $entitlements,
        protected StripeClientFactory $stripe,
    ) {
    }

    /* ---------------------------------------------------------------------
     | Hooks - override in the host's subclass
     | ------------------------------------------------------------------- */

    /** The party this request acts for. Default: the configured resolver. */
    protected function billable(Request $request): ?Model
    {
        return $this->billables->resolver()->resolve($request);
    }

    /** May this request commit the party to a charge? Default: the resolver. */
    protected function mayManage(Request $request, Model $billable): bool
    {
        return $this->billables->resolver()->canManage($request, $billable);
    }

    /** Where Stripe returns a buyer who paid. Must keep {CHECKOUT_SESSION_ID}. */
    protected function successUrl(Request $request, BillingFeature $feature): string
    {
        return $this->appUrl((string) ModuleConfig::get('billing.success_path', '/?checkout={CHECKOUT_SESSION_ID}'), $feature);
    }

    /** Where Stripe returns a buyer who backed out. */
    protected function cancelUrl(Request $request, BillingFeature $feature): string
    {
        return $this->appUrl((string) ModuleConfig::get('billing.cancel_path', '/'), $feature);
    }

    /** Where the Customer Portal's back link goes. */
    protected function returnUrl(Request $request): string
    {
        return $this->appUrl((string) ModuleConfig::get('billing.return_path', '/'));
    }

    /** The host user id stamped on a checkout. A portal person is not one: null. */
    protected function actorId(Request $request): ?int
    {
        return null;
    }

    protected function refusedManage(): string
    {
        return 'Only a billing administrator for your organisation can change its subscriptions. Ask your account administrator.';
    }

    /* ---------------------------------------------------------------------
     | Actions
     | ------------------------------------------------------------------- */

    /** Every catalogue feature with its price and this party's state. */
    public function features(Request $request, BillingCustomers $customers, Checkouts $checkouts): JsonResponse
    {
        return $this->answer(function () use ($request, $customers, $checkouts) {
            $billable = $this->party($request);
            $livemode = $this->stripe->livemode();
            $configured = $this->stripe->configured();

            $features = BillingFeature::query()->ordered()->get()->map(fn (BillingFeature $f) => [
                ...$this->subscribeTerms($checkouts->eligibility($billable, $f)),
                'key' => $f->key,
                'name' => $f->name,
                'blurb' => $f->blurb,
                'currency' => $f->currency,
                // Ex GST; the screen says "+ GST".
                'monthly_amount_cents' => $f->monthly_amount_cents,
                'annual_amount_cents' => $f->annual_amount_cents,
                'intervals' => $f->intervals(),
                'available' => (bool) $f->available,
                'purchasable' => $configured && $f->available && $f->stripe_livemode === $livemode && $f->stripe_price_monthly_id !== null,
                'state' => $this->entitlements->whyFeature($billable, $f),
            ])->all();

            return response()->json([
                'ok' => true,
                'configured' => $configured,
                'can_manage' => $this->mayManage($request, $billable),
                'has_billing_account' => $configured && $customers->find($billable) !== null,
                'features' => $features,
            ]);
        });
    }

    /** Start a hosted Checkout; the page assigns `url` to window.location. */
    public function checkout(Request $request, Checkouts $checkouts): JsonResponse
    {
        $validated = $request->validate([
            'feature' => 'required|string|max:64',
            'interval' => 'required|string|in:month,year',
        ]);

        return $this->answer(function () use ($request, $checkouts, $validated) {
            $this->assertConfigured();
            $billable = $this->party($request);

            if (! $this->mayManage($request, $billable)) {
                throw new BillingRefusal($this->refusedManage(), 403);
            }

            $feature = BillingFeature::query()->where('key', $validated['feature'])->first() ?? throw BillingRefusal::notFound();

            $started = $checkouts->start(
                $billable,
                $feature,
                $validated['interval'],
                $this->successUrl($request, $feature),
                $this->cancelUrl($request, $feature),
                $this->actorId($request),
            );

            return response()->json(['ok' => true, 'url' => $started['url']]);
        });
    }

    /** The success page's poll. Fulfils on the spot when Stripe says paid. */
    public function checkoutStatus(Request $request, Checkouts $checkouts, string $session): JsonResponse
    {
        return $this->answer(function () use ($request, $checkouts, $session) {
            $this->assertConfigured();
            $billable = $this->party($request);
            $checkout = $checkouts->status($billable, $session);
            $feature = $checkout->feature;

            return response()->json([
                'ok' => true,
                'status' => $checkout->status,
                'payment_status' => $checkout->payment_status,
                'feature' => $feature?->key,
                'entitled' => $feature ? $this->entitlements->whyFeature($billable, $feature)['entitled'] : false,
            ]);
        });
    }

    /** A Customer Portal link. */
    public function manage(Request $request, CustomerPortal $portal): JsonResponse
    {
        return $this->answer(function () use ($request, $portal) {
            $this->assertConfigured();
            $billable = $this->party($request);

            if (! $this->mayManage($request, $billable)) {
                throw new BillingRefusal($this->refusedManage(), 403);
            }

            return response()->json(['ok' => true, 'url' => $portal->url($billable, $this->returnUrl($request))]);
        });
    }

    /* ------------------------------------------------------------------- */

    /**
     * What Subscribe would do, from Checkouts::eligibility() (the same reading
     * start() refuses by). `can_subscribe` is about the party, not the person:
     * `can_manage` says whether this person may press it. On a dated comp,
     * `included_until` is its end and `billing_starts_at` is when Stripe would
     * first charge (null: at checkout, the comp being under 48 hours from its end).
     */
    private function subscribeTerms(array $eligibility): array
    {
        return [
            'can_subscribe' => $eligibility['can_subscribe'],
            'included_until' => $eligibility['included_until']?->toIso8601String(),
            'billing_starts_at' => $eligibility['trial_end']?->toIso8601String(),
        ];
    }

    private function party(Request $request): Model
    {
        if (! ModuleConfig::get('billing.enabled', false)) {
            throw BillingRefusal::notFound();
        }

        return $this->billable($request) ?? throw BillingRefusal::notFound();
    }

    private function assertConfigured(): void
    {
        if (! ModuleConfig::get('billing.enabled', false)) {
            throw BillingRefusal::notFound();
        }

        if (! $this->stripe->configured()) {
            throw new BillingUnconfigured('Stripe is not configured.');
        }
    }

    /** From config('app.url'), never the request's host. */
    private function appUrl(string $path, ?BillingFeature $feature = null): string
    {
        if ($feature) {
            $path = str_replace('{feature}', rawurlencode((string) $feature->key), $path);
        }

        return rtrim((string) config('app.url'), '/') . '/' . ltrim($path, '/');
    }
}
