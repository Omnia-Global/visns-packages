<?php

namespace Visnsstudio\VisnsPackages\Services\Sms;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Visnsstudio\VisnsPackages\Models\SmsCampaign;
use Visnsstudio\VisnsPackages\Models\SmsCampaignRecipient;
use Visnsstudio\VisnsPackages\Models\SmsMessage;
use Visnsstudio\VisnsPackages\Support\ModuleConfig;

/**
 * The engine behind `sms:send-campaigns`: one minute's worth of texting.
 *
 * Drip-fed rather than queued, and that is the design rather than a
 * limitation. A queue would need a worker, a failed-jobs table and a retry
 * policy the practice cannot see; this needs a scheduler line and leaves every
 * piece of its state in two tables somebody can look at. More importantly a
 * budget per minute is a CEILING ON DAMAGE: a mistake spotted two minutes in
 * has cost sixty texts rather than four hundred, and there is a Pause button
 * that works because nothing is in flight anywhere else.
 *
 * Every send goes through SmsService::sendToNumber, i.e. the same path a staff
 * member's own message takes. So each recipient gets a real thread, the reply
 * lands in the inbox beside every other conversation, and there is no second
 * definition anywhere of what sending a text means.
 *
 * ## The lock
 *
 * `Cache::lock('sms:send-campaigns', 55)`, released in a `finally`. Two runs at
 * once would both read the same pending recipient and both send to them - the
 * one failure mode of a bulk texter that a client actually notices. 55 seconds
 * is deliberately just under the scheduler's minute: a run that dies without
 * releasing the lock costs one cycle, not every cycle until somebody notices.
 * A run that cannot take the lock reports `locked` and does nothing, which is
 * the correct outcome and not an error.
 *
 * ## Stopping
 *
 * Two conditions stop a run early, and both are statements about the TRANSPORT
 * rather than about a recipient, which is why they stop everything rather than
 * moving on to the next campaign:
 *
 *  - **not_connected** - there is no provider. The campaign is paused with a
 *    sentence saying so; nothing is marked failed, because nothing was tried.
 *  - **a retryable failure** - a 429, a 5xx, a request that never completed.
 *    The recipient stays `pending`, `retries` goes up, and the run ends. Next
 *    minute tries again. Carrying on to another campaign would be making the
 *    same request to the same rate-limited account.
 *
 * Everything else is about one recipient and costs that recipient only.
 */
class SmsCampaignSender
{
    /** The lock every run of the command contends for. */
    public const LOCK = 'sms:send-campaigns';

    /** Held just under the scheduler's minute - see the class docblock. */
    public const LOCK_SECONDS = 55;

    /** What a paused-by-the-sender campaign says happened to it. */
    public const NOT_CONNECTED = 'Messaging is not connected, so nothing can be sent.';

    public function __construct(
        private SmsService $sms,
        private SmsOptOuts $optOuts,
        private SmsCampaignRenderer $renderer
    ) {
    }

    /**
     * Send up to `$budget` messages, across as many campaigns as that reaches.
     *
     * @param  int  $budget  Sends this run. A skipped recipient costs none of it:
     *                       skipping is a database write, and spending the
     *                       minute's budget on a list of opted-out numbers would
     *                       leave a campaign apparently stuck.
     * @return array{locked: bool, campaigns: int, sent: int, failed: int, skipped: int, retry_wait: int}
     */
    public function run(int $budget): array
    {
        $counts = [
            'locked' => false,
            'campaigns' => 0,
            'sent' => 0,
            'failed' => 0,
            'skipped' => 0,
            // How many recipients this run left `pending` for a later attempt
            // because the provider asked us to wait. Never more than one, since
            // the first stops the run - but a count rather than a flag, because
            // it is a number of people who have not been texted yet.
            'retry_wait' => 0,
        ];

        $lock = Cache::lock(self::LOCK, self::LOCK_SECONDS);

        if (! $lock->get()) {
            return ['locked' => true] + $counts;
        }

        try {
            $counts = $this->work(max(0, $budget), $counts);
        } finally {
            $lock->release();
        }

        return $counts;
    }

    /* ---------------------------------------------------------------------
     | Internals
     | ------------------------------------------------------------------- */

