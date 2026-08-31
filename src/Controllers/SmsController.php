<?php

namespace Visnsstudio\VisnsPackages\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Visnsstudio\VisnsPackages\Models\SmsLine;
use Visnsstudio\VisnsPackages\Models\SmsMessage;
use Visnsstudio\VisnsPackages\Models\SmsThread;
use Visnsstudio\VisnsPackages\Models\SmsThreadRead;
use Visnsstudio\VisnsPackages\Services\Sms\SmsService;
use Visnsstudio\VisnsPackages\Support\ModuleConfig;
use Visnsstudio\VisnsPackages\Support\PhoneNumber;
use Visnsstudio\VisnsPackages\Support\SmsAccess;
use Visnsstudio\VisnsPackages\Support\SmsPayload;

/**
 * The messaging inbox: lines, threads, messages.
 *
 * Three rules run through every method and are worth stating once.
 *
 * **A line you are not on does not exist.** Visibility is the `sms_line_user`
 * pivot, plus the manage permission. Every miss is a 404, including the ones
 * that are really "you are not allowed" - a 403 on a thread id would answer
 * "is this client texting the practice" for anyone who cared to enumerate.
 *
 * **Unread is per user, not per thread.** A shared line is read by several
 * people; a thread one of them has opened is still new to the others. The
 * counts come from a read high-water mark per (thread, user).
 *
 * **A send always leaves a row.** Even with no transport connected, the message
 * is stored - `not_connected` - so the practice can see what it tried to send.
 * See Services\Sms\NullSmsTransport.
 */
class SmsController extends \App\Http\Controllers\Controller
{
    private const MAX_PER_PAGE = 100;
    private const DEFAULT_MESSAGE_LIMIT = 50;
    private const MAX_MESSAGE_LIMIT = 200;

    public function __construct(private SmsService $sms)
    {
    }

    /**
     * Is messaging actually wired to anything, and is there anything waiting?
     *
     * The `connected` flag is what lets the UI say plainly "not connected to
     * Zoom yet" instead of offering a send button that quietly stores drafts.
     */
    public function status(Request $request)
    {
        $user = $request->user();
        $lineIds = SmsAccess::lineIds($user);

        return response()->json([
            'transport' => $this->sms->transportName(),
            'connected' => $this->sms->connected(),
            'lines_count' => count($lineIds),
            'unread_total' => array_sum(SmsThread::unreadCountsFor($user, null, $lineIds)),
        ]);
    }

    /**
     * The lines this user may work, each with its own unread count.
     */
    public function lines(Request $request)
    {
        $user = $request->user();

        $lines = SmsLine::query()
            ->visibleTo($user, SmsAccess::manages($user))
            ->orderBy('label')
            ->get();

        $unread = SmsThread::unreadCountsFor(
            $user,
            null,
            $lines->pluck('id')->map(fn ($id) => (int) $id)->all()
        );

        $byLine = $this->unreadByLine($unread);

        return response()->json([
            'data' => $lines->map(fn (SmsLine $line) => [
                'id' => $line->id,
                'label' => $line->label,
                'phone_number' => $line->phone_number,
                'display_number' => $line->display_number,
                'active' => (bool) $line->active,
                'unread_count' => $byLine[$line->id] ?? 0,
            ])->values(),
        ]);
    }

    /**
     * The unread counts, three ways.
     *
     * Its own endpoint because the UI polls it every 30 seconds as a backstop to
     * the broadcast - a browser that missed a Pusher frame (asleep, offline,
     * behind a proxy that dropped the socket) still catches up. It has to stay
     * cheap: one grouped query, no thread bodies, no pagination.
     */
    public function unread(Request $request)
    {
        $user = $request->user();
        $lineIds = SmsAccess::lineIds($user);

        $byThread = SmsThread::unreadCountsFor($user, null, $lineIds);

        return response()->json([
            'total' => array_sum($byThread),
            'by_line' => (object) $this->unreadByLine($byThread),
            'by_thread' => (object) $byThread,
        ]);
    }

