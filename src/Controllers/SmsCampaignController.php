<?php

namespace Visnsstudio\VisnsPackages\Controllers;

use Illuminate\Http\Request;
use Visnsstudio\VisnsPackages\Models\SmsCampaign;
use Visnsstudio\VisnsPackages\Models\SmsCampaignRecipient;
use Visnsstudio\VisnsPackages\Models\SmsLine;
use Visnsstudio\VisnsPackages\Services\Sms\SmsCampaignRenderer;
use Visnsstudio\VisnsPackages\Services\Sms\SmsOptOuts;
use Visnsstudio\VisnsPackages\Support\ModuleConfig;
use Visnsstudio\VisnsPackages\Support\PhoneNumber;
use Visnsstudio\VisnsPackages\Support\SmsPayload;

/**
 * Bulk campaigns: import a list, write one message, watch it go out.
 *
 * Registered ONLY when `messaging.bulk.enabled` is true, and gated in the route
 * on `messaging.bulk.permission` (falling back to the manage permission). An
 * application that leaves the sub-module off has none of these endpoints and
 * none of its tables read.
 *
 * Four rules run through every method here.
 *
 * **Nothing is written until the whole list has been judged.** The create
 * endpoint parses, normalises, de-duplicates and checks the opt-out register
 * for every row BEFORE it inserts anything, and returns a report naming every
 * row it refused. A partial import is the worst available outcome: somebody
 * presses start on a campaign they believe is 400 people and it is 340, and
 * they find out from the ones who complain they were missed.
 *
 * **A campaign is created as a draft and nothing sends itself.** Creating and
 * starting are separate presses, because the gap between them is where the
 * preview is read.
 *
 * **Illegal transitions are refused with a sentence, never ignored.** A
 * campaign that answered 200 to "start" and did not start would be the worst
 * possible behaviour on a screen whose entire job is to say what is happening.
 *
 * **The opt-out register is consulted twice.** Here, so somebody is told before
 * they press start; and again in the sender, per recipient, which is the rule -
 * an opt-out that arrives while a campaign is half sent has to be honoured for
 * the other half.
 */
class SmsCampaignController extends \App\Http\Controllers\Controller
{
    /** Recipients per page on the drill-down. */
    private const RECIPIENTS_PER_PAGE = 50;

    /** How many rendered messages the preview endpoint returns. */
    private const PREVIEW_LIMIT = 3;

    public function __construct(
        private SmsOptOuts $optOuts,
        private SmsCampaignRenderer $renderer
    ) {
    }

    /**
     * Every campaign, newest first, plus the settings a composer needs.
     *
     * The settings block rides the list rather than living on a second endpoint
     * because the screen cannot draw a character counter without it, and a
     * second request for four integers is a second thing to get wrong.
     *
     * `max_body_length` is the MODULE's cap, the same number every other
     * messaging screen uses. The cap on a campaign body is that minus the
     * footer and the newline before it, which the client can compute from
     * `footer` and `footer_required` in the same block - and which this
     * controller enforces regardless.
     */
    public function index(Request $request)
    {
        $campaigns = SmsCampaign::query()
            ->with(['line', 'user'])
            ->orderByDesc('id')
            ->get();

        return response()->json([
            'campaigns' => $campaigns
                ->map(fn (SmsCampaign $campaign) => SmsPayload::campaign($campaign))
                ->values(),
            'settings' => [
                'per_minute' => $this->perMinute(),
                'max_recipients' => $this->maxRecipients(),
                'footer' => $this->footer(),
                'footer_required' => (bool) ModuleConfig::get('messaging.bulk.footer_required', true),
                'max_body_length' => $this->maxBodyLength(),
            ],
        ]);
    }

