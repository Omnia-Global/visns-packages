<?php

namespace Visnsstudio\VisnsPackages\Services\Billing;

/**
 * The `stripe` card on Settings → Integrations.
 *
 * Declared by the package when billing is on and the host's own
 * `integrations` has no entry under that name; a host that wants different
 * wording can declare its own, ideally starting from this array. No `env`
 * fallbacks: the keys are pasted by staff, per environment.
 */
final class StripeIntegration
{
    public static function definition(): array
    {
        return [
            'driver' => 'api_key',
            'name' => 'Stripe',
            'category' => 'Billing',
            'icon' => 'credit-card',
            'description' => 'Self-service subscriptions: Stripe Checkout, the hosted Customer Portal and the webhook that keeps access in step.',
            'docs_url' => 'https://docs.stripe.com/keys/restricted-api-keys',
            'enabled' => true,
            'test' => [Probes::class, 'stripe'],
            'validate' => [StripeKeys::class, 'validate'],
            'fields' => [
                StripeKeys::KEY_FIELD => [
                    'label' => 'Restricted key',
                    'required' => true,
                    'secret' => true,
                    'placeholder' => 'rk_live_...',
                    'help' => 'Dashboard → Developers → API keys → Create restricted key. Write on Customers, Checkout Sessions and Customer portal; read on Subscriptions, Invoices, Invoice payments, Charges, Disputes, Early fraud warnings, Tax rates and Balance; write on Products and Prices for catalogue sync. A secret key (sk_) is refused.',
                ],
                StripeKeys::SECRET_FIELD => [
                    'label' => 'Webhook signing secret',
                    'required' => true,
                    'secret' => true,
                    'placeholder' => 'whsec_...',
                    'help' => 'Workbench → Webhooks → the endpoint for this site → Signing secret. Without it every event is refused.',
                ],
                StripeKeys::MODE_FIELD => [
                    'label' => 'Mode',
                    'type' => 'select',
                    'options' => ['test', 'live'],
                    'default' => 'test',
                    'required' => true,
                    'help' => 'Must match the key: rk_test_ is test, rk_live_ is live.',
                ],
                'tax_rate_id' => [
                    'label' => 'GST tax rate id',
                    'placeholder' => 'txr_...',
                    'help' => 'The 10% exclusive GST Tax Rate applied to every subscription.',
                ],
                'portal_configuration_id' => [
                    'label' => 'Customer Portal configuration id',
                    'placeholder' => 'bpc_...',
                    'help' => 'Optional. Blank uses the account\'s default portal configuration.',
                ],
            ],
        ];
    }
}
