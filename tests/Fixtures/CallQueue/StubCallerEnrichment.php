<?php

namespace Visnsstudio\VisnsPackages\Tests\Fixtures\CallQueue;

use Visnsstudio\VisnsPackages\Contracts\CallerEnrichment;

/**
 * Stands in for the CRM's caller-to-client lookup.
 */
class StubCallerEnrichment implements CallerEnrichment
{
    /** @var array<int, ?string> */
    public static array $calls = [];

    public static bool $shouldThrow = false;

    public static function reset(): void
    {
        self::$calls = [];
        self::$shouldThrow = false;
    }

    public function __invoke(?string $callerNumber): ?array
    {
        self::$calls[] = $callerNumber;

        if (self::$shouldThrow) {
            throw new \RuntimeException('client lookup exploded');
        }

        return $callerNumber === '+61412345678'
            ? ['id' => 7, 'name' => 'Cleo Client', 'open_tasks' => 2]
            : null;
    }
}