    /**
     * @param  array<string, int|bool>  $counts
     * @return array<string, int|bool>
     */
    private function work(int $budget, array $counts): array
    {
        foreach (SmsCampaign::dueQuery()->get() as $campaign) {
            if ($budget <= 0) {
                break;
            }

            $line = $campaign->line;

            if ($line === null) {
                // The line was deleted (or hard-deleted) under the campaign.
                // Paused rather than failed, and emphatically not sent from
                // whatever line the resolver would otherwise pick: a client
                // receiving this from a number they have never seen, in reply to
                // nothing, is worse than a campaign that stopped and said why.
                $this->pause($campaign, 'The line this campaign sends from no longer exists.');

                continue;
            }

            $touched = false;

            while ($budget > 0) {
                $recipient = $campaign->recipients()
                    ->where('status', SmsCampaignRecipient::STATUS_PENDING)
                    ->orderBy('id')
                    ->first();

                if ($recipient === null) {
                    break;
                }

                $outcome = $this->deliver($campaign, $recipient);

                $touched = true;

                if ($outcome === 'stop') {
                    // The transport, not this recipient. Reconcile what has
                    // happened so far and leave the rest for the next run.
                    $counts['campaigns'] += 1;
                    $counts['retry_wait'] += 1;

                    $campaign->syncCounts();

                    return $counts;
                }

                if ($outcome === 'paused') {
                    $counts['campaigns'] += 1;

                    $campaign->syncCounts();

                    return $counts;
                }

                $counts[$outcome] = (int) ($counts[$outcome] ?? 0) + 1;

                // A skip costs no budget - see the parameter's docblock.
                if ($outcome !== 'skipped') {
                    $budget--;
                }
            }

            if ($touched) {
                $counts['campaigns'] += 1;
            }

            // Recomputed from the recipients rather than trusted: the loop above
            // keeps the campaign's own copy roughly right for a screen watching
            // it, and this is the reconciliation that makes that safe.
            $campaign->syncCounts();

            $this->completeIfDone($campaign);
        }

        return $counts;
    }

    /**
     * One recipient.
     *
     * @return string  'sent' | 'failed' | 'skipped' - what happened to this
     *                 recipient - or 'stop' / 'paused', which are about the
     *                 transport and end the run.
     */
    private function deliver(SmsCampaign $campaign, SmsCampaignRecipient $recipient): string
    {
        // Checked here rather than at import time ONLY, because an opt-out that
        // arrived while the campaign was half sent has to be honoured for the
        // other half. The import check is a courtesy that tells somebody before
        // they press start; this one is the rule.
        if ($this->optOuts->isOptedOut((string) $recipient->number)) {
            $this->finish($recipient, SmsCampaignRecipient::STATUS_SKIPPED, SmsCampaignRecipient::ERROR_OPTED_OUT);

            $campaign->forceFill(['skipped' => (int) $campaign->skipped + 1])->save();

            return 'skipped';
        }

        $message = $this->sms->sendToNumber(
            (string) $recipient->number,
            $this->renderer->render($campaign, $recipient),
            $campaign->user,
            $campaign->line
        );

        if ($message === null) {
            // sendToNumber refuses rather than throws, and with the line handed
            // to it the only way back is a number it cannot read - which the
            // import should have caught, so it is worth recording plainly
            // instead of retrying.
            $this->finish(
                $recipient,
                SmsCampaignRecipient::STATUS_FAILED,
                'That number could not be read as a mobile number.'
            );

            $campaign->forceFill(['failed' => (int) $campaign->failed + 1])->save();

            return 'failed';
        }

        if ($message->status === SmsMessage::STATUS_NOT_CONNECTED) {
            // Nothing was sent and nothing is wrong with this recipient, so the
            // row stays pending and the campaign stops. Retrying against a
            // transport that cannot send is a loop that would burn every
            // recipient's retry budget in half an hour.
            $this->pause($campaign, self::NOT_CONNECTED);

            return 'paused';
        }

        $result = $this->sms->lastResult();

        if ($message->status === SmsMessage::STATUS_FAILED && $result !== null && $result->retryable) {
            return $this->waitAndRetry($campaign, $recipient, (string) ($message->error ?? 'The provider could not be reached.'));
        }

        if ($message->status === SmsMessage::STATUS_FAILED) {
            $this->finish(
                $recipient,
                SmsCampaignRecipient::STATUS_FAILED,
                (string) ($message->error ?? 'The provider refused this message.')
            );

            $campaign->forceFill([
                'failed' => (int) $campaign->failed + 1,
                'last_error' => (string) ($message->error ?? 'The provider refused this message.'),
            ])->save();

            return 'failed';
        }

        $recipient->forceFill([
            'status' => SmsCampaignRecipient::STATUS_SENT,
            'error' => null,
            'message_id' => (int) $message->id,
            'thread_id' => (int) $message->thread_id,
            'sent_at' => $message->sent_at ?? now(),
        ])->save();

        $campaign->forceFill(['sent' => (int) $campaign->sent + 1])->save();

        $this->archiveIfUntouched($message);

        return 'sent';
    }