    /**
     * Create a campaign from an imported list.
     *
     * The response's `report` is the whole point of the endpoint, and it is
     * returned on SUCCESS rather than being an error shape: a list of four
     * hundred rows out of a spreadsheet will contain a landline, a blank and a
     * client who unsubscribed last month, and none of those should stop the
     * other 397 - but every one of them has to be named, or somebody quietly
     * texts 397 people believing they texted 400.
     */
    public function store(Request $request)
    {
        $data = $request->validate([
            'line_id' => ['required', 'integer'],
            'name' => ['required', 'string', 'max:191'],
            'body' => ['required', 'string', 'max:' . $this->maxCampaignBodyLength()],
            'recipients' => ['required', 'array', 'min:1'],
            'recipients.*.name' => ['nullable', 'string', 'max:191'],
            'recipients.*.number' => ['required', 'string', 'max:32'],
            'recipients.*.extra' => ['nullable', 'array'],
        ]);

        $line = SmsLine::query()->find((int) $data['line_id']);

        if ($line === null) {
            return $this->refuse('line_id', 'That line no longer exists.');
        }

        if (! (bool) $line->active) {
            // Refused rather than quietly sent from somewhere else. An inactive
            // line is a number that has been handed back; a campaign going out
            // from it would reach people from a number that answers nobody.
            return $this->refuse('line_id', 'That line is not active, so nothing can be sent from it.');
        }

        $sorted = $this->sortRecipients($this->recipientRows($request));

        if ($sorted['accepted'] === []) {
            return $this->refuse(
                'recipients',
                'None of those rows can be texted — every number was unreadable, a duplicate, or already opted out.'
            );
        }

        $cap = $this->maxRecipients();

        if (count($sorted['accepted']) > $cap) {
            return $this->refuse('recipients', sprintf(
                'That list has %d usable numbers on it and a campaign takes at most %d. Split it.',
                count($sorted['accepted']),
                $cap
            ));
        }

        $campaign = SmsCampaign::create([
            'line_id' => $line->id,
            'user_id' => $request->user()?->id,
            'name' => $data['name'],
            'body' => $data['body'],
            // Snapshotted, so a config change next month cannot rewrite what
            // this campaign said it would append.
            'footer' => $this->footer(),
            'status' => SmsCampaign::STATUS_DRAFT,
            'total' => count($sorted['accepted']),
        ]);

        $this->insertRecipients($campaign, $sorted['accepted']);

        return response()->json([
            'campaign' => SmsPayload::campaign($campaign->fresh(['line', 'user'])),
            'report' => [
                'accepted' => count($sorted['accepted']),
                'invalid' => $sorted['invalid'],
                'duplicates' => $sorted['duplicates'],
                'opted_out' => $sorted['opted_out'],
            ],
        ], 201);
    }

    /**
     * The first few messages, rendered exactly as the sender will render them.
     *
     * Takes a body and rows rather than a campaign id, because it is called
     * while somebody is still typing - which is the only moment a preview is
     * worth anything. It goes through the SAME renderer the sender uses, so a
     * preview that looks right is a promise rather than a rehearsal.
     *
     * The footer comes from config here, exactly as create will snapshot it a
     * moment later, so the preview and the campaign about to be created agree.
     */
    public function preview(Request $request)
    {
        $data = $request->validate([
            'body' => ['required', 'string', 'max:' . $this->maxCampaignBodyLength()],
            'recipients' => ['required', 'array', 'min:1'],
            'recipients.*.name' => ['nullable', 'string', 'max:191'],
            'recipients.*.number' => ['required', 'string', 'max:32'],
            'recipients.*.extra' => ['nullable', 'array'],
        ]);

        return response()->json([
            'previews' => $this->renderer->preview(
                (string) $data['body'],
                $this->footer(),
                $this->recipientRows($request),
                self::PREVIEW_LIMIT
            ),
        ]);
    }

    public function show(Request $request, $id)
    {
        return response()->json([
            'campaign' => SmsPayload::campaign($this->campaign($id)),
        ]);
    }

    /**
     * A page of one campaign's recipients, optionally one status at a time.
     *
     * Paginated where the campaign list is not, because this is the list
     * somebody works through: "show me the 12 that failed" is the question, and
     * it is answered by `?status=failed` rather than by scrolling four hundred
     * rows looking for red ones.
     */
    public function recipients(Request $request, $id)
    {
        $campaign = $this->campaign($id);

        $query = $campaign->recipients()->orderBy('id');

        $status = trim((string) $request->input('status', ''));

        if ($status !== '') {
            // An unknown status matches nothing, which is the right answer for
            // a filter: "no such status" and "none in that status" read the
            // same on screen, and a 422 here would break a bookmarked link the
            // day a status was renamed.
            $query->where('status', $status);
        }

        $page = $query->paginate(self::RECIPIENTS_PER_PAGE);

        return response()->json([
            'recipients' => $page
                ->getCollection()
                ->map(fn (SmsCampaignRecipient $r) => SmsPayload::campaignRecipient($r))
                ->values(),
            'meta' => [
                'total' => $page->total(),
                'per_page' => $page->perPage(),
                'current_page' => $page->currentPage(),
                'last_page' => $page->lastPage(),
            ],
        ]);
    }

