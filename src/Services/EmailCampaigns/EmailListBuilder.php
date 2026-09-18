<?php

namespace Visnsstudio\VisnsPackages\Services\EmailCampaigns;

use Illuminate\Support\Facades\DB;
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

    /**
     * Re-read a CRM list from its filters.
     *
     * @return array{added: int, removed: int, kept: int, total: int}
     */
    public function refresh(EmailList $list): array
    {
        $source = $this->source();

        if ($list->kind !== EmailList::KIND_CRM || $source === null) {
            return ['added' => 0, 'removed' => 0, 'kept' => 0, 'total' => $list->members()->count()];
        }

        $wanted = [];

        foreach ($source->contacts((array) $list->filters) as $row) {
            $email = self::email($row['email'] ?? null);

            if ($email === null || isset($wanted[$email])) {
                continue;
            }

            $wanted[$email] = $row;

            if (count($wanted) >= (int) ModuleConfig::get('email_campaigns.max_list_size', 20000)) {
                break;
            }
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

    /** @param array<string, array<string, mixed>> $wanted email => row */
    private function apply(EmailList $list, array $wanted): array
    {
        $added = 0;
        $removed = 0;
        $kept = 0;

        DB::transaction(function () use ($list, $wanted, &$added, &$removed, &$kept) {
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

            foreach ($existing as $email => $member) {
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
