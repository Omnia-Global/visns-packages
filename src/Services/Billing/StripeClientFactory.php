<?php

namespace Visnsstudio\VisnsPackages\Services\Billing;

use Stripe\StripeClient;
use Visnsstudio\VisnsPackages\Exceptions\Billing\BillingUnconfigured;
use Visnsstudio\VisnsPackages\Services\IntegrationRegistry;
use Visnsstudio\VisnsPackages\Support\ModuleConfig;

/**
 * Builds the StripeClient every billing call uses, from the Stripe integration
 * settings.
 *
 * Always an instance with its own key and a pinned API version, never the
 * SDK's global `Stripe::setApiKey()`: a global key is shared with anything
 * else in the process that talks to Stripe.
 */
class StripeClientFactory
{
    /**
     * The one pinned API version. The SDK's own default for stripe-php 22.1.0
     * (Stripe\Util\ApiVersion::CURRENT). The webhook endpoint in Workbench
     * must be created at this same version.
     */
    public const API_VERSION = '2026-10-28.endive';

    public function __construct(private IntegrationRegistry $registry)
    {
    }

    public function provider(): string
    {
        return (string) ModuleConfig::get('billing.integration', 'stripe');
    }

    public function key(): ?string
    {
        $key = trim((string) $this->registry->credential($this->provider(), StripeKeys::KEY_FIELD, ''));

        return $key === '' ? null : $key;
    }

    /** Is there a restricted key to build a client with? */
    public function configured(): bool
    {
        return StripeKeys::keyProblem($this->key()) === null;
    }

    /**
     * The Stripe mode in force, read from the key's prefix.
     *
     * With no key it answers live: an entitlement check must never count a
     * sandbox subscription because a key was removed.
     */
    public function livemode(): bool
    {
        return StripeKeys::livemode($this->key()) ?? true;
    }

    public function webhookSecret(): ?string
    {
        $secret = trim((string) $this->registry->credential($this->provider(), StripeKeys::SECRET_FIELD, ''));

        return $secret === '' ? null : $secret;
    }

    /** The GST Tax Rate: the integration's field first, then config. */
    public function taxRateId(): ?string
    {
        $id = trim((string) $this->registry->credential($this->provider(), 'tax_rate_id', ''));

        if ($id === '') {
            $id = trim((string) ModuleConfig::get('billing.tax_rate_id', ''));
        }

        return $id === '' ? null : $id;
    }

    public function portalConfigurationId(): ?string
    {
        $id = trim((string) $this->registry->credential($this->provider(), 'portal_configuration_id', ''));

        if ($id === '') {
            $id = trim((string) ModuleConfig::get('billing.portal_configuration_id', ''));
        }

        return $id === '' ? null : $id;
    }

    /** @throws BillingUnconfigured */
    public function make(): StripeClient
    {
        return $this->forKey((string) $this->key());
    }

    /**
     * A client for one key, refused unless it is a restricted key. The probe
     * uses this with the credentials it was handed.
     *
     * @throws BillingUnconfigured
     */
    public function forKey(string $key): StripeClient
    {
        if (StripeKeys::keyProblem($key) !== null) {
            throw new BillingUnconfigured('Stripe is not configured with a restricted key.');
        }

        return new StripeClient([
            'api_key' => trim($key),
            'stripe_version' => self::API_VERSION,
            'max_network_retries' => 2,
        ]);
    }
}
