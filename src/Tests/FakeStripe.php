<?php

namespace Visnsstudio\VisnsPackages\Tests;

use PHPUnit\Framework\Assert;
use Stripe\HttpClient\ClientInterface;
use Visnsstudio\VisnsPackages\Services\Billing\StripeClientFactory;

/**
 * A scripted Stripe API, installed under the SDK through
 * `ApiRequestor::setHttpClient()`. See FakesStripe.
 *
 * stripe-php has its own curl client, so Laravel's `Http::fake()` and
 * `preventStrayRequests()` never see it. This is the same guarantee for
 * Stripe: a request nobody scripted THROWS, so nothing reaches the network.
 *
 * STRIPE'S RULES ARE ENFORCED, NOT WAVED THROUGH. A request Stripe would
 * refuse is answered with Stripe's 400 AND recorded as a violation that fails
 * the test at teardown, even if the code under test swallowed the error:
 *
 *   - `payment_method_types` anywhere in a request. Payment methods are set in
 *     the Dashboard; naming them in code switches BECS (or the next method)
 *     off without anyone noticing.
 *   - a secret key (sk_) on the wire: billing runs on restricted keys only.
 *   - any API version but StripeClientFactory::API_VERSION.
 *   - a Checkout Session given both `customer` and `customer_email`, or
 *     `tax_id_collection` for an existing customer without
 *     `customer_update[name]=auto`, or `mode: subscription` without a Price.
 *   - a Price update that tries to change an amount, currency, interval or
 *     product: a Price's amount can never be edited.
 *   - a Checkout `subscription_data.trial_end` under 48 hours away.
 */
class FakeStripe implements ClientInterface
{
    /** @var array<int, array{method: string, pattern: string, response: mixed, times: ?int}> */
    private array $routes = [];

    /** @var array<int, array{method: string, path: string, params: array, headers: array<string, string>}> */
    public array $requests = [];

    /** @var string[] */
    public array $violations = [];

    /**
     * Script an answer.
     *
     * `$path` is the path after the host, `*` matching one segment:
     * `/v1/subscriptions/*`. `$response` is a body array (answered 200), a
     * `[status, body]` pair, or a callable receiving (params, request) and
     * returning either. `$times` limits how often it answers; later scripts
     * for the same path answer after it is used up.
     */
    public function on(string $method, string $path, $response, ?int $times = null): static
    {
        $pattern = '#^' . str_replace('\*', '[^/]+', preg_quote($path, '#')) . '$#';

        $this->routes[] = ['method' => strtolower($method), 'pattern' => $pattern, 'response' => $response, 'times' => $times];

        return $this;
    }

    /** Stripe's error shape, as a [status, body] pair for on(). */
    public static function error(int $status, string $message, string $type = 'invalid_request_error', ?string $code = null): array
    {
        return [$status, ['error' => array_filter(['type' => $type, 'message' => $message, 'code' => $code])]];
    }

    public function request($method, $absUrl, $headers, $params, $hasFile, $apiMode = 'v1', $maxNetworkRetries = null)
    {
        $method = strtolower((string) $method);
        $path = (string) parse_url((string) $absUrl, PHP_URL_PATH);
        $query = (string) parse_url((string) $absUrl, PHP_URL_QUERY);
        $params = is_array($params) ? $params : [];

        if ($query !== '') {
            parse_str($query, $fromUrl);
            $params += $fromUrl;
        }

        $headerMap = [];

        foreach ((array) $headers as $line) {
            [$name, $value] = array_pad(explode(':', (string) $line, 2), 2, '');
            $headerMap[strtolower(trim($name))] = trim($value);
        }

        $request = ['method' => $method, 'path' => $path, 'params' => $params, 'headers' => $headerMap];
        $this->requests[] = $request;

        if ($refusal = $this->breach($request)) {
            $this->violations[] = strtoupper($method) . ' ' . $path . ': ' . $refusal;

            return $this->encode(self::error(400, $refusal));
        }

        foreach ($this->routes as $i => $route) {
            if ($route['method'] !== $method || ! preg_match($route['pattern'], $path)) {
                continue;
            }

            if ($route['times'] !== null) {
                if ($route['times'] < 1) {
                    continue;
                }

                $this->routes[$i]['times']--;
            }

            $response = $route['response'];

            if (is_callable($response)) {
                $response = $response($params, $request);
            }

            return $this->encode($response);
        }

        $message = 'Unscripted Stripe request: ' . strtoupper($method) . ' ' . $path;
        $this->violations[] = $message;

        throw new \LogicException($message);
    }

