<?php

namespace Visnsstudio\VisnsPackages\Services\Billing;

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Log;
use Stripe\Event;
use Stripe\Exception\InvalidRequestException;
use Stripe\Subscription;
use Visnsstudio\VisnsPackages\Events\BillingAttention;
use Visnsstudio\VisnsPackages\Models\BillingCheckout;
use Visnsstudio\VisnsPackages\Models\BillingEvent;
use Visnsstudio\VisnsPackages\Models\BillingSubscription;

/**
 * Applies one verified Stripe event: ledger first, then the handler.
 *
 * Idempotent: the ledger's unique event id means a redelivery answers 200
 * `duplicate` and changes nothing. A handler that throws marks the row
 * `failed` and answers 500, so Stripe retries, and the retry runs it again.
 * Every subscription-affecting handler re-reads Stripe (SubscriptionSync), so
 * the order events arrive in does not matter.
 *
 * Nothing from the payload is stored or logged except ids.
 */
class WebhookProcessor
{
    /** A row still unfinished after this long was a crash, not a race. */
    private const STALE_SECONDS = 120;

    public function __construct(
        private Fulfilment $fulfilment,
        private SubscriptionSync $sync,
        private StripeObjectGraph $graph,
        private BillingCustomers $customers,
        private StripeClientFactory $stripe,
    ) {
    }

    /** @return array{status: int, outcome: string} */
    public function process(Event $event): array
    {
        $eventId = (string) $event->id;
        $type = (string) $event->type;

        $row = BillingEvent::query()->where('stripe_event_id', $eventId)->first();

        if ($row && ! $this->shouldRetry($row)) {
            $row->forceFill(['deliveries' => $row->deliveries + 1])->save();

            return ['status' => 200, 'outcome' => BillingEvent::OUTCOME_DUPLICATE];
        }

        if ($row) {
            $row->forceFill(['deliveries' => $row->deliveries + 1, 'outcome' => null, 'error' => null])->save();
        } else {
            $row = new BillingEvent();
            $row->forceFill([
                'stripe_event_id' => mb_substr($eventId, 0, 64),
                'type' => mb_substr($type, 0, 64),
                'livemode' => (bool) $event->livemode,
                'received_at' => now(),
                'deliveries' => 1,
            ]);

            try {
                $row->save();
            } catch (QueryException $e) {
                // A concurrent delivery of the same event got there first.
                return ['status' => 200, 'outcome' => BillingEvent::OUTCOME_DUPLICATE];
            }
        }

        try {
            [$outcome, $ids] = $this->dispatch($event);
        } catch (\Throwable $e) {
            $row->forceFill([
                'outcome' => BillingEvent::OUTCOME_FAILED,
                'processed_at' => now(),
                'error' => mb_substr(class_basename($e) . ': ' . self::redact($e->getMessage()), 0, 500),
            ])->save();

            Log::warning('billing.webhook_failed', ['event_id' => $eventId, 'type' => $type, 'exception' => class_basename($e)]);

            $this->failedAttention($event);

            return ['status' => 500, 'outcome' => BillingEvent::OUTCOME_FAILED];
        }

        $row->forceFill([
            'outcome' => $outcome,
            'object_ids' => array_filter($ids, fn ($v) => $v !== null && $v !== ''),
            'processed_at' => now(),
        ])->save();

        Log::info('billing.webhook', ['event_id' => $eventId, 'type' => $type, 'outcome' => $outcome]);

        return ['status' => 200, 'outcome' => $outcome];
    }

    /** Ids and the event type only. A listener that throws must not change the 500. */
    private function failedAttention(Event $event): void
    {
        try {
            BillingAttention::dispatch('handler_failed', (string) $event->id, $event->data->object->id ?? null, null, null, null, null, (string) $event->type);
        } catch (\Throwable $e) {
            Log::warning('billing.attention_listener_failed', ['event_id' => $event->id, 'exception' => class_basename($e)]);
        }
    }

    /** Strip anything shaped like a Stripe key or secret from an error. */
    public static function redact(string $text): string
    {
        return (string) preg_replace('/\b(sk|rk|pk)_(live|test)_[A-Za-z0-9*]+|\bwhsec_[A-Za-z0-9]+/', '[redacted]', $text);
    }

    private function shouldRetry(BillingEvent $row): bool
    {
        if ($row->outcome === BillingEvent::OUTCOME_FAILED) {
            return true;
        }

        return $row->outcome === null
            && $row->received_at !== null
            && $row->received_at->lt(now()->subSeconds(self::STALE_SECONDS));
    }

