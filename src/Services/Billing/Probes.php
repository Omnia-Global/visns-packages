<?php

namespace Visnsstudio\VisnsPackages\Services\Billing;

use Stripe\Exception\ApiConnectionException;
use Stripe\Exception\ApiErrorException;
use Stripe\Exception\AuthenticationException;
use Stripe\Exception\PermissionException;
use Visnsstudio\VisnsPackages\Support\ModuleConfig;

/**
 * "Test connection" for the Stripe integration.
 *
 * A static array callable, never a closure, so `config:cache` can serialise
 * the definition. Never throws, never repeats the key.
 */
final class Probes
{
    /** @return array{success: bool, message: string} */
    public static function stripe(array $credentials): array
    {
        if ($problem = StripeKeys::problem($credentials, true)) {
            return ['success' => false, 'message' => $problem];
        }

        $key = trim((string) $credentials[StripeKeys::KEY_FIELD]);
        $mode = StripeKeys::livemode($key) ? 'live' : 'test';
        $client = app(StripeClientFactory::class)->forKey($key);

        try {
            $client->balance->retrieve();
        } catch (AuthenticationException $e) {
            return ['success' => false, 'message' => 'Stripe refused the key. It may have been rolled or deleted.'];
        } catch (PermissionException $e) {
            return ['success' => false, 'message' => 'The key cannot read the balance. Give the restricted key Balance read.'];
        } catch (ApiConnectionException $e) {
            return ['success' => false, 'message' => 'Could not reach Stripe.'];
        } catch (ApiErrorException $e) {
            return ['success' => false, 'message' => 'Stripe answered ' . ($e->getHttpStatus() ?: 'an error') . ' to a balance read.'];
        }

        $notes = ["Connected to Stripe in {$mode} mode."];

        $taxRateId = trim((string) ($credentials['tax_rate_id'] ?? '')) ?: trim((string) ModuleConfig::get('billing.tax_rate_id', ''));

        if ($taxRateId !== '') {
            try {
                $rate = $client->taxRates->retrieve($taxRateId);
            } catch (PermissionException $e) {
                return ['success' => false, 'message' => 'The key cannot read tax rates. Give the restricted key Tax rates read.'];
            } catch (ApiErrorException $e) {
                return ['success' => false, 'message' => "Stripe has no tax rate {$taxRateId} in {$mode} mode."];
            }

            $expected = (float) ModuleConfig::get('billing.tax_percentage', 10);

            if (! $rate->active) {
                return ['success' => false, 'message' => "Tax rate {$taxRateId} is archived. Create an active one."];
            }

            if (abs((float) $rate->percentage - $expected) > 0.0001) {
                return ['success' => false, 'message' => "Tax rate {$taxRateId} is {$rate->percentage}%, not {$expected}%."];
            }

            if ($rate->inclusive) {
                return ['success' => false, 'message' => "Tax rate {$taxRateId} is inclusive. Prices are ex GST, so the rate must be exclusive."];
            }

            $notes[] = "GST rate {$taxRateId} is {$expected}% exclusive.";
        } else {
            $notes[] = 'No GST tax rate is set, so Checkout will charge no GST.';
        }

        if (trim((string) ($credentials[StripeKeys::SECRET_FIELD] ?? '')) === '') {
            return ['success' => false, 'message' => implode(' ', $notes) . ' The webhook signing secret is missing, so every event will be refused.'];
        }

        return ['success' => true, 'message' => implode(' ', $notes)];
    }
}