    /**
     * A retryable failure: leave the recipient alone and stop the run.
     *
     * The retry mechanism IS the pending row. There is no queue, no delayed job
     * and nothing to lose if the process is killed between minutes - the next
     * run picks up exactly the same recipient and tries again.
     *
     * Out of retries, it becomes an ordinary failure. `max_retries` at one
     * attempt a minute is a window measured in minutes, which is longer than
     * any rate limit and shorter than a person's patience.
     */
    private function waitAndRetry(SmsCampaign $campaign, SmsCampaignRecipient $recipient, string $error): string
    {
        $retries = (int) $recipient->retries + 1;
        $max = (int) ModuleConfig::get('messaging.bulk.max_retries', 30);

        if ($max > 0 && $retries >= $max) {
            $this->finish(
                $recipient,
                SmsCampaignRecipient::STATUS_FAILED,
                $error,
                ['retries' => $retries]
            );

            $campaign->forceFill([
                'failed' => (int) $campaign->failed + 1,
                'last_error' => $error,
            ])->save();

            return 'failed';
        }

        $recipient->forceFill([
            'status' => SmsCampaignRecipient::STATUS_PENDING,
            'error' => $error,
            'retries' => $retries,
        ])->save();

        $campaign->forceFill(['last_error' => $error])->save();

        Log::info('sms.campaign send will be retried', [
            'campaign_id' => $campaign->id,
            'recipient_id' => $recipient->id,
            'retries' => $retries,
        ]);

        return 'stop';
    }

    /**
     * Archive the thread a campaign send just created, so four hundred one-way
     * texts do not bury the conversations somebody is actually having.
     *
     * ONLY a thread with no inbound message, ever. An existing conversation -
     * somebody the practice has been texting all week, who happens to be on the
     * list - is left exactly where it is; archiving it would hide a live
     * exchange as a side effect of a mail-out.
     *
     * The bargain is SmsService::recordInbound, which un-archives on a reply.
     * Without that half this would be a way of losing answers, and the two are
     * one decision written in two places.
     */
    private function archiveIfUntouched(SmsMessage $message): void
    {
        if (! (bool) ModuleConfig::get('messaging.bulk.archive_threads', true)) {
            return;
        }

        $thread = $message->thread;

        if ($thread === null || $thread->archived_at !== null) {
            return;
        }

        $hasInbound = $thread->messages()
            ->where('direction', SmsMessage::DIRECTION_IN)
            ->exists();

        if ($hasInbound) {
            return;
        }

        $thread->forceFill(['archived_at' => now()])->save();
    }

    /**
     * Move a campaign to `completed` once there is nothing pending left.
     *
     * Counted off the recipients rather than off the denormalised counters: a
     * campaign declared finished while a row was still pending would simply stop
     * being picked up, and nobody would ever be told why that person was not
     * texted.
     */
    private function completeIfDone(SmsCampaign $campaign): void
    {
        if ($campaign->status !== SmsCampaign::STATUS_SENDING) {
            return;
        }

        if ($campaign->pendingCount() > 0) {
            return;
        }

        $campaign->forceFill([
            'status' => SmsCampaign::STATUS_COMPLETED,
            'completed_at' => now(),
        ])->save();
    }

    private function pause(SmsCampaign $campaign, string $reason): void
    {
        $campaign->forceFill([
            'status' => SmsCampaign::STATUS_PAUSED,
            'last_error' => $reason,
        ])->save();

        Log::warning('sms.campaign paused', [
            'campaign_id' => $campaign->id,
            'reason' => $reason,
        ]);
    }

    /**
     * @param  array<string, mixed>  $extra
     */
    private function finish(
        SmsCampaignRecipient $recipient,
        string $status,
        ?string $error,
        array $extra = []
    ): void {
        $recipient->forceFill(array_merge([
            'status' => $status,
            'error' => $error,
        ], $extra))->save();
    }
}