    /** The params of the most recent request to a path, or null. */
    public function sent(string $method, string $path): ?array
    {
        $pattern = '#^' . str_replace('\*', '[^/]+', preg_quote($path, '#')) . '$#';

        foreach (array_reverse($this->requests) as $request) {
            if ($request['method'] === strtolower($method) && preg_match($pattern, $request['path'])) {
                return $request['params'];
            }
        }

        return null;
    }

    /** The headers of the most recent request to a path, or null. */
    public function sentHeaders(string $method, string $path): ?array
    {
        $pattern = '#^' . str_replace('\*', '[^/]+', preg_quote($path, '#')) . '$#';

        foreach (array_reverse($this->requests) as $request) {
            if ($request['method'] === strtolower($method) && preg_match($pattern, $request['path'])) {
                return $request['headers'];
            }
        }

        return null;
    }

    public function count(string $method, string $path): int
    {
        $pattern = '#^' . str_replace('\*', '[^/]+', preg_quote($path, '#')) . '$#';

        return count(array_filter(
            $this->requests,
            fn ($r) => $r['method'] === strtolower($method) && preg_match($pattern, $r['path'])
        ));
    }

    public function assertSent(string $method, string $path, ?callable $check = null): void
    {
        $params = $this->sent($method, $path);

        Assert::assertNotNull($params, 'Stripe was not sent ' . strtoupper($method) . ' ' . $path);

        if ($check) {
            Assert::assertTrue((bool) $check($params), 'Stripe was sent ' . strtoupper($method) . ' ' . $path . ' but not as expected.');
        }
    }

    public function assertNotSent(string $method, string $path): void
    {
        Assert::assertSame(0, $this->count($method, $path), 'Stripe was sent ' . strtoupper($method) . ' ' . $path);
    }

    public function assertNoViolations(): void
    {
        Assert::assertSame([], $this->violations, "Stripe's rules were broken or a request was unscripted.");
    }

    /* ---------------------------------------------------------------------
     | Object builders: the shapes the billing module reads, at the pinned
     | API version. Override any key with $overrides.
     | ------------------------------------------------------------------- */

    public static function customer(string $id = 'cus_test_1', array $overrides = []): array
    {
        return array_replace_recursive(['id' => $id, 'object' => 'customer', 'livemode' => false, 'email' => null, 'name' => null, 'metadata' => []], $overrides);
    }

    public static function subscription(string $id, string $customer, string $price, string $product, string $status = 'active', array $overrides = []): array
    {
        $periodEnd = time() + 30 * 86400;

        return array_replace_recursive([
            'id' => $id,
            'object' => 'subscription',
            'customer' => $customer,
            'status' => $status,
            'livemode' => false,
            'cancel_at' => null,
            'cancel_at_period_end' => false,
            'canceled_at' => null,
            'latest_invoice' => 'in_test_1',
            'metadata' => [],
            'items' => [
                'object' => 'list',
                'data' => [[
                    'id' => 'si_' . substr(md5($id), 0, 10),
                    'object' => 'subscription_item',
                    'current_period_end' => $periodEnd,
                    'price' => ['id' => $price, 'object' => 'price', 'product' => $product, 'recurring' => ['interval' => 'month']],
                ]],
            ],
        ], $overrides);
    }

