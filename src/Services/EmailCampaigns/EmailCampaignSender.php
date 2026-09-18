<?php

namespace Visnsstudio\VisnsPackages\Services\EmailCampaigns;

use Carbon\Carbon;
use Illuminate\Support\Facades\Log;
use Visnsstudio\VisnsPackages\Models\EmailCampaign;
use Visnsstudio\VisnsPackages\Support\ModuleConfig;

/**
 * Sending, scheduling, cancelling and testing a campaign: the only writer of a
 * campaign's status, broadcast id and send stamps.
 *
 * TWO RESEND CALLS, CREATE THEN SEND. The broadcast is created as a draft and
 * then sent (now, or at `scheduled_at`), so a refused send leaves a draft in
 * Resend and a campaign here that says why, rather than a half-sent mailshot
 * nobody can see.
 *
 * THE HTML IS SNAPSHOTTED AT SEND. `html` is what went out, and later changes
 * to the renderer or the brand must not rewrite the record of it.
 */
class EmailCampaignSender
{
    public function __construct(
        private ResendClient $resend,
        private EmailCampaignRenderer $renderer,
    ) {
    }

    /**
     * Every reason this campaign cannot go yet, all at once: naming one, then
     * another on the next press, is how somebody fixes the subject and is then
     * told about the list.
     *
     * @return array<int, string>
     */
    public function blockers(EmailCampaign $campaign): array
    {
        $blockers = [];

        if (! $this->resend->configured()) {
            $blockers[] = 'Email campaigns are not connected to Resend yet (RESEND_KEY).';
        }

        if (trim((string) $campaign->subject) === '') {
            $blockers[] = 'Give it a subject line.';
        }

        if (! filter_var($this->fromEmail($campaign), FILTER_VALIDATE_EMAIL)) {
            $blockers[] = 'Say who it is from: a sender address on a domain verified in Resend.';
        }

        if (! $this->hasContent($campaign)) {
            $blockers[] = 'Add some content: at least a heading or a paragraph.';
        }

        $list = $campaign->list;

        if ($list === null || $list->trashed()) {
            $blockers[] = 'Choose a list to send it to.';
        } else {
            if ($list->activeMembers()->count() === 0) {
                $blockers[] = 'The list "' . $list->name . '" has nobody on it who can be emailed.';
            }

            if ($list->isSyncing()) {
                $blockers[] = 'The list "' . $list->name . '" is still being copied to Resend. It carries on in the background; try again in a minute or two.';
            }
        }

        return $blockers;
    }

    /**
     * Send now, or at `$when`.
     *
     * @return array{ok: bool, message: string, blockers?: array<int, string>}
     */
    public function send(EmailCampaign $campaign, ?Carbon $when = null): array
    {
        if (! $campaign->isEditable()) {
            return ['ok' => false, 'message' => 'This campaign has already been ' . $campaign->status . '.'];
        }

        if ($when !== null && $when->lte(now()->addMinutes(2))) {
            return ['ok' => false, 'message' => 'Choose a time at least a few minutes from now, or send it now.'];
        }

        $blockers = $this->blockers($campaign);

        if ($blockers !== []) {
            return ['ok' => false, 'message' => 'This campaign cannot be sent yet.', 'blockers' => $blockers];
        }

        $blocks = (array) $campaign->content;
        $meta = ['subject' => $campaign->subject, 'preview_text' => $campaign->preview_text];
        $html = $this->renderer->render($blocks, $meta);
        $text = $this->renderer->text($blocks);

        $created = $this->resend->createBroadcast(array_filter([
            'segment_id' => $campaign->list->resend_segment_id,
            'from' => $this->from($campaign),
            'subject' => $campaign->subject,
            'reply_to' => $this->replyTo($campaign),
            'html' => $html,
            'text' => $text,
            'name' => mb_substr($campaign->name, 0, 191),
        ], fn ($value) => $value !== null && $value !== ''));

        if (! $created->ok || empty($created->data['id'])) {
            return $this->failed($campaign, 'Resend did not accept the campaign: ' . ($created->error ?? 'no broadcast id came back.'));
        }

        $broadcastId = (string) $created->data['id'];
        $sent = $this->resend->sendBroadcast($broadcastId, $when?->toIso8601String());

        if (! $sent->ok) {
            $campaign->forceFill(['resend_broadcast_id' => $broadcastId])->save();

            return $this->failed($campaign, 'Resend did not send it: ' . $sent->error);
        }

        $campaign->forceFill([
            'html' => $html,
            'resend_broadcast_id' => $broadcastId,
            'status' => $when ? EmailCampaign::STATUS_SCHEDULED : EmailCampaign::STATUS_SENDING,
            'scheduled_at' => $when,
            'sent_at' => $when ? null : now(),
            'recipient_count' => $campaign->list->activeMembers()->count(),
            'error' => null,
        ])->save();

        Log::info('email_campaigns.sent', [
            'campaign_id' => $campaign->id,
            'broadcast_id' => $broadcastId,
            'scheduled_at' => $when?->toIso8601String(),
            'recipients' => $campaign->recipient_count,
            'user_id' => auth()->id(),
        ]);

        return [
            'ok' => true,
            'message' => $when
                ? 'Scheduled for ' . $when->copy()->timezone((string) config('app.timezone'))->format('j M Y, g:ia') . ' to ' . $campaign->recipient_count . ' ' . ($campaign->recipient_count === 1 ? 'person' : 'people') . '.'
                : 'Sending to ' . $campaign->recipient_count . ' ' . ($campaign->recipient_count === 1 ? 'person' : 'people') . ' now.',
        ];
    }

