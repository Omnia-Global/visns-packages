<?php

namespace Visnsstudio\VisnsPackages\Services\EmailCampaigns;

use Illuminate\Support\Facades\DB;
use Visnsstudio\VisnsPackages\Contracts\EmailContactPicker;
use Visnsstudio\VisnsPackages\Contracts\EmailContactSource;
use Visnsstudio\VisnsPackages\Models\EmailList;
use Visnsstudio\VisnsPackages\Models\EmailListMember;
use Visnsstudio\VisnsPackages\Support\ModuleConfig;

/**
 * Who is on a list: re-read from the contact source, or taken from a CSV.
 *
 * It only ever changes the CRM's rows. New people go in `pending`; people who no
 * longer match go to `removing`; `EmailListSync` carries both to Resend on the
 * next tick. Nothing here talks to Resend.
 *
 * An unsubscribed person stays unsubscribed: `unsubscribed_at` is Resend's fact,
 * mirrored by the webhook, and a refresh never clears it.
 */
class EmailListBuilder
{
    public function source(): ?EmailContactSource
    {
        $class = ModuleConfig::get('email_campaigns.contact_source');

        if (is_string($class) && $class !== '' && class_exists($class)) {
            $source = app($class);

            return $source instanceof EmailContactSource ? $source : null;
        }

        return null;
    }

    /** The source, when it can also pick people one by one; else null. */
    public function picker(): ?EmailContactPicker
    {
        $source = $this->source();

        return $source instanceof EmailContactPicker ? $source : null;
    }

    /**
     * The hand-picked people's keys a CRM list's filters carry.
     *
     * @return array<int, string>
     */
    public static function pickedKeys(?array $filters): array
    {
        return array_values(array_unique(array_filter(array_map(
            fn ($key) => mb_substr(trim((string) $key), 0, 64),
            (array) (($filters ?? [])['contacts'] ?? [])
        ), fn ($key) => $key !== '')));
    }

    /**
     * Does this CRM list take every contact? Only when it names no group AND
     * no hand-picked person (a list of picked people alone is just them).
     */
    public static function takesEveryone(?array $filters): bool
    {
        return ((array) (($filters ?? [])['groups'] ?? [])) === [] && self::pickedKeys($filters) === [];
    }

    /**
     * Re-read a CRM list from its filters: the chosen groups' people (or
     * everybody, when nothing is chosen) plus the hand-picked people, re-read
     * by key so a changed address follows the contact.
     *
     * @return array{added: int, removed: int, kept: int, total: int}
     */
    public function refresh(EmailList $list): array
    {
        $source = $this->source();

        if ($list->kind !== EmailList::KIND_CRM || $source === null) {
            return ['added' => 0, 'removed' => 0, 'kept' => 0, 'total' => $list->members()->count()];
        }

        $filters = (array) $list->filters;
        $picked = self::pickedKeys($filters);
        $max = (int) ModuleConfig::get('email_campaigns.max_list_size', 20000);
        $wanted = [];

        $take = function (iterable $rows) use (&$wanted, $max): void {
            foreach ($rows as $row) {
                $email = self::email($row['email'] ?? null);

                if ($email === null || isset($wanted[$email])) {
                    continue;
                }

                if (count($wanted) >= $max) {
                    return;
                }

                $wanted[$email] = $row;
            }
        };

        // Picked people first: someone picked by hand is on the list whatever
        // the groups' options say.
        $picker = $source instanceof EmailContactPicker ? $source : null;

        if ($picked !== [] && $picker !== null) {
            $take($picker->peopleByKeys($picked));
        }

        if (((array) ($filters['groups'] ?? [])) !== [] || $picked === []) {
            $take($source->contacts($filters));
        }

        return $this->apply($list, $wanted);
    }