    /**
     * A page of threads.
     */
    public function threads(Request $request)
    {
        $user = $request->user();
        $manages = SmsAccess::manages($user);

        // Filtering a page after the fact would give pages of wildly different
        // sizes, so the unread view is its own query.
        if ($request->boolean('unread_only')) {
            return response()->json(
                $this->unreadOnlyPage($request, $user, $manages, $this->perPage($request))
            );
        }

        $query = SmsThread::query()
            ->visibleTo($user, $manages)
            ->search($request->input('search'));

        if ($request->filled('line_id')) {
            $lineId = (int) $request->input('line_id');

            // A line the user cannot see is not a 403 here either - it simply
            // has no threads.
            if (! $this->canSeeLine($user, $manages, $lineId)) {
                $query->whereRaw('1 = 0');
            } else {
                $query->where('line_id', $lineId);
            }
        }

        $this->applyClientFilter($query, $request);

        // Archived threads are out of the way by default; `archived=1` is the
        // archive view, and there is deliberately no "both" - a list mixing
        // them would make the archive button look like it did nothing.
        if ($request->boolean('archived')) {
            $query->whereNotNull('archived_at');
        } else {
            $query->whereNull('archived_at');
        }

        $perPage = $this->perPage($request);

        $threads = $query
            ->orderByDesc('last_message_at')
            // A stable tiebreak, so page 2 cannot repeat a row from page 1 when
            // several threads have never had a message.
            ->orderByDesc('id')
            ->paginate($perPage);

        $unread = SmsThread::unreadCountsFor(
            $user,
            $threads->pluck('id')->map(fn ($id) => (int) $id)->all()
        );

        return response()->json(
            $threads->through(fn (SmsThread $thread) => $this->threadRow($thread, $unread))
        );
    }

    /**
     * Start (or reopen) a conversation, optionally sending the first message.
     */
    public function storeThread(Request $request)
    {
        $user = $request->user();
        $manages = SmsAccess::manages($user);

        $data = $request->validate([
            'line_id' => ['required', 'integer'],
            'to' => ['required', 'string', 'max:32'],
            'body' => ['nullable', 'string', 'max:' . $this->maxBodyLength()],
        ]);

        $line = SmsLine::query()
            ->visibleTo($user, $manages)
            ->find((int) $data['line_id']);

        if ($line === null) {
            abort(404, 'Not found.');
        }

        $e164 = PhoneNumber::toE164($data['to'], $this->country());

        if ($e164 === null) {
            // 422 rather than a best guess: texting the wrong person is worse
            // than being asked to retype the number.
            return response()->json([
                'message' => 'That does not look like a phone number we can text.',
                'errors' => ['to' => ['That does not look like a phone number we can text.']],
            ], 422);
        }

        if (! PhoneNumber::isMobile($e164)) {
            // A landline parses perfectly and is still the wrong number: the
            // message would be billed, recorded against the client, and never
            // read. The wording is the compose box's own, so a person who got
            // past the field's warning is not told a second, different story.
            $landline = 'That looks like a landline; SMS needs a mobile number.';

            return response()->json([
                'message' => $landline,
                'errors' => ['to' => [$landline]],
            ], 422);
        }

        $thread = SmsThread::findOrCreateFor($line, $e164, $this->sms->clientResolver());

        $message = null;

        if (isset($data['body']) && trim((string) $data['body']) !== '') {
            $message = $this->sms->send($thread, (string) $data['body'], $user);
        }

        return response()->json([
            'thread' => SmsPayload::thread($thread->fresh(), $user),
            'message' => $message === null ? null : SmsPayload::message($message),
        ], 201);
    }

    /**
     * One thread and a page of its messages, oldest first - and the thread is
     * marked read for this user, because opening it is what reading it means.
     *
     * Paginated backwards (`before=<message id>`) rather than by page number: a
     * conversation grows at the end, so a page number would shift under the
     * reader every time a message arrived.
     */
    public function showThread(Request $request, $id)
    {
        $user = $request->user();
        $thread = $this->visibleThread($request, $id);

        $limit = (int) $request->input('limit', self::DEFAULT_MESSAGE_LIMIT);
        $limit = max(1, min($limit, self::MAX_MESSAGE_LIMIT));

        $query = $thread->messages()->with('user');

        if ($request->filled('before')) {
            $query->where('id', '<', (int) $request->input('before'));
        }

        // One more than asked for, purely to answer has_more without a count.
        $page = $query->orderByDesc('id')->limit($limit + 1)->get();

        $hasMore = $page->count() > $limit;

        $messages = $page->take($limit)->reverse()->values();

        $this->markRead($thread, $user);

        return response()->json([
            'thread' => $this->withClientDetails(SmsPayload::thread($thread->fresh(), $user)),
            'messages' => $messages->map(fn (SmsMessage $m) => SmsPayload::message($m))->values(),
            'has_more' => $hasMore,
        ]);
    }

