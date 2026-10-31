<?php

namespace Visnsstudio\VisnsPackages\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;
use Visnsstudio\VisnsPackages\Exceptions\Billing\BillingRefusal;
use Visnsstudio\VisnsPackages\Models\BillingCheckout;
use Visnsstudio\VisnsPackages\Models\BillingComp;
use Visnsstudio\VisnsPackages\Models\BillingCustomer;
use Visnsstudio\VisnsPackages\Models\BillingEvent;
use Visnsstudio\VisnsPackages\Models\BillingFeature;
use Visnsstudio\VisnsPackages\Models\BillingSubscription;
use Visnsstudio\VisnsPackages\Services\Billing\Billables;
use Visnsstudio\VisnsPackages\Services\Billing\BillingCatalogue;
use Visnsstudio\VisnsPackages\Services\Billing\Comps;
use Visnsstudio\VisnsPackages\Services\Billing\Entitlements;
use Visnsstudio\VisnsPackages\Services\Billing\Probes;
use Visnsstudio\VisnsPackages\Services\Billing\StripeClientFactory;
use Visnsstudio\VisnsPackages\Services\IntegrationRegistry;
use Visnsstudio\VisnsPackages\Support\BillingResponses;
use Visnsstudio\VisnsPackages\Support\ModuleConfig;
use Visnsstudio\VisnsPackages\Support\PageSize;

/**
 * Settings → Billing: the staff side of the billing module, behind
 * `billing.permissions.manage`.
 *
 * The catalogue (staff set prices; `sync` pushes them to Stripe), the probe,
 * one billable party's subscriptions, comps and checkouts, and the webhook
 * ledger. No action here cancels or refunds: that stays in the Dashboard.
 */
class BillingController extends \App\Http\Controllers\Controller
{
    use BillingResponses;

    public function __construct(
        private StripeClientFactory $stripe,
        private Billables $billables,
        private Entitlements $entitlements,
    ) {
    }

    public function status(): JsonResponse
    {
        $last = BillingEvent::query()->orderByDesc('id')->first();

        return response()->json([
            'data' => [
                'configured' => $this->stripe->configured(),
                'livemode' => $this->stripe->configured() ? $this->stripe->livemode() : null,
                'webhook_secret_set' => $this->stripe->webhookSecret() !== null,
                'tax_rate_id' => $this->stripe->taxRateId(),
                'has_resolver' => $this->billables->hasResolver(),
                'api_version' => StripeClientFactory::API_VERSION,
                // From config('app.url'), never the request's host: it is pasted into Workbench.
                'webhook_url' => rtrim((string) config('app.url'), '/') . '/' . trim((string) ModuleConfig::get('billing.uris.webhook', 'api/billing/stripe/webhook'), '/'),
                'last_event_at' => $last?->received_at?->toIso8601String(),
            ],
        ]);
    }

    public function probe(IntegrationRegistry $registry): JsonResponse
    {
        $result = Probes::stripe($registry->credentials($this->stripe->provider()));

        return response()->json(['ok' => $result['success'], 'message' => $result['message']]);
    }

    public function features(): JsonResponse
    {
        return response()->json([
            'data' => BillingFeature::query()->ordered()->get()->map(fn (BillingFeature $f) => $this->featurePayload($f))->all(),
        ]);
    }

    public function storeFeature(Request $request): JsonResponse
    {
        $validated = $request->validate($this->featureRules(true));

        $feature = new BillingFeature();
        $feature->forceFill([
            'key' => $validated['key'],
            'currency' => strtolower((string) ModuleConfig::get('billing.currency', 'aud')),
        ]);
        $feature->fill($validated);
        $feature->save();

        return response()->json(['data' => $this->featurePayload($feature)], 201);
    }

    public function updateFeature(Request $request, $id): JsonResponse
    {
        $feature = BillingFeature::query()->findOrFail($id);

        $feature->fill($request->validate($this->featureRules(false)));
        $feature->save();

        return response()->json(['data' => $this->featurePayload($feature)]);
    }

    public function syncFeature(BillingCatalogue $catalogue, $id): JsonResponse
    {
        $feature = BillingFeature::query()->findOrFail($id);

        return $this->answer(function () use ($catalogue, $feature) {
            $done = $catalogue->sync($feature);

            return response()->json(['ok' => true, 'done' => $done, 'data' => $this->featurePayload($feature->refresh())]);
        });
    }

