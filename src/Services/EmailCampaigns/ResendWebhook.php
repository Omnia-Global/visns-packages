<?php

namespace Visnsstudio\VisnsPackages\Services\EmailCampaigns;

use Carbon\Carbon;
use Illuminate\Database\QueryException;
use Visnsstudio\VisnsPackages\Models\EmailCampaign;
use Visnsstudio\VisnsPackages\Models\EmailCampaignEvent;
use Visnsstudio\VisnsPackages\Models\EmailListMember;
use Visnsstudio\VisnsPackages\Support\ModuleConfig;

/**
 * Resend's webhook: what happened to each email of each campaign, and who
 * unsubscribed.
 *
 * SIGNED OR NOTHING. Resend signs with Svix: HMAC-SHA256, keyed on the
 * base64 secret after `whsec_`, over `{svix-id}.{svix-timestamp}.{body}`, with
 * one or more `v1,<base64>` signatures in `svix-signature`. A timestamp more
 * than five minutes off is refused too, so a captured delivery cannot be
 * replayed later. Without a configured secret everything is refused: reports
 * built on unsigned events are reports anybody can write.
 *
 * Only events carrying a `broadcast_id` this module sent are kept; the host's
 * transactional mail goes through the same Resend team and is none of this
 * module's business. At-least-once delivery is absorbed by the unique
 * `webhook_id`.
 */
class ResendWebhook
{
    private const TOLERANCE_SECONDS = 300;

    public function verify(string $body, ?string $id, ?string $timestamp, ?string $signatures): bool
    {
        $secret = (string) ModuleConfig::get('email_campaigns.webhook_secret');

        if ($secret === '' || ! $id || ! $timestamp || ! $signatures || ! ctype_digit($timestamp)) {
            return false;
        }

        if (abs(time() - (int) $timestamp) > self::TOLERANCE_SECONDS) {
            return false;
        }

        $key = base64_decode(str_starts_with($secret, 'whsec_') ? substr($secret, 6) : $secret, true);

        if ($key === false) {
            return false;
        }

        $expected = base64_encode(hash_hmac('sha256', $id . '.' . $timestamp . '.' . $body, $key, true));

        foreach (preg_split('/\s+/', trim($signatures)) as $entry) {
            [$version, $signature] = array_pad(explode(',', $entry, 2), 2, '');

            if ($version === 'v1' && hash_equals($expected, $signature)) {
                return true;
            }
        }

        return false;
    }

    /** @return string what was done, for the log line */
    public function handle(array $payload, string $webhookId): string
    {
        $type = (string) ($payload['type'] ?? '');
        $data = (array) ($payload['data'] ?? []);

        if (str_starts_with($type, 'contact.')) {
            return $this->contact($type, $data);
        }

        if (! str_starts_with($type, 'email.')) {
            return 'ignored';
        }

        $broadcastId = (string) ($data['broadcast_id'] ?? '');

        if ($broadcastId === '') {
            return 'not_a_campaign';
        }

        $campaign = EmailCampaign::withTrashed()->where('resend_broadcast_id', $broadcastId)->first();

        if ($campaign === null) {
            return 'unknown_broadcast';
        }

        $event = substr($type, strlen('email.'));
        $email = strtolower(trim((string) (is_array($data['to'] ?? null) ? ($data['to'][0] ?? '') : ($data['to'] ?? ''))));
        $click = (array) ($data['click'] ?? []);

        try {
            EmailCampaignEvent::create([
                'campaign_id' => $campaign->id,
                'broadcast_id' => $broadcastId,
                'email_id' => mb_substr((string) ($data['email_id'] ?? ''), 0, 64) ?: null,
                'type' => mb_substr($event, 0, 32),
                'email' => $email !== '' ? mb_substr($email, 0, 191) : null,
                'link' => isset($click['link']) ? mb_substr((string) $click['link'], 0, 2000) : null,
                'ip' => isset($click['ipAddress']) ? mb_substr((string) $click['ipAddress'], 0, 45) : null,
                'user_agent' => isset($click['userAgent']) ? mb_substr((string) $click['userAgent'], 0, 500) : null,
                'occurred_at' => $this->when($payload['created_at'] ?? null),
                'webhook_id' => mb_substr($webhookId, 0, 100),
                'created_at' => now(),
            ]);
        } catch (QueryException $e) {
            // The unique webhook_id: Svix delivered this one before.
            return 'duplicate';
        }

        // The first delivered event is the moment it really went.
        if ($event === 'delivered' && $campaign->status === EmailCampaign::STATUS_SENDING) {
            $campaign->forceFill(['status' => EmailCampaign::STATUS_SENT, 'sent_at' => $campaign->sent_at ?? now()])->save();
        }

        // A spam complaint is as good as an unsubscribe; Resend suppresses them.
        if ($event === 'complained' && $email !== '') {
            $this->unsubscribe($email);
        }

        return 'stored';
    }

    private function contact(string $type, array $data): string
    {
        $email = strtolower(trim((string) ($data['email'] ?? '')));

        if ($email === '') {
            return 'ignored';
        }

        if ($type === 'contact.updated' && array_key_exists('unsubscribed', $data)) {
            if ($data['unsubscribed']) {
                $this->unsubscribe($email);

                return 'unsubscribed';
            }

            // Re-subscribed in Resend (by the person, or by staff there).
            EmailListMember::query()->where('email', $email)->update(['unsubscribed_at' => null]);

            return 'resubscribed';
        }

        return 'ignored';
    }

    private function unsubscribe(string $email): void
    {
        EmailListMember::query()
            ->where('email', $email)
            ->whereNull('unsubscribed_at')
            ->update(['unsubscribed_at' => now()]);
    }

    private function when($value): Carbon
    {
        try {
            return $value ? Carbon::parse((string) $value) : now();
        } catch (\Throwable $e) {
            return now();
        }
    }
}