    /**
     * Send a message on an existing thread.
     */
    public function storeMessage(Request $request, $id)
    {
        $user = $request->user();
        $thread = $this->visibleThread($request, $id);

        // A thread opened by an inbound message from a sender ID - `Apple`,
        // `ANZ`, a short code - has no handset behind it. The compose box is
        // already replaced by a notice for these (SmsPayload's `can_reply`), so
        // reaching here means a stale tab or a script; either way, saying so is
        // better than storing a row and letting Zoom reject an address it was
        // never going to accept. 422 rather than 403: nothing is forbidden, the
        // recipient simply is not one.
        if (PhoneNumber::isSenderId($thread->external_number)) {
            $refusal = $thread->external_number
                . ' is a sender ID, not a phone number — messages from it are one-way.';

            return response()->json([
                'message' => $refusal,
                'errors' => ['body' => [$refusal]],
            ], 422);
        }

        $data = $request->validate([
            'body' => ['required', 'string', 'max:' . $this->maxBodyLength()],
        ]);

        $message = $this->sms->send($thread, $data['body'], $user);

        return response()->json([
            'message' => SmsPayload::message($message->load('user')),
            'thread' => SmsPayload::thread($thread->fresh(), $user),
        ], 201);
    }

    /**
     * Mark a thread read up to its newest message.
     */
    public function read(Request $request, $id)
    {
        $user = $request->user();
        $thread = $this->visibleThread($request, $id);

        $this->markRead($thread, $user);

        return response()->noContent();
    }

    public function archive(Request $request, $id)
    {
        $thread = $this->visibleThread($request, $id);

        $thread->forceFill(['archived_at' => now()])->save();

        return response()->noContent();
    }

    public function unarchive(Request $request, $id)
    {
        $thread = $this->visibleThread($request, $id);

        $thread->forceFill(['archived_at' => null])->save();

        return response()->noContent();
    }

    /**
     * Link a thread to a client by hand, or label it.
     *
     * Needed because the automatic match is only as good as the number on the
     * client record: a spouse's phone, a new mobile, or an accountant texting on
     * a client's behalf all arrive unmatched, and an adviser knowing who it is
     * should be able to say so.
     *
     * `client_id` and `client_name` travel together: the name is a cache, so
     * setting an id without one would leave the thread list showing a number
     * where it should show a person.
     */
    public function updateThread(Request $request, $id)
    {
        $user = $request->user();
        $thread = $this->visibleThread($request, $id);

        $data = $request->validate([
            'client_id' => ['nullable', 'integer'],
            'client_name' => ['nullable', 'string', 'max:191'],
            'contact_name' => ['nullable', 'string', 'max:191'],
        ]);

        foreach (['client_id', 'client_name', 'contact_name'] as $field) {
            if (array_key_exists($field, $data)) {
                $thread->{$field} = $data[$field];
            }
        }

        // Unlinking clears the cached name too - a name with no id is a label,
        // and there is a field for that.
        if (array_key_exists('client_id', $data) && $data['client_id'] === null
            && ! array_key_exists('client_name', $data)) {
            $thread->client_name = null;
        }

        $thread->save();

        return response()->json(['thread' => SmsPayload::thread($thread->fresh(), $user)]);
    }

