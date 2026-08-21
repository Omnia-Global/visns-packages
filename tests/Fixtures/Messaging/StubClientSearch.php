<?php

namespace Visnsstudio\VisnsPackages\Tests\Fixtures\Messaging;

/**
 * Stands in for the CRM's client search, behind `messaging.client_search`.
 */
class StubClientSearch
{
    public static bool $shouldThrow = false;

    public static function reset(): void
    {
        self::$shouldThrow = false;
    }

    public function __invoke(string $term): array
    {
        if (self::$shouldThrow) {
            throw new \RuntimeException('search exploded');
        }

        if (stripos('Cleo Client', $term) === false) {
            return [];
        }

        return [[
            'id' => 7,
            'name' => 'Cleo Client',
            'numbers' => [['label' => 'Mobile', 'number' => '+61412345678']],
        ]];
    }
}