    /**
     * Replace an import list's people with CSV rows.
     *
     * @param  array<int, array<string, mixed>>  $rows  each with `email`, optionally `first_name`, `last_name`, `company`
     * @return array{added: int, removed: int, kept: int, total: int, rejected: array<int, array{line: int, reason: string}>}
     */
    public function import(EmailList $list, array $rows): array
    {
        $wanted = [];
        $rejected = [];

        foreach (array_values($rows) as $index => $row) {
            $email = self::email($row['email'] ?? null);

            if ($email === null) {
                $rejected[] = ['line' => $index + 1, 'reason' => 'Not an email address: ' . mb_substr((string) ($row['email'] ?? ''), 0, 60)];

                continue;
            }

            if (isset($wanted[$email])) {
                $rejected[] = ['line' => $index + 1, 'reason' => 'A second row for ' . $email];

                continue;
            }

            $wanted[$email] = $row + ['key' => null];
        }

        return $this->apply($list, $wanted) + ['rejected' => $rejected];
    }

    /**
     * Add people to an import list without taking anybody off it (people
     * picked from the contact source). Somebody already on it is kept as is.
     *
     * @param  iterable<array<string, mixed>>  $rows
     * @return array{added: int, removed: int, kept: int, total: int}
     */
    public function add(EmailList $list, iterable $rows): array
    {
        $existing = $list->members()->where('status', '!=', EmailListMember::STATUS_REMOVING)->pluck('email')->flip();
        $wanted = [];

        foreach ($rows as $row) {
            $email = self::email($row['email'] ?? null);

            if ($email !== null && ! isset($wanted[$email])) {
                $wanted[$email] = $row;
            }
        }

        $max = (int) ModuleConfig::get('email_campaigns.max_list_size', 20000);
        $new = array_diff_key($wanted, $existing->all());

        if ($existing->count() + count($new) > $max) {
            $new = array_slice($new, 0, max(0, $max - $existing->count()), true);
        }

        return $this->apply($list, $new, false);
    }

    /** @param array<string, array<string, mixed>> $wanted email => row */
    private function apply(EmailList $list, array $wanted, bool $removeMissing = true): array
    {
        $added = 0;
        $removed = 0;
        $kept = 0;

        DB::transaction(function () use ($list, $wanted, $removeMissing, &$added, &$removed, &$kept) {
            $existing = $list->members()->get()->keyBy('email');

            foreach ($wanted as $email => $row) {
                $fields = [
                    'first_name' => self::clip($row['first_name'] ?? null, 100),
                    'last_name' => self::clip($row['last_name'] ?? null, 100),
                    'company' => self::clip($row['company'] ?? null, 191),
                    'contact_key' => isset($row['key']) ? self::clip((string) $row['key'], 64) : null,
                ];

                /** @var EmailListMember|null $member */
                $member = $existing->get($email);

                if ($member === null) {
                    $member = new EmailListMember(['list_id' => $list->id, 'email' => $email] + $fields);
                    $member->status = EmailListMember::STATUS_PENDING;
                    $member->save();
                    $added++;

                    continue;
                }

                $member->fill($fields);

                // Coming back after being taken off, or a name that changed:
                // either way Resend needs telling again.
                if ($member->status === EmailListMember::STATUS_REMOVING || $member->isDirty(['first_name', 'last_name', 'company'])) {
                    $member->status = EmailListMember::STATUS_PENDING;
                }

                $member->save();
                $kept++;
            }

            foreach ($removeMissing ? $existing : [] as $email => $member) {
                if (! isset($wanted[$email]) && $member->status !== EmailListMember::STATUS_REMOVING) {
                    // Never synced: nothing at Resend to take out, just drop it.
                    if ($member->synced_at === null) {
                        $member->delete();
                    } else {
                        $member->status = EmailListMember::STATUS_REMOVING;
                        $member->save();
                    }

                    $removed++;
                }
            }
        });

        return [
            'added' => $added,
            'removed' => $removed,
            'kept' => $kept,
            'total' => $list->members()->where('status', '!=', EmailListMember::STATUS_REMOVING)->count(),
        ];
    }

    public static function email($value): ?string
    {
        $email = strtolower(trim((string) $value));

        return filter_var($email, FILTER_VALIDATE_EMAIL) !== false && strlen($email) <= 191 ? $email : null;
    }

    private static function clip($value, int $max): ?string
    {
        $value = trim((string) $value);

        return $value === '' ? null : mb_substr($value, 0, $max);
    }
}
