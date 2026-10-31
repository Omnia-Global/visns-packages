<?php

namespace Visnsstudio\VisnsPackages\Tests;

use Stripe\ApiRequestor;

/**
 * Stripe, faked under the SDK, for any test that touches billing.
 *
 *     use FakesStripe;
 *
 *     $stripe = $this->fakeStripe();
 *     $stripe->on('POST', '/v1/customers', FakeStripe::customer('cus_1'));
 *     $stripe->on('GET', '/v1/subscriptions/*', fn ($params, $request) => FakeStripe::subscription(...));
 *
 *     $this->postStripeEvent($this->stripeEvent('invoice.paid', FakeStripe::invoice(...)), 'whsec_test');
 *     $stripe->assertSent('POST', '/v1/checkout/sessions', fn ($p) => ! isset($p['payment_method_types']));
 *
 * Installed for the one test and removed at teardown, which also fails the
 * test if any request was unscripted or broke one of Stripe's rules (see
 * FakeStripe), even when the code under test caught the error.
 */
trait FakesStripe
{
    protected ?FakeStripe $stripeFake = null;

    protected function fakeStripe(): FakeStripe
    {
        $fake = new FakeStripe();

        ApiRequestor::setHttpClient($fake);
        $this->stripeFake = $fake;

        $this->beforeApplicationDestroyed(function () use ($fake) {
            ApiRequestor::setHttpClient(null);
            $fake->assertNoViolations();
        });

        return $fake;
    }

    /** A `Stripe-Signature` header, signed the way Stripe signs. */
    protected function stripeSignature(string $body, string $secret, ?int $timestamp = null): string
    {
        $timestamp ??= time();

        return 't=' . $timestamp . ',v1=' . hash_hmac('sha256', $timestamp . '.' . $body, $secret);
    }

    /** An event envelope around an object, as Stripe delivers it. */
    protected function stripeEvent(string $type, array $object, array $overrides = []): array
    {
        return array_replace_recursive([
            'id' => 'evt_' . bin2hex(random_bytes(8)),
            'object' => 'event',
            'api_version' => \Visnsstudio\VisnsPackages\Services\Billing\StripeClientFactory::API_VERSION,
            'created' => time(),
            'livemode' => (bool) ($object['livemode'] ?? false),
            'type' => $type,
            'data' => ['object' => $object],
        ], $overrides);
    }

    /** POST a signed event to the webhook, raw body and all. */
    protected function postStripeEvent(array $event, string $secret, ?int $timestamp = null, ?string $uri = null, ?string $signature = null)
    {
        $body = json_encode($event);
        $uri ??= '/' . trim((string) config('visns-packages.billing.uris.webhook', 'api/billing/stripe/webhook'), '/');

        return $this->call('POST', $uri, [], [], [], [
            'HTTP_STRIPE_SIGNATURE' => $signature ?? $this->stripeSignature($body, $secret, $timestamp),
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json',
        ], $body);
    }
}