    /**
     * Start, or resume.
     *
     * `started_at` is stamped ONCE. A resume after a pause keeps the original,
     * because "when did this campaign begin" has one answer and a screen showing
     * the time somebody pressed resume would be answering a question nobody
     * asked.
     */
    public function start(Request $request, $id)
    {
        $campaign = $this->campaign($id);

        if (! in_array($campaign->status, [SmsCampaign::STATUS_DRAFT, SmsCampaign::STATUS_PAUSED], true)) {
            return $this->refuseTransition($campaign, 'started');
        }

        $campaign->forceFill([
            'status' => SmsCampaign::STATUS_SENDING,
            'started_at' => $campaign->started_at ?? now(),
            // Cleared: whatever stopped it last time is being overruled by
            // somebody who has looked at it, and leaving the sentence up would
            // make a running campaign read as a broken one.
            'last_error' => null,
        ])->save();

        return response()->json(['campaign' => SmsPayload::campaign($campaign->fresh(['line', 'user']))]);
    }

    /**
     * Stop sending, keep everything.
     *
     * Nothing is in flight - the sender does one recipient at a time and holds a
     * lock for the length of a run - so pausing is exact: the message being sent
     * at this instant finishes, and the next one never starts.
     */
    public function pause(Request $request, $id)
    {
        $campaign = $this->campaign($id);

        if ($campaign->status !== SmsCampaign::STATUS_SENDING) {
            return $this->refuseTransition($campaign, 'paused');
        }

        $campaign->forceFill(['status' => SmsCampaign::STATUS_PAUSED])->save();

        return response()->json(['campaign' => SmsPayload::campaign($campaign->fresh(['line', 'user']))]);
    }

    /**
     * Abandon it.
     *
     * The pending recipients become `skipped` rather than being deleted, so the
     * campaign still says who was on the list and what happened to each of them.
     * "We cancelled before these 180 went out" is a fact somebody will need, and
     * a row that vanished cannot state it.
     */
    public function cancel(Request $request, $id)
    {
        $campaign = $this->campaign($id);

        if (! $campaign->isActive()) {
            return $this->refuseTransition($campaign, 'cancelled');
        }

        $campaign->recipients()
            ->where('status', SmsCampaignRecipient::STATUS_PENDING)
            ->update([
                'status' => SmsCampaignRecipient::STATUS_SKIPPED,
                'error' => SmsCampaignRecipient::ERROR_CANCELLED,
                'updated_at' => now(),
            ]);

        $campaign->forceFill([
            'status' => SmsCampaign::STATUS_CANCELLED,
            'completed_at' => now(),
        ])->save();

        $campaign->syncCounts();

        return response()->json(['campaign' => SmsPayload::campaign($campaign->fresh(['line', 'user']))]);
    }

    /**
     * Put the failures back in the queue.
     *
     * `retries` is reset, because a retry budget is spent against one episode of
     * trouble and this is a person deciding the trouble is over. A COMPLETED
     * campaign goes back to `sending`: there is now something pending on it
     * again, and a campaign that held pending rows while claiming to be finished
     * would simply never be picked up.
     *
     * Deliberately does NOT touch `skipped` rows. Those are the opted-out and
     * the cancelled, and neither is a failure to retry - re-queueing an opted-out
     * number because somebody pressed "retry failed" is exactly the accident the
     * register exists to prevent.
     */
    public function retryFailed(Request $request, $id)
    {
        $campaign = $this->campaign($id);

        $requeued = (int) $campaign->recipients()
            ->where('status', SmsCampaignRecipient::STATUS_FAILED)
            ->update([
                'status' => SmsCampaignRecipient::STATUS_PENDING,
                'error' => null,
                'retries' => 0,
                'updated_at' => now(),
            ]);

        if ($requeued > 0 && $campaign->status === SmsCampaign::STATUS_COMPLETED) {
            $campaign->forceFill([
                'status' => SmsCampaign::STATUS_SENDING,
                'completed_at' => null,
                'last_error' => null,
            ])->save();
        }

        $campaign->syncCounts();

        return response()->json([
            'campaign' => SmsPayload::campaign($campaign->fresh(['line', 'user'])),
            // Said out loud, because "retry failed" on a campaign with nothing
            // failed is a press that correctly does nothing, and silence there
            // reads as a broken button.
            'requeued' => $requeued,
        ]);
    }