    public static function checkoutSession(string $id, string $paymentStatus = 'paid', array $overrides = []): array
    {
        return array_replace_recursive([
            'id' => $id,
            'object' => 'checkout.session',
            'mode' => 'subscription',
            'status' => $paymentStatus === 'paid' ? 'complete' : 'open',
            'payment_status' => $paymentStatus,
            'url' => 'https://checkout.stripe.com/c/pay/' . $id,
            'client_reference_id' => null,
            'customer' => 'cus_test_1',
            'subscription' => null,
            'livemode' => false,
        ], $overrides);
    }

    public static function invoice(string $id, ?string $subscription, string $status = 'paid', int $amountPaid = 11000, array $overrides = []): array
    {
        return array_replace_recursive([
            'id' => $id,
            'object' => 'invoice',
            'status' => $status,
            'amount_paid' => $amountPaid,
            'livemode' => false,
            'parent' => $subscription ? ['type' => 'subscription_details', 'subscription_details' => ['subscription' => $subscription, 'metadata' => []]] : null,
        ], $overrides);
    }

    public static function price(string $id, string $product, int $amount, string $interval = 'month', bool $active = true): array
    {
        return ['id' => $id, 'object' => 'price', 'product' => $product, 'unit_amount' => $amount, 'currency' => 'aud', 'active' => $active, 'recurring' => ['interval' => $interval]];
    }

    /* ------------------------------------------------------------------- */

    private function breach(array $request): ?string
    {
        $auth = $request['headers']['authorization'] ?? '';

        if (str_starts_with($auth, 'Bearer sk_')) {
            return 'A secret key reached Stripe. Billing runs on restricted keys (rk_) only.';
        }

        if (($request['headers']['stripe-version'] ?? null) !== StripeClientFactory::API_VERSION) {
            return 'Stripe-Version was not the pinned ' . StripeClientFactory::API_VERSION . '.';
        }

        if ($this->hasKey($request['params'], 'payment_method_types')) {
            return 'payment_method_types is not allowed: payment methods are managed in the Dashboard.';
        }

        $params = $request['params'];

        if ($request['method'] === 'post' && $request['path'] === '/v1/checkout/sessions') {
            if (! empty($params['customer']) && ! empty($params['customer_email'])) {
                return 'You may only specify one of these parameters: customer, customer_email.';
            }

            if (! empty($params['customer']) && ! empty($params['tax_id_collection']['enabled'])
                && ($params['customer_update']['name'] ?? null) !== 'auto') {
                return 'tax_id_collection with an existing customer requires customer_update[name] to be auto.';
            }

            $trialEnd = $params['subscription_data']['trial_end'] ?? null;

            if ($trialEnd !== null && (int) $trialEnd < time() + 48 * 3600) {
                return 'subscription_data[trial_end] must be at least 48 hours in the future.';
            }

            if (($params['mode'] ?? null) === 'subscription' && empty($params['line_items'][0]['price']) && empty($params['line_items'][0]['price_data'])) {
                return 'A subscription-mode Checkout Session needs at least one recurring Price.';
            }
        }

        if ($request['method'] === 'post' && preg_match('#^/v1/prices/[^/]+$#', $request['path'])) {
            foreach (['unit_amount', 'currency', 'recurring', 'product'] as $immutable) {
                if (array_key_exists($immutable, $params)) {
                    return "Received unknown parameter: {$immutable}. A Price's amount and terms cannot be changed; create a new Price.";
                }
            }
        }

        return null;
    }

    private function hasKey(array $params, string $key): bool
    {
        foreach ($params as $name => $value) {
            if ($name === $key) {
                return true;
            }

            if (is_array($value) && $this->hasKey($value, $key)) {
                return true;
            }
        }

        return false;
    }

    private function encode($response): array
    {
        [$status, $body] = is_array($response) && array_key_exists(0, $response) && is_int($response[0]) && array_key_exists(1, $response)
            ? $response
            : [200, $response];

        return [json_encode($body ?? new \stdClass()), $status, ['request-id' => 'req_fake_' . count($this->requests)]];
    }
}
