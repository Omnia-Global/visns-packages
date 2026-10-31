<?php

namespace Visnsstudio\VisnsPackages\Contracts;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;

/**
 * The host application's answer to "who pays", named in
 * `visns-packages.billing.hooks.billable`.
 *
 * A billable party is the host's own client model (a CRM customer, say): any
 * Eloquent model. The package stores it as a morph (`getMorphClass()`,
 * `getKey()`) and never names the class.
 */
interface BillableResolver
{
    /**
     * The billable party a portal request acts for, or null when it acts for
     * nobody. Read from the host's own session (the portal guard's client),
     * never from request input.
     */
    public function resolve(Request $request): ?Model;

    /**
     * May this request commit that party to a charge (start a checkout, open
     * the Customer Portal)? Everyone else sees the list read-only.
     */
    public function canManage(Request $request, Model $billable): bool;

    /**
     * The party a stored morph points at, or null for a type the host does not
     * bill or an id that does not exist. The staff API addresses billables
     * this way, so this is also where the host refuses a type it never bills.
     */
    public function find(string $type, int $id): ?Model;

    /**
     * What the Stripe Customer is created with.
     *
     * @return array{name: ?string, email: ?string}
     */
    public function describe(Model $billable): array;
}
