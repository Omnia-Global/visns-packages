<?php

namespace Visnsstudio\VisnsPackages\Tests\Fixtures\Billing;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Visnsstudio\VisnsPackages\Contracts\BillableResolver;

/**
 * A host resolver: the "session" is a static, as a portal guard's client
 * would be, and only the `client` morph type is billable.
 */
class StubBillableResolver implements BillableResolver
{
    public static ?int $sessionClientId = null;

    public static bool $mayManage = true;

    public static function reset(): void
    {
        self::$sessionClientId = null;
        self::$mayManage = true;
    }

    public function resolve(Request $request): ?Model
    {
        return self::$sessionClientId ? BillingClient::query()->find(self::$sessionClientId) : null;
    }

    public function canManage(Request $request, Model $billable): bool
    {
        return self::$mayManage;
    }

    public function find(string $type, int $id): ?Model
    {
        return $type === 'client' ? BillingClient::query()->find($id) : null;
    }

    public function describe(Model $billable): array
    {
        return ['name' => $billable->name, 'email' => $billable->email];
    }
}
