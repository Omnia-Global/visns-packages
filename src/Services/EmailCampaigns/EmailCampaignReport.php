<?php

namespace Visnsstudio\VisnsPackages\Services\EmailCampaigns;

use Illuminate\Support\Facades\DB;
use Visnsstudio\VisnsPackages\Models\EmailCampaign;
use Visnsstudio\VisnsPackages\Models\EmailCampaignEvent;
use Visnsstudio\VisnsPackages\Models\EmailListMember;

/**
 * A campaign's numbers, from the events Resend reported. The screen adds
 * nothing up: every rate is worked out here, once.
 *
 * Opens and clicks are counted by PERSON (distinct addresses), which is what a
 * campaign tool means by an open rate; the raw totals ride alongside. Rates are
 * over DELIVERED, not over sent, so a bounce does not drag the open rate down.
 *
 * Opens are an estimate and the screen says so: Apple Mail Privacy Protection
 * opens every message it downloads, and many clients block the pixel entirely.
 * Clicks are the reliable signal.
 */
class EmailCampaignReport
{
    /** @return array<string, mixed> */
    public function for(EmailCampaign $campaign): array
    {
        $events = EmailCampaignEvent::query()->where('campaign_id', $campaign->id);

        $people = fn (string $type) => (clone $events)->where('type', $type)->whereNotNull('email')->distinct()->count('email');
        $total = fn (string $type) => (clone $events)->where('type', $type)->count();

        $delivered = $people('delivered');
        $opened = $people('opened');
        $clicked = $people('clicked');
        $bounced = $people('bounced');
        $complained = $people('complained');

        $unsubscribed = $campaign->sent_at && $campaign->list_id
            ? EmailListMember::query()
                ->where('list_id', $campaign->list_id)
                ->where('unsubscribed_at', '>=', $campaign->sent_at)
                ->count()
            : 0;

        $rate = fn (int $count) => $delivered > 0 ? round($count / $delivered * 100, 1) : null;

        $links = (clone $events)
            ->where('type', 'clicked')
            ->whereNotNull('link')
            ->select('link', DB::raw('COUNT(*) as clicks'), DB::raw('COUNT(DISTINCT email) as people'))
            ->groupBy('link')
            ->orderByDesc('clicks')
            ->limit(25)
            ->get()
            ->map(fn ($row) => ['url' => $row->link, 'clicks' => (int) $row->clicks, 'people' => (int) $row->people])
            ->all();

        $activity = (clone $events)
            ->whereIn('type', ['opened', 'clicked', 'bounced', 'complained'])
            ->orderByDesc('occurred_at')
            ->orderByDesc('id')
            ->limit(50)
            ->get()
            ->map(fn (EmailCampaignEvent $event) => [
                'type' => $event->type,
                'email' => $event->email,
                'link' => $event->link,
                'at' => $event->occurred_at?->toIso8601String(),
            ])
            ->all();

        return [
            'recipients' => $campaign->recipient_count,
            'delivered' => $delivered,
            'opened' => $opened,
            'clicked' => $clicked,
            'bounced' => $bounced,
            'complained' => $complained,
            'unsubscribed' => $unsubscribed,
            'open_rate' => $rate($opened),
            'click_rate' => $rate($clicked),
            'opens_total' => $total('opened'),
            'clicks_total' => $total('clicked'),
            'links' => $links,
            'activity' => $activity,
        ];
    }
}