    /**
     * Delete a draft.
     *
     * DRAFTS ONLY. Once a campaign has started, its recipients are the record of
     * who the practice texted and when - a client communication, which nothing
     * in this module deletes. Cancel is what stops one; delete is for a list
     * that was imported wrongly and never sent.
     */
    public function destroy(Request $request, $id)
    {
        $campaign = $this->campaign($id);

        if ($campaign->status !== SmsCampaign::STATUS_DRAFT) {
            return $this->refuse(
                'status',
                'Only a draft can be deleted. Cancel this campaign instead — what it has already sent is a record.'
            );
        }

        // The recipients go too - a draft has sent nothing, so there is nothing
        // to keep. Deleted explicitly rather than left to the cascade: the
        // migration only adds that foreign key when the campaigns table is
        // there to point at, and SQLite cannot add one to an existing table at
        // all. A cascade that exists on one database and not another is not a
        // rule, it is a coincidence.
        $campaign->recipients()->delete();

        $campaign->delete();

        return response()->noContent();
    }

    /* ---------------------------------------------------------------------
     | Internals
     | ------------------------------------------------------------------- */

    /**
     * The imported rows, IN THE ORDER THEY WERE SENT.
     *
     * Read off the request rather than out of `validate()`'s return value, and
     * that is not a style preference. Laravel reconstructs the validated array
     * rule by rule, so a row missing an OPTIONAL key - here, a bare number with
     * no name, which is most of a pasted column - is appended after the rows
     * that have one. The list comes back re-ordered:
     *
     *     [Cleo, Solo, <no name>, Fourth]  ->  [Cleo, Solo, Fourth, <no name>]
     *
     * Which would make the preview show the wrong three messages and, far
     * worse, make the report's `row` numbers point at the wrong lines of
     * somebody's spreadsheet - the one thing that report exists to get right.
     *
     * The validation still runs and still refuses; only the ORDER comes from
     * here. Anything that is not an array is dropped, though the rules would
     * already have refused it.
     *
     * @return array<int, array<string, mixed>>
     */
    private function recipientRows(Request $request): array
    {
        return array_values(array_filter(
            (array) $request->input('recipients', []),
            fn ($row) => is_array($row)
        ));
    }