    /**
     * Search the application's clients, so a staff member can text somebody
     * without copying a number out of another tab.
     *
     * Proxied to the configured `messaging.client_search` hook, because this
     * package does not know what a client is. Unset means the composer simply
     * has no search - the number box still works.
     */
    public function clientSearch(Request $request)
    {
        $term = trim((string) $request->input('q', ''));

        $search = $this->sms->clientSearch();

        if ($search === null || $term === '') {
            return response()->json(['data' => []]);
        }

        try {
            $results = $search($term);
        } catch (\Throwable $e) {
            // A broken hook costs the search box, never the page.
            Log::warning('sms client search failed', [
                'error' => $e->getMessage(),
            ]);

            return response()->json(['data' => [], 'error' => 'Client search is unavailable.']);
        }

        return response()->json(['data' => is_array($results) ? array_values($results) : []]);
    }

    /**
     * Pretend a message arrived, for testing the UI with no provider.
     *
     * Manage-only, and refused outright when the Zoom transport is configured:
     * on a connected system this would write a message into a client's
     * conversation that the client never sent, and the thread is a business
     * record.
     */
    public function simulateInbound(Request $request, $id)
    {
        $user = $request->user();

        if (! SmsAccess::manages($user)) {
            abort(404, 'Not found.');
        }

        if ($this->sms->transportName() === 'zoom') {
            return response()->json([
                'message' => 'Inbound messages cannot be simulated while Zoom is connected.',
            ], 422);
        }

        $thread = $this->visibleThread($request, $id);

        $data = $request->validate([
            'body' => ['required', 'string', 'max:' . $this->maxBodyLength()],
        ]);

        $message = $this->sms->recordInbound($thread, $data['body'], [
            'raw_payload' => ['simulated' => true, 'by_user_id' => $user?->id],
        ]);

        return response()->json([
            'message' => $message === null ? null : SmsPayload::message($message),
            'thread' => SmsPayload::thread($thread->fresh(), $user),
        ], 201);
    }

    /* ---------------------------------------------------------------------
     | Internals
     | ------------------------------------------------------------------- */

    /**
     * A thread this user may work, or a 404.
     */
    private function visibleThread(Request $request, $id): SmsThread
    {
        $user = $request->user();

        $thread = SmsThread::query()
            ->visibleTo($user, SmsAccess::manages($user))
            ->find($id);

        if ($thread === null) {
            abort(404, 'Not found.');
        }

        return $thread;
    }

    /**
     * Narrow a thread list to one client.
     *
     * What makes a client page's "SMS" tab possible: the same list endpoint,
     * across every line the user can work, showing only the conversations
     * linked to that client record.
     *
     * Deliberately NOT a widening of visibility. The filter is applied on top
     * of `visibleTo`, so a conversation with this client on a line the user is
     * not on stays invisible - a client page must not become a way around the
     * line pivot.
     *
     * @param  \Illuminate\Database\Eloquent\Builder  $query
     */
    private function applyClientFilter($query, Request $request): void
    {
        if (! $request->filled('client_id')) {
            return;
        }

        // A non-numeric id casts to 0, which matches nothing - the right
        // answer for a filter, where "no such client" and "no conversations"
        // read the same on screen.
        $query->where('client_id', (int) $request->input('client_id'));
    }

    private function canSeeLine($user, bool $manages, int $lineId): bool
    {
        if ($manages) {
            return SmsLine::query()->whereKey($lineId)->exists();
        }

        return SmsLine::query()->visibleTo($user, false)->whereKey($lineId)->exists();
    }

    /**
     * Move this user's read mark to the newest message in the thread.
     */
    private function markRead(SmsThread $thread, $user): void
    {
        $userId = is_object($user) ? ($user->id ?? null) : $user;

        if ($userId === null) {
            return;
        }

        $newest = $thread->messages()->max('id');

        SmsThreadRead::markRead((int) $thread->id, $userId, $newest === null ? null : (int) $newest);
    }

