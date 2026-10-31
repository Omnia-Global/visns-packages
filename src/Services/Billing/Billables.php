<?php

namespace Visnsstudio\VisnsPackages\Services\Billing;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Visnsstudio\VisnsPackages\Contracts\BillableResolver;
use Visnsstudio\VisnsPackages\Exceptions\Billing\BillingUnconfigured;
use Visnsstudio\VisnsPackages\Support\ModuleConfig;

/**
 * The host's billable resolver, and the one way a billable party becomes a
 * morph pair and back.
 */
class Billables
{
    /** @throws BillingUnconfigured when the host named no resolver */
    public function resolver(): BillableResolver
    {
        $configured = ModuleConfig::get('billing.hooks.billable');

        if (is_object($configured) && $configured instanceof BillableResolver) {
            return $configured;
        }

        if (is_string($configured) && $configured !== '' && class_exists($configured)) {
            $resolver = app($configured);

            if ($resolver instanceof BillableResolver) {
                return $resolver;
            }
        }

        if (app()->bound(BillableResolver::class)) {
            return app(BillableResolver::class);
        }

        throw new BillingUnconfigured('No billable resolver is configured (visns-packages.billing.hooks.billable).');
    }

    public function hasResolver(): bool
    {
        try {
            $this->resolver();

            return true;
        } catch (BillingUnconfigured $e) {
            return false;
        }
    }

    /** @return array{0: string, 1: int} */
    public function morph(Model $billable): array
    {
        return [$billable->getMorphClass(), (int) $billable->getKey()];
    }

    /** Narrow a billing table's query to one party. Every cross-record read starts here. */
    public function scope(Builder $query, Model $billable): Builder
    {
        [$type, $id] = $this->morph($billable);

        return $query->where('billable_type', $type)->where('billable_id', $id);
    }

    public function find(?string $type, $id): ?Model
    {
        if (! is_string($type) || $type === '' || ! is_numeric($id)) {
            return null;
        }

        return $this->resolver()->find($type, (int) $id);
    }
}
