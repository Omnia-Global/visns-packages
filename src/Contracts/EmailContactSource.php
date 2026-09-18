<?php

namespace Visnsstudio\VisnsPackages\Contracts;

/**
 * Where an email campaign's pickable contacts come from: the host application's
 * own people (a CRM's contacts), grouped however the host groups them (a CRM's
 * clients). Named in `visns-packages.email_campaigns.contact_source`.
 *
 * A list built from a source stores the FILTERS, not the people, and is re-read
 * whenever it is refreshed — so a client's new contact is on the next campaign
 * without anybody rebuilding the list.
 */
interface EmailContactSource
{
    /**
     * What the groups are called, for the screen: ['singular' => 'client',
     * 'plural' => 'clients'].
     *
     * @return array{singular: string, plural: string}
     */
    public function groupNoun(): array;

    /**
     * Groups matching a search, for the picker.
     *
     * @return array<int, array{id: string, label: string}>
     */
    public function groups(string $search = '', int $limit = 25): array;

    /**
     * Labels for group ids already chosen, so a saved list can show them.
     *
     * @param  array<int, string>  $ids
     * @return array<string, string>  id => label
     */
    public function groupLabels(array $ids): array;

    /**
     * Extra yes/no filters the list builder offers, e.g.
     * ['primary_only' => 'Primary contacts only'].
     *
     * @return array<string, string>
     */
    public function options(): array;

    /**
     * Everybody matching `['groups' => [ids] (empty = everyone), 'options' =>
     * [key => bool]]`, with an email address. Duplicates by email are fine:
     * the list keeps the first.
     *
     * @return iterable<array{email: string, first_name: ?string, last_name: ?string, company: ?string, key: ?string}>
     */
    public function contacts(array $filters): iterable;
}
