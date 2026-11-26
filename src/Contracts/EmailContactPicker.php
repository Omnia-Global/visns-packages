<?php

namespace Visnsstudio\VisnsPackages\Contracts;

/**
 * Optional second half of an `EmailContactSource`: picking PEOPLE one by one
 * (a CRM's contacts), not only whole groups. A source that implements this
 * lets a list carry hand-picked people (`filters.contacts`, their keys), which
 * are re-read by key on every refresh, so a changed email address follows the
 * contact. An import list can take people from here as well.
 *
 * A source without it works exactly as before: the screen offers no people
 * search.
 */
interface EmailContactPicker
{
    /**
     * People matching a search (name, email, company), for the picker. Only
     * people with an email address.
     *
     * @return array<int, array{key: string, label: string, email: string, company: ?string}>
     */
    public function people(string $search = '', int $limit = 25): array;

    /**
     * The people with these keys who still have an email address, as list
     * rows. Unknown or removed keys are left out.
     *
     * @param  array<int, string>  $keys
     * @return iterable<array{email: string, first_name: ?string, last_name: ?string, company: ?string, key: string}>
     */
    public function peopleByKeys(array $keys): iterable;
}
