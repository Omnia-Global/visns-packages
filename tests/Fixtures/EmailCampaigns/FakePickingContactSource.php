<?php

namespace Visnsstudio\VisnsPackages\Tests\Fixtures\EmailCampaigns;

use Visnsstudio\VisnsPackages\Contracts\EmailContactPicker;

/** FakeContactSource that can also pick people one by one, plus a contact in no group. */
class FakePickingContactSource extends FakeContactSource implements EmailContactPicker
{
    /** @var array<int, array<string, mixed>> people in no group */
    public static array $loose = [
        ['email' => 'pat@solo.test', 'first_name' => 'Pat', 'last_name' => 'Solo', 'company' => null, 'key' => '31', 'primary' => false],
    ];

    /** @return array<string, array<string, mixed>> key => person, everyone once */
    private function everyone(): array
    {
        $out = [];

        foreach (array_merge(array_merge(...array_values(self::$people)), self::$loose) as $person) {
            $out[$person['key']] ??= $person;
        }

        return $out;
    }

    public function people(string $search = '', int $limit = 25): array
    {
        $rows = [];

        foreach ($this->everyone() as $person) {
            if (! str_contains((string) $person['email'], '@')) {
                continue;
            }

            $hay = strtolower($person['email'] . ' ' . $person['first_name'] . ' ' . ($person['last_name'] ?? '') . ' ' . ($person['company'] ?? ''));

            if ($search === '' || str_contains($hay, strtolower($search))) {
                $rows[] = ['key' => $person['key'], 'label' => trim($person['first_name'] . ' ' . ($person['last_name'] ?? '')), 'email' => $person['email'], 'company' => $person['company']];
            }
        }

        return array_slice($rows, 0, $limit);
    }

    public function peopleByKeys(array $keys): iterable
    {
        foreach ($this->everyone() as $key => $person) {
            if (in_array((string) $key, $keys, true)) {
                yield $person;
            }
        }
    }
}
