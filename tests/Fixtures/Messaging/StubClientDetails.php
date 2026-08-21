<?php

namespace Visnsstudio\VisnsPackages\Tests\Fixtures\Messaging;

/**
 * Stands in for the CRM's client id -> detail lookup
 * (`messaging.client_details`): the block the composer fills a template's
 * `{first_name}` and `{date}` from when one conversation is opened.
 */
class StubClientDetails
{
    /** @var array<int, mixed> */
    public static array $calls = [];

    public static bool $shouldThrow = false;

    /** Returned as-is when set, so a test can shape the answer it needs. */
    public static ?array $answer = null;

    public static function reset(): void
    {
        self::$calls = [];
        self::$shouldThrow = false;
        self::$answer = null;
    }

    public function __invoke($clientId): ?array
    {
        self::$calls[] = $clientId;

        if (self::$shouldThrow) {
            throw new \RuntimeException('client details exploded');
        }

        if (self::$answer !== null) {
            return self::$answer;
        }

        return [
            'first_name' => 'Cleo',
            'last_name' => 'Client',
            'next_event' => [
                'title' => 'Annual review',
                'date' => '2026-08-24T14:30:00+08:00',
            ],
        ];
    }
}
