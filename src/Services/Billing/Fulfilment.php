<?php

namespace Visnsstudio\VisnsPackages\Services\Billing;

use Illuminate\Support\Facades\Log;
use Stripe\Checkout\Session;
use Stripe\Subscription;
use Visnsstudio\VisnsPackages\Models\BillingCheckout;

/**
 * The one idempotent fulfilment of a Checkout, called by the webhook
 * (`checkout.session.completed`, `checkout.session.async_payment_succeeded`)
 * AND by the success-page poll, so a buyer who is present sees the feature
 * at once and one who closed the tab still gets it.
 *
 * It trusts nothing it is handed: it retrieves the Session from Stripe and
 * acts only when `payment_status` is `paid`. A direct debit still clearing
 * reads `unpaid` and is left open until Stripe says it succeeded.
 */
class Fulfilment
{
    public function __construct(
        private StripeClientFactory $stripe,
        private SubscriptionSync $sync,
    ) {
    }

    /** @return BillingCheckout|null our row, when the session is one we started */
    public function fromCheckout(string $sessionId): ?BillingCheckout
    {
        $session = $this->stripe->make()->checkout->sessions->retrieve($sessionId, ['expand' => ['subscription']]);
        $checkout = $this->locate($session);

        if ($checkout && $checkout->status === BillingCheckout::STATUS_COMPLETE && $checkout->fulfilled_at !== null) {
            return $checkout;
        }

        $paymentStatus = (string) ($session->payment_status ?? '');
        $sessionStatus = (string) ($session->status ?? '');

        if ($checkout) {
            $checkout->forceFill(['payment_status' => $paymentStatus ?: null]);

            if ($sessionStatus === 'expired') {
                $checkout->status = BillingCheckout::STATUS_EXPIRED;
            }
        }

        if ($paymentStatus !== 'paid' || ($session->mode ?? null) !== 'subscription') {
            $checkout?->save();

            return $checkout;
        }

        $subscription = $session->subscription;

        if (is_string($subscription) && $subscription !== '') {
            $subscription = $this->stripe->make()->subscriptions->retrieve($subscription);
        }

        $row = $subscription instanceof Subscription ? $this->sync->apply($subscription, true, $checkout) : null;

        if ($checkout) {
            $checkout->forceFill([
                'status' => BillingCheckout::STATUS_COMPLETE,
                'stripe_subscription_id' => $row?->stripe_subscription_id ?? ($subscription instanceof Subscription ? $subscription->id : null),
                'fulfilled_at' => $checkout->fulfilled_at ?? now(),
            ])->save();

            Log::info('billing.checkout_fulfilled', ['checkout_id' => $checkout->id, 'subscription_id' => $row?->id]);
        }

        return $checkout;
    }

    /** Our checkout row for a session: client_reference_id first, then the session id. */
    public function locate(Session $session): ?BillingCheckout
    {
        $reference = (string) ($session->client_reference_id ?? '');

        if (ctype_digit($reference)) {
            $checkout = BillingCheckout::query()->find((int) $reference);

            if ($checkout && $checkout->stripe_session_id === $session->id) {
                return $checkout;
            }

            // Stripe made the session but our save of its id did not land.
            if ($checkout && $checkout->stripe_session_id === null) {
                $checkout->forceFill(['stripe_session_id' => $session->id])->save();

                return $checkout;
            }
        }

        return BillingCheckout::query()->where('stripe_session_id', $session->id)->first();
    }
}
