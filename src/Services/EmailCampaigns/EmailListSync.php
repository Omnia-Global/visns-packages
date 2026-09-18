<?php

namespace Visnsstudio\VisnsPackages\Services\EmailCampaigns;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Visnsstudio\VisnsPackages\Models\EmailCampaign;
use Visnsstudio\VisnsPackages\Models\EmailList;
use Visnsstudio\VisnsPackages\Models\EmailListMember;
use Visnsstudio\VisnsPackages\Support\ModuleConfig;

/**
 * One tick of `email-campaigns:sync`: carry the CRM's lists to Resend.
 *
 * Paced (`sync.per_second`) and time-boxed (`sync.seconds`) because Resend allows
 * ten requests a second per team and the host's own transactional mail shares
 * them, and because the scheduler runs this every minute with
 * `withoutOverlapping()`, so a tick that overran would cost the next one.
 *
 * A 429 ends the tick with nothing marked failed: the members stay pending and
 * the next minute carries on. Any other refusal marks that one member failed
 * with Resend's sentence, and the rest of the list goes on.
 *
 * It also asks Resend about campaigns still `sending`, and records them sent.
 */
class EmailListSync
{
    /** @var callable(int): void */
    private $sleep;

    public function __construct(private ResendClient $resend)
    {
        $this->sleep = static fn (int $microseconds) => usleep($microseconds);
    }

    /** Tests replace the sleep so a paced tick costs no time. */
    public function sleepUsing(callable $sleep): self
    {
        $this->sleep = $sleep;

        return $this;
    }

    /**
     * @return array{synced: int, removed: int, failed: int, lists: int, throttled: bool, campaigns: int}
     */
    public function tick(?int $onlyListId = null): array
    {
        $report = ['synced' => 0, 'removed' => 0, 'failed' => 0, 'lists' => 0, 'throttled' => false, 'campaigns' => 0];

        if (! $this->resend->configured()) {
            return $report;
        }

        $deadline = microtime(true) + max(1, (int) ModuleConfig::get('email_campaigns.sync.seconds', 45));
        $gap = (int) floor(1_000_000 / max(1, (int) ModuleConfig::get('email_campaigns.sync.per_second', 5)));

        $lists = EmailList::query()
            ->when($onlyListId, fn ($q) => $q->whereKey($onlyListId))
            ->where(function ($q) {
                $q->whereNull('resend_segment_id')
                    ->orWhereHas('members', fn ($m) => $m->whereIn('status', [
                        EmailListMember::STATUS_PENDING,
                        EmailListMember::STATUS_REMOVING,
                    ]));
            })
            ->orderBy('id')
            ->get();

        $this->ensureCompanyProperty();

        foreach ($lists as $list) {
            if (microtime(true) >= $deadline) {
                break;
            }

            $report['lists']++;

            if ($list->resend_segment_id === null) {
                $made = $this->resend->createSegment($this->segmentName($list));
                ($this->sleep)($gap);

                if (! $made->ok || empty($made->data['id'])) {
                    $list->forceFill(['sync_error' => $made->error ?? 'Resend did not return a segment.'])->save();
                    $report['throttled'] = $report['throttled'] || $made->throttled();

                    if ($made->throttled()) {
                        break;
                    }

                    continue;
                }

                $list->forceFill(['resend_segment_id' => (string) $made->data['id'], 'sync_error' => null])->save();
            }

            $members = $list->members()
                ->whereIn('status', [EmailListMember::STATUS_PENDING, EmailListMember::STATUS_REMOVING])
                ->orderBy('id')
                ->cursor();

            foreach ($members as $member) {
                if (microtime(true) >= $deadline) {
                    break 2;
                }

                $result = $member->status === EmailListMember::STATUS_REMOVING
                    ? $this->resend->removeFromSegment($member->email, $list->resend_segment_id)
                    : $this->resend->upsertContact($member->email, $this->fields($member), $list->resend_segment_id);

                ($this->sleep)($gap);

                if ($result->throttled()) {
                    $report['throttled'] = true;

                    break 2;
                }

                if (! $result->ok) {
                    $member->forceFill([
                        'status' => EmailListMember::STATUS_FAILED,
                        'error' => mb_substr((string) $result->error, 0, 500),
                    ])->save();
                    $report['failed']++;

                    continue;
                }

                if ($member->status === EmailListMember::STATUS_REMOVING) {
                    $member->delete();
                    $report['removed']++;
                } else {
                    $member->forceFill([
                        'status' => EmailListMember::STATUS_SYNCED,
                        'synced_at' => now(),
                        'error' => null,
                    ])->save();
                    $report['synced']++;
                }
            }

            if (! $list->members()->whereIn('status', [EmailListMember::STATUS_PENDING, EmailListMember::STATUS_REMOVING])->exists()) {
                $list->forceFill(['synced_at' => now(), 'sync_error' => null])->save();
            }
        }

        $report['campaigns'] = $this->settleSending($deadline);

        return $report;
    }

    /** Campaigns Resend has not finished with: ask, and record the answer. */
    private function settleSending(float $deadline): int
    {
        $settled = 0;

        $campaigns = EmailCampaign::query()
            ->whereIn('status', [EmailCampaign::STATUS_SENDING, EmailCampaign::STATUS_SCHEDULED])
            ->whereNotNull('resend_broadcast_id')
            ->get();

        foreach ($campaigns as $campaign) {
            if (microtime(true) >= $deadline) {
                break;
            }

            // A scheduled one is not worth asking about before its time.
            if ($campaign->status === EmailCampaign::STATUS_SCHEDULED
                && $campaign->scheduled_at && $campaign->scheduled_at->isFuture()) {
                continue;
            }

            $result = $this->resend->getBroadcast($campaign->resend_broadcast_id);

            if (! $result->ok) {
                continue;
            }

            $status = strtolower((string) ($result->data['status'] ?? ''));

            if ($status === 'sent') {
                $campaign->forceFill([
                    'status' => EmailCampaign::STATUS_SENT,
                    'sent_at' => $campaign->sent_at ?? ($result->data['sent_at'] ?? now()),
                ])->save();
                $settled++;
            } elseif (in_array($status, ['queued', 'sending'], true) && $campaign->status === EmailCampaign::STATUS_SCHEDULED) {
                $campaign->forceFill(['status' => EmailCampaign::STATUS_SENDING])->save();
            }
        }

        return $settled;
    }

    /** @return array<string, mixed> */
    private function fields(EmailListMember $member): array
    {
        return array_filter([
            'first_name' => $member->first_name,
            'last_name' => $member->last_name,
            'properties' => $member->company ? ['company' => $member->company] : null,
        ], fn ($value) => $value !== null);
    }

    private function segmentName(EmailList $list): string
    {
        $app = trim((string) config('app.name', 'CRM'));

        return mb_substr($app . ': ' . $list->name . ' #' . $list->id, 0, 100);
    }

    /** `{{{contact.company}}}` needs the property to exist. Once a day is plenty. */
    private function ensureCompanyProperty(): void
    {
        Cache::remember('email_campaigns:property:company', now()->addDay(), function () {
            $result = $this->resend->ensureProperty('company');

            if (! $result->ok) {
                Log::warning('email_campaigns.property_failed', ['error' => $result->error]);

                return null;
            }

            return true;
        });
    }
}
