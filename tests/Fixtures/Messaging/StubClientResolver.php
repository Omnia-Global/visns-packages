<?php

namespace Visnsstudio\VisnsPackages\Tests\Fixtures\Messaging;

use Visnsstudio\VisnsPackages\Contracts\CallerEnrichment;

/**
 * Stands in for the CRM's number-to-client lookup.
 *
 * Implements CallerEnrichment on purpose: the messaging module's
 * `client_resolver` is deliberately the same contract as the call queue's
 * caller enrichment, so an application passes ONE implementation to both.
 */
class StubClientResolver implements CallerEnrichment
{
    /** @var array<int, ?string> */
    public static array $calls = [];

    public static bool $shouldThrow = false;

    public static function reset(): void
    {
        self::$calls = [];
        self::$shouldThrow = false;
    }

    public function __invoke(?string $number): ?array
    {
        self::$calls[] = $number;

        if (self::$shouldThrow) {
            throw new \RuntimeException('client lookup exploded');
        }

        return $number === '+61412345678'
            ? ['id' => 7, 'name' => 'Cleo Client', 'adviser' => 'Ada Adviser']
            : null;
    }
}
