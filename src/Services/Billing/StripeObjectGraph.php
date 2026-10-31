<?php

namespace Visnsstudio\VisnsPackages\Services\Billing;

/**
 * From any object a billing event carries to the Subscription it is about,
 * through Stripe's own links rather than our metadata.
 *
 *  - an invoice → `parent.subscription_details.subscription`;
 *  - a charge, a dispute or an early fraud warning → its PaymentIntent →
 *    the paid InvoicePayment for it → that invoice → as above (the procedure
 *    Stripe documents for basil and later, where a charge no longer names
 *    its invoice).
 */
class StripeObjectGraph
{
    public function __construct(private StripeClientFactory $stripe)
    {
    }

    public function subscriptionForInvoice(array $invoice): ?string
    {
        $id = data_get($invoice, 'parent.subscription_details.subscription')
            ?? data_get($invoice, 'subscription');

        if (is_array($id)) {
            $id = $id['id'] ?? null;
        }

        return is_string($id) && $id !== '' ? $id : null;
    }

    /** A charge, dispute or early fraud warning, as an array. */
    public function subscriptionForPaymentObject(array $object): ?string
    {
        $paymentIntent = $this->id($object['payment_intent'] ?? null);

        if ($paymentIntent === null && ($charge = $this->id($object['charge'] ?? null))) {
            $paymentIntent = $this->id($this->stripe->make()->charges->retrieve($charge)->payment_intent ?? null);
        }

        return $paymentIntent ? $this->subscriptionForPaymentIntent($paymentIntent) : null;
    }

    public function subscriptionForPaymentIntent(string $paymentIntent): ?string
    {
        $client = $this->stripe->make();

        $payments = $client->invoicePayments->all([
            'payment' => ['type' => 'payment_intent', 'payment_intent' => $paymentIntent],
            'status' => 'paid',
            'limit' => 1,
        ]);

        $invoiceId = $this->id($payments->data[0]->invoice ?? null);

        if ($invoiceId === null) {
            return null;
        }

        return $this->subscriptionForInvoice($client->invoices->retrieve($invoiceId)->toArray());
    }

    private function id($value): ?string
    {
        if (is_object($value) && isset($value->id)) {
            $value = $value->id;
        }

        if (is_array($value)) {
            $value = $value['id'] ?? null;
        }

        return is_string($value) && $value !== '' ? $value : null;
    }
}