    /** One billable party: what it has, why, and the rows behind it. */
    public function billable(string $type, $id): JsonResponse
    {
        return $this->answer(function () use ($type, $id) {
            $billable = $this->billables->find($type, $id) ?? throw BillingRefusal::notFound();
            $scope = fn ($query) => $this->billables->scope($query, $billable);

            return response()->json([
                'data' => [
                    'billable' => ['type' => $type, 'id' => (int) $id],
                    'entitlements' => $this->entitlements->forBillable($billable),
                    'customers' => $scope(BillingCustomer::query())->get(['stripe_customer_id', 'livemode', 'email', 'name', 'created_at']),
                    'subscriptions' => $scope(BillingSubscription::query())->with('feature:id,key,name')->orderByDesc('id')->get(),
                    'comps' => $scope(BillingComp::query())->with('feature:id,key,name')->orderByDesc('id')->get(),
                    'checkouts' => $scope(BillingCheckout::query())->with('feature:id,key,name')->orderByDesc('id')->limit(20)->get(),
                ],
            ]);
        });
    }

    public function grantComp(Request $request, Comps $comps, string $type, $id): JsonResponse
    {
        $validated = $request->validate([
            'feature' => 'required|string|max:64',
            'reason' => 'required|string|max:500',
            'ends_at' => 'nullable|date',
        ]);

        return $this->answer(function () use ($request, $comps, $type, $id, $validated) {
            $billable = $this->billables->find($type, $id) ?? throw BillingRefusal::notFound();
            $feature = BillingFeature::query()->where('key', $validated['feature'])->first() ?? throw BillingRefusal::notFound();

            $comp = $comps->grant(
                $billable,
                $feature,
                $validated['reason'],
                ! empty($validated['ends_at']) ? Carbon::parse($validated['ends_at'], config('app.timezone')) : null,
                $request->user()?->getKey(),
            );

            return response()->json(['ok' => true, 'data' => $comp], 201);
        });
    }

    public function endComp(Request $request, Comps $comps, $id): JsonResponse
    {
        $comp = BillingComp::query()->findOrFail($id);

        return $this->answer(fn () => response()->json(['ok' => true, 'data' => $comps->end($comp, $request->user()?->getKey())]));
    }

    /** The webhook ledger, newest first. */
    public function events(Request $request): JsonResponse
    {
        $query = BillingEvent::query()->orderByDesc('id');

        if ($request->filled('outcome')) {
            $query->where('outcome', (string) $request->input('outcome'));
        }

        if ($request->filled('type')) {
            $query->where('type', (string) $request->input('type'));
        }

        return response()->json($query->paginate(PageSize::from($request, 50)));
    }

    private function featureRules(bool $creating): array
    {
        $table = (new BillingFeature())->getTable();
        $sometimes = $creating ? 'required' : 'sometimes';

        return array_filter([
            // Fixed at create: the host's feature checks name it.
            'key' => $creating ? ['required', 'string', 'regex:/^[a-z][a-z0-9_]{1,63}$/', Rule::unique($table, 'key')] : null,
            'name' => [$sometimes, 'string', 'max:191'],
            'blurb' => ['nullable', 'string', 'max:1000'],
            // Ex GST, in cents. Stripe's minimum charge in AUD is 50 cents.
            'monthly_amount_cents' => [$sometimes, 'integer', 'min:50', 'max:99999999'],
            'annual_amount_cents' => ['nullable', 'integer', 'min:50', 'max:99999999'],
            'available' => ['sometimes', 'boolean'],
            'sort_order' => ['sometimes', 'integer'],
        ]);
    }

    private function featurePayload(BillingFeature $feature): array
    {
        return [
            'id' => $feature->id,
            'key' => $feature->key,
            'name' => $feature->name,
            'blurb' => $feature->blurb,
            'currency' => $feature->currency,
            'monthly_amount_cents' => $feature->monthly_amount_cents,
            'annual_amount_cents' => $feature->annual_amount_cents,
            'available' => (bool) $feature->available,
            'sort_order' => $feature->sort_order,
            'stripe_product_id' => $feature->stripe_product_id,
            'stripe_price_monthly_id' => $feature->stripe_price_monthly_id,
            'stripe_price_annual_id' => $feature->stripe_price_annual_id,
            'stripe_livemode' => $feature->stripe_livemode,
            'synced_at' => $feature->synced_at?->toIso8601String(),
            // Edited since the last push, or never pushed. The sync's own save
            // stamps updated_at a moment after synced_at, hence the second.
            'needs_sync' => $feature->synced_at === null || (bool) $feature->updated_at?->gt($feature->synced_at->copy()->addSecond()),
        ];
    }
}