    /**
     * Fatten one opened thread's `client` block with whatever the application
     * knows about that client - the first name, the surname, the next meeting.
     *
     * This is what makes a template worth having: a body reading "Hi
     * {first_name}, a reminder of your review on {date}" is only useful if the
     * composer can fill it, and the composer can only fill it from what came
     * down with the thread.
     *
     * Three deliberate limits:
     *
     *  - **Show only.** The inbox list does not call it; see
     *    SmsService::clientDetails().
     *  - **The thread wins on identity.** `id` as stored on the thread is
     *    never overwritten, and neither is `name` unless the hook returns a
     *    non-empty one - a human may have linked and named this conversation by
     *    hand, and a hook with nothing to say must not blank that.
     *  - **Best effort.** A missing hook, a hook returning nothing, or a hook
     *    that throws all leave the block exactly as the payload built it. A
     *    placeholder left unfilled is a small annoyance; a conversation that
     *    will not open is not.
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function withClientDetails(array $payload): array
    {
        $client = $payload['client'] ?? null;

        if (! is_array($client) || ($client['id'] ?? null) === null) {
            return $payload;
        }

        $hook = $this->sms->clientDetails();

        if ($hook === null) {
            return $payload;
        }

        try {
            $extra = $hook($client['id']);
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::warning('sms.client-details hook failed', [
                'client_id' => $client['id'],
                'error' => $e->getMessage(),
            ]);

            return $payload;
        }

        if (! is_array($extra) || $extra === []) {
            return $payload;
        }

        $name = $client['name'];

        $payload['client'] = array_merge($client, $extra, ['id' => $client['id']]);

        // The hook may enrich the name, never erase it.
        if (trim((string) ($extra['name'] ?? '')) === '') {
            $payload['client']['name'] = $name;
        }

        return $payload;
    }

    /**
     * @param  array<int, int>  $unread  thread id => count
     * @return array<string, mixed>
     */
    private function threadRow(SmsThread $thread, array $unread): array
    {
        $row = SmsPayload::thread($thread);

        // Overwritten rather than computed per row: the payload's own
        // unread_count would be one query each.
        $row['unread_count'] = $unread[$thread->id] ?? 0;

        return $row;
    }

    /**
     * Collapse per-thread unread counts into per-line ones.
     *
     * @param  array<int, int>  $byThread
     * @return array<int, int>
     */
    private function unreadByLine(array $byThread): array
    {
        if ($byThread === []) {
            return [];
        }

        $lines = SmsThread::query()
            ->whereIn('id', array_keys($byThread))
            ->pluck('line_id', 'id');

        $byLine = [];

        foreach ($byThread as $threadId => $count) {
            $lineId = (int) ($lines[$threadId] ?? 0);

            if ($lineId === 0) {
                continue;
            }

            $byLine[$lineId] = ($byLine[$lineId] ?? 0) + (int) $count;
        }

        return $byLine;
    }

    /**
     * The unread-only view.
     *
     * Built as its own query rather than by filtering a page, because filtering
     * after pagination gives pages of wildly different sizes - twenty rows, then
     * two, then none - which reads as a broken list.
     *
     * @return \Illuminate\Contracts\Pagination\LengthAwarePaginator
     */
    private function unreadOnlyPage(Request $request, $user, bool $manages, int $perPage)
    {
        $unread = SmsThread::unreadCountsFor($user, null, SmsAccess::lineIds($user));

        $query = SmsThread::query()
            ->visibleTo($user, $manages)
            ->search($request->input('search'))
            ->whereIn('id', array_keys($unread) ?: [0]);

        if ($request->filled('line_id')) {
            $query->where('line_id', (int) $request->input('line_id'));
        }

        $this->applyClientFilter($query, $request);

        if ($request->boolean('archived')) {
            $query->whereNotNull('archived_at');
        } else {
            $query->whereNull('archived_at');
        }

        return $query
            ->orderByDesc('last_message_at')
            ->orderByDesc('id')
            ->paginate($perPage)
            ->through(fn (SmsThread $thread) => $this->threadRow($thread, $unread));
    }

    private function perPage(Request $request): int
    {
        $default = (int) ModuleConfig::get('messaging.page_size', 50);
        $perPage = (int) $request->input('per_page', $default > 0 ? $default : 50);

        return max(1, min($perPage, self::MAX_PER_PAGE));
    }

    private function maxBodyLength(): int
    {
        $max = (int) ModuleConfig::get('messaging.max_body_length', 1600);

        return $max > 0 ? $max : 1600;
    }

    private function country(): string
    {
        return (string) ModuleConfig::get('messaging.default_country', 'AU');
    }
}