    /**
     * Judge every imported row, and write nothing.
     *
     * Returns the four halves of the report. The order of the tests is the rule
     * and is worth stating: unreadable, then not-a-mobile, then duplicate, then
     * opted out. A number that cannot be read is not also a duplicate, and
     * calling it one would send somebody looking for the other copy.
     *
     * The opt-out check is ONE query for the whole list, after the numbers have
     * been normalised - four hundred existence checks would make a big import
     * feel broken.
     *
     * @param  array<int, array<string, mixed>>  $rows
     * @return array{
     *     accepted: array<int, array<string, mixed>>,
     *     invalid: array<int, array<string, mixed>>,
     *     duplicates: int,
     *     opted_out: array<int, array<string, mixed>>
     * }
     */
    private function sortRecipients(array $rows): array
    {
        $candidates = [];
        $invalid = [];
        $duplicates = 0;
        $seen = [];

        foreach (array_values($rows) as $index => $row) {
            // 1-based, so it names the row a person is looking at in their
            // spreadsheet rather than an array offset.
            $number = $index + 1;

            $name = isset($row['name']) && trim((string) $row['name']) !== ''
                ? trim((string) $row['name'])
                : null;

            $typed = trim((string) ($row['number'] ?? ''));

            $e164 = $this->optOuts->normalise($typed);

            if ($e164 === null) {
                $invalid[] = $this->invalidRow($number, $name, $typed, 'could not be read');

                continue;
            }

            if (! PhoneNumber::isMobile($e164)) {
                // A landline parses perfectly and is still the wrong number:
                // the message is billed, recorded against the client, and never
                // read.
                $invalid[] = $this->invalidRow($number, $name, $typed, 'not a mobile number');

                continue;
            }

            if (isset($seen[$e164])) {
                $duplicates++;

                continue;
            }

            $seen[$e164] = true;

            $candidates[] = [
                'name' => $name,
                'number' => $e164,
                'extra' => isset($row['extra']) && is_array($row['extra']) ? $row['extra'] : null,
            ];
        }

        $optedOut = $this->optOuts->optedOutAmong(array_column($candidates, 'number'));

        $accepted = [];
        $refused = [];

        foreach ($candidates as $candidate) {
            if (isset($optedOut[$candidate['number']])) {
                // NOT stored as a recipient at all. A skipped row on the list
                // would look, on the campaign screen, exactly like somebody the
                // practice intended to text - and the whole point is that it did
                // not.
                $refused[] = ['name' => $candidate['name'], 'number' => $candidate['number']];

                continue;
            }

            $accepted[] = $candidate;
        }

        return [
            'accepted' => $accepted,
            'invalid' => $invalid,
            'duplicates' => $duplicates,
            'opted_out' => $refused,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function invalidRow(int $row, ?string $name, string $number, string $reason): array
    {
        return [
            'row' => $row,
            'name' => $name,
            // The number AS TYPED, not normalised - it could not be normalised,
            // and echoing a cleaned-up version of something we refused to read
            // would make it impossible to find in the spreadsheet.
            'number' => $number,
            'reason' => $reason,
        ];
    }

    /**
     * Write the accepted rows.
     *
     * Chunked `insert()` rather than a create() each: five hundred models is
     * five hundred round trips and five hundred sets of events for rows nothing
     * observes. The timestamps are set by hand because a bulk insert does not
     * get them, and a recipient with no `created_at` would sort unpredictably on
     * every screen that shows one.
     *
     * @param  array<int, array<string, mixed>>  $accepted
     */
    private function insertRecipients(SmsCampaign $campaign, array $accepted): void
    {
        $now = now();

        $rows = array_map(fn (array $candidate) => [
            'campaign_id' => $campaign->id,
            'name' => $candidate['name'],
            'number' => $candidate['number'],
            'extra' => $candidate['extra'] === null ? null : json_encode($candidate['extra']),
            'status' => SmsCampaignRecipient::STATUS_PENDING,
            'error' => null,
            'retries' => 0,
            'message_id' => null,
            'thread_id' => null,
            'sent_at' => null,
            'created_at' => $now,
            'updated_at' => $now,
        ], $accepted);

        foreach (array_chunk($rows, 100) as $chunk) {
            SmsCampaignRecipient::query()->insert($chunk);
        }
    }

    /**
     * A campaign, or a 404.
     */
    private function campaign($id): SmsCampaign
    {
        $campaign = SmsCampaign::query()->with(['line', 'user'])->find($id);

        if ($campaign === null) {
            abort(404, 'Not found.');
        }

        return $campaign;
    }

    /**
     * The house refusal shape: 422, one sentence, in both `message` and
     * `errors` so a form and a toast each find it where they look.
     */
    private function refuse(string $field, string $message)
    {
        return response()->json([
            'message' => $message,
            'errors' => [$field => [$message]],
        ], 422);
    }

    /**
     * A transition that does not exist, named in terms of what the campaign IS
     * rather than of what was refused - "a completed campaign cannot be started"
     * tells somebody what to do next, and "invalid transition" does not.
     */
    private function refuseTransition(SmsCampaign $campaign, string $verb)
    {
        return $this->refuse('status', sprintf(
            'A %s campaign cannot be %s.',
            $campaign->status,
            $verb
        ));
    }

    private function footer(): string
    {
        $footer = ModuleConfig::get('messaging.bulk.footer', '');

        return is_string($footer) ? trim($footer) : '';
    }

    private function perMinute(): int
    {
        $value = (int) ModuleConfig::get('messaging.bulk.per_minute', 30);

        return $value > 0 ? $value : 1;
    }

    private function maxRecipients(): int
    {
        $value = (int) ModuleConfig::get('messaging.bulk.max_recipients', 500);

        return $value > 0 ? $value : 500;
    }

    private function maxBodyLength(): int
    {
        $max = (int) ModuleConfig::get('messaging.max_body_length', 1600);

        return $max > 0 ? $max : 1600;
    }

    /**
     * The cap on a campaign body: the module's, less whatever the server is
     * going to append.
     *
     * Enforced rather than advisory. Without it a body typed right up to the
     * module's limit would be over it the moment the footer went on, and the
     * refusal would arrive from the transport, one recipient at a time, after
     * the campaign had started.
     */
    private function maxCampaignBodyLength(): int
    {
        $max = $this->maxBodyLength();

        $footer = $this->footer();

        if ($footer === '' || ! (bool) ModuleConfig::get('messaging.bulk.footer_required', true)) {
            return $max;
        }

        // +1 for the newline the renderer puts between them.
        return max(1, $max - (mb_strlen($footer) + 1));
    }
}
