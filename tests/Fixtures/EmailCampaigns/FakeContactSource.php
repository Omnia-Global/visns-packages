<?php

namespace Visnsstudio\VisnsPackages\Tests\Fixtures\EmailCampaigns;

use Visnsstudio\VisnsPackages\Contracts\EmailContactSource;

/** Two clients and their people, for the list builder. */
class FakeContactSource implements EmailContactSource
{
    /** @var array<string, array<int, array<string, mixed>>> group id => people */
    public static array $people = [];

    public static function reset(): void
    {
        self::$people = [
            'c1' => [
                ['email' => 'Kim@Acura.test', 'first_name' => 'Kim', 'last_name' => 'Alvarez', 'company' => 'Acura', 'key' => '11', 'primary' => true],
                ['email' => 'lee@acura.test', 'first_name' => 'Lee', 'last_name' => 'Ng', 'company' => 'Acura', 'key' => '12', 'primary' => false],
                ['email' => 'not-an-email', 'first_name' => 'Broken', 'company' => 'Acura', 'key' => '13', 'primary' => false],
            ],
            'c2' => [
                ['email' => 'sam@bethesda.test', 'first_name' => 'Sam', 'last_name' => 'Ray', 'company' => 'Bethesda', 'key' => '21', 'primary' => true],
                // Also on Acura: one person, once.
                ['email' => 'kim@acura.test', 'first_name' => 'Kim', 'last_name' => 'Alvarez', 'company' => 'Acura', 'key' => '11', 'primary' => true],
            ],
        ];
    }

    public function groupNoun(): array
    {
        return ['singular' => 'client', 'plural' => 'clients'];
    }

    public function groups(string $search = '', int $limit = 25): array
    {
        return array_values(array_filter([
            ['id' => 'c1', 'label' => 'Acura Group'],
            ['id' => 'c2', 'label' => 'Bethesda'],
        ], fn ($g) => $search === '' || stripos($g['label'], $search) !== false));
    }

    public function groupLabels(array $ids): array
    {
        return array_intersect_key(['c1' => 'Acura Group', 'c2' => 'Bethesda'], array_flip($ids));
    }

    public function options(): array
    {
        return ['primary_only' => 'Primary contacts only'];
    }

    public function contacts(array $filters): iterable
    {
        $groups = $filters['groups'] ?? [];
        $primaryOnly = (bool) (($filters['options'] ?? [])['primary_only'] ?? false);

        foreach (self::$people as $group => $people) {
            if ($groups && ! in_array($group, $groups, true)) {
                continue;
            }

            foreach ($people as $person) {
                if ($primaryOnly && ! $person['primary']) {
                    continue;
                }

                yield $person;
            }
        }
    }
}