    /** @return array{ok: bool, message: string} */
    public function cancel(EmailCampaign $campaign): array
    {
        if ($campaign->status !== EmailCampaign::STATUS_SCHEDULED || ! $campaign->resend_broadcast_id) {
            return ['ok' => false, 'message' => 'Only a scheduled campaign can be cancelled.'];
        }

        $result = $this->resend->cancelBroadcast($campaign->resend_broadcast_id);

        if (! $result->ok) {
            return ['ok' => false, 'message' => 'Resend did not cancel it: ' . $result->error];
        }

        // Back to editable. A new send makes a new broadcast; the cancelled one
        // stays in Resend's history.
        $campaign->forceFill([
            'status' => EmailCampaign::STATUS_CANCELLED,
            'resend_broadcast_id' => null,
            'scheduled_at' => null,
        ])->save();

        return ['ok' => true, 'message' => 'Cancelled. Nothing was sent, and it can be edited and sent again.'];
    }

    /**
     * One copy to one address, with the merge tags filled from `$sample`.
     *
     * @return array{ok: bool, message: string}
     */
    public function test(EmailCampaign $campaign, string $to, array $sample): array
    {
        if (! filter_var($this->fromEmail($campaign), FILTER_VALIDATE_EMAIL)) {
            return ['ok' => false, 'message' => 'Set who it is from before sending a test.'];
        }

        $blocks = (array) $campaign->content;
        $result = $this->resend->sendEmail([
            'from' => $this->from($campaign),
            'to' => [$to],
            'subject' => '[Test] ' . ($campaign->subject ?: $campaign->name),
            'reply_to' => $this->replyTo($campaign),
            'html' => $this->renderer->render($blocks, ['subject' => $campaign->subject, 'preview_text' => $campaign->preview_text], $sample),
            'text' => $this->renderer->text($blocks, $sample),
        ]);

        return $result->ok
            ? ['ok' => true, 'message' => 'A test copy is on its way to ' . $to . '.']
            : ['ok' => false, 'message' => 'The test could not be sent: ' . $result->error];
    }

    public function fromEmail(EmailCampaign $campaign): string
    {
        return trim((string) ($campaign->from_email ?: ModuleConfig::get('email_campaigns.from_email')));
    }

    private function from(EmailCampaign $campaign): string
    {
        $name = trim((string) ($campaign->from_name ?: ModuleConfig::get('email_campaigns.from_name')));
        $name = str_replace(['"', '<', '>'], '', $name);

        return $name !== '' ? $name . ' <' . $this->fromEmail($campaign) . '>' : $this->fromEmail($campaign);
    }

    private function replyTo(EmailCampaign $campaign): ?string
    {
        $reply = trim((string) ($campaign->reply_to ?: ModuleConfig::get('email_campaigns.reply_to')));

        return filter_var($reply, FILTER_VALIDATE_EMAIL) ? $reply : null;
    }

    private function hasContent(EmailCampaign $campaign): bool
    {
        foreach ((array) $campaign->content as $block) {
            $type = $block['type'] ?? null;

            if (($type === 'heading' && trim((string) ($block['text'] ?? '')) !== '')
                || ($type === 'text' && trim(strip_tags((string) ($block['html'] ?? ''))) !== '')
                || ($type === 'columns' && trim(strip_tags((string) ($block['left'] ?? '') . ($block['right'] ?? ''))) !== '')) {
                return true;
            }
        }

        return false;
    }

    /** @return array{ok: bool, message: string} */
    private function failed(EmailCampaign $campaign, string $message): array
    {
        $campaign->forceFill(['status' => EmailCampaign::STATUS_FAILED, 'error' => mb_substr($message, 0, 500)])->save();

        Log::warning('email_campaigns.send_failed', ['campaign_id' => $campaign->id, 'error' => $message]);

        return ['ok' => false, 'message' => $message];
    }
}