    /** @return array{0: string, 1: array<string, mixed>} outcome and the ids it resolved */
    private function dispatch(Event $event): array
    {
        $object = $event->data->object->toArray();
        $objectId = $object['id'] ?? null;
        $ids = ['object' => $objectId];

        switch ($event->type) {
            case 'checkout.session.completed':
            case 'checkout.session.async_payment_succeeded':
                $checkout = $this->fulfilment->fromCheckout((string) $objectId);

                return [BillingEvent::OUTCOME_APPLIED, $ids + $this->checkoutIds($checkout)];

            case 'checkout.session.async_payment_failed':
            case 'checkout.session.expired':
                return $this->closeCheckout($event->type, (string) $objectId, $ids);

            case 'customer.subscription.created':
            case 'customer.subscription.updated':
            case 'customer.subscription.deleted':
                return $this->subscription((string) $objectId, $object, $ids);

            case 'invoice.paid':
            case 'invoice.payment_failed':
                $subscriptionId = $this->graph->subscriptionForInvoice($object);

                if ($subscriptionId === null) {
                    return [BillingEvent::OUTCOME_IGNORED, $ids];
                }

                $settled = $event->type === 'invoice.paid'
                    && ($object['status'] ?? null) === 'paid'
                    && (int) ($object['amount_paid'] ?? 0) > 0;

                $row = $this->sync->byId($subscriptionId, $settled);

                return [$row ? BillingEvent::OUTCOME_APPLIED : BillingEvent::OUTCOME_IGNORED, $ids + ['subscription' => $subscriptionId] + $this->subscriptionIds($row)];

            case 'charge.refunded':
                return $this->attention('refunded', $event, $object, $ids);

            case 'charge.dispute.created':
                return $this->attention('disputed', $event, $object, $ids);

            case 'radar.early_fraud_warning.created':
                return $this->attention('fraud_warning', $event, $object, $ids);

            case 'customer.updated':
                return $this->customerUpdated($object, $ids);
        }

        return [BillingEvent::OUTCOME_IGNORED, $ids];
    }

    private function subscription(string $subscriptionId, array $object, array $ids): array
    {
        try {
            $row = $this->sync->byId($subscriptionId);
        } catch (InvalidRequestException $e) {
            if ($e->getHttpStatus() !== 404) {
                throw $e;
            }

            // Gone at Stripe: the event's own copy is the last word there is.
            $row = $this->sync->apply(Subscription::constructFrom($object));
        }

        return [$row ? BillingEvent::OUTCOME_APPLIED : BillingEvent::OUTCOME_IGNORED, $ids + ['subscription' => $subscriptionId] + $this->subscriptionIds($row)];
    }

    private function closeCheckout(string $type, string $sessionId, array $ids): array
    {
        $checkout = BillingCheckout::query()->where('stripe_session_id', $sessionId)->first();

        if (! $checkout) {
            return [BillingEvent::OUTCOME_IGNORED, $ids];
        }

        if ($checkout->status !== BillingCheckout::STATUS_COMPLETE || $checkout->fulfilled_at === null) {
            if ($type === 'checkout.session.async_payment_failed') {
                $session = $this->stripe->make()->checkout->sessions->retrieve($sessionId);
                $checkout->forceFill([
                    'status' => BillingCheckout::STATUS_FAILED,
                    'payment_status' => (string) ($session->payment_status ?? 'unpaid') ?: null,
                ])->save();

                Log::info('billing.checkout_payment_failed', ['checkout_id' => $checkout->id]);
            } elseif ($checkout->status === BillingCheckout::STATUS_OPEN) {
                $checkout->forceFill(['status' => BillingCheckout::STATUS_EXPIRED])->save();
            }
        }

        return [BillingEvent::OUTCOME_APPLIED, $ids + $this->checkoutIds($checkout)];
    }

    private function attention(string $kind, Event $event, array $object, array $ids): array
    {
        $subscriptionId = $this->graph->subscriptionForPaymentObject($object);
        $row = $subscriptionId ? BillingSubscription::query()->where('stripe_subscription_id', $subscriptionId)->first() : null;

        BillingAttention::dispatch(
            $kind,
            (string) $event->id,
            $ids['object'] ?? null,
            $subscriptionId,
            $row?->id,
            $row?->billable_type,
            $row ? (int) $row->billable_id : null,
            (string) $event->type,
        );

        Log::warning('billing.attention', ['kind' => $kind, 'event_id' => $event->id, 'subscription_id' => $row?->id]);

        return [BillingEvent::OUTCOME_APPLIED, $ids + ['subscription' => $subscriptionId] + $this->subscriptionIds($row)];
    }

    /** Only the two fields the mapping mirrors. */
    private function customerUpdated(array $object, array $ids): array
    {
        $mapping = $this->customers->byStripeId($object['id'] ?? null);

        if (! $mapping) {
            return [BillingEvent::OUTCOME_IGNORED, $ids];
        }

        $email = trim((string) ($object['email'] ?? '')) ?: null;
        $name = trim((string) ($object['name'] ?? '')) ?: null;

        $mapping->forceFill([
            'email' => $email ? mb_substr($email, 0, 191) : null,
            'name' => $name ? mb_substr($name, 0, 191) : null,
        ]);

        if ($mapping->isDirty()) {
            $mapping->save();
        }

        return [BillingEvent::OUTCOME_APPLIED, $ids + ['billable_id' => $mapping->billable_id]];
    }

    private function checkoutIds(?BillingCheckout $checkout): array
    {
        return $checkout ? [
            'checkout_id' => $checkout->id,
            'subscription' => $checkout->stripe_subscription_id,
            'billable_id' => $checkout->billable_id,
            'feature_id' => $checkout->feature_id,
        ] : [];
    }

    private function subscriptionIds(?BillingSubscription $row): array
    {
        return $row ? [
            'subscription_id' => $row->id,
            'billable_id' => $row->billable_id,
            'feature_id' => $row->feature_id,
            'customer' => $row->stripe_customer_id,
        ] : [];
    }
}
