# Changelog

Notable changes to `visnsstudio/visns-packages`.

Entries before 4.15.0 were not kept in a file; the git log and the README's
per-module sections are the record for those.

## 4.15.3

### Fixed — Call queue: the pop had no Pick up button, because Zoom never sent the queue's id

Product owner, after the first successful queue pop on production: *"can we look
into picking up the call with the call pop"*. The Test Dev queue was configured —
`queue_id = "-pi2RsyBTTmgvi128QR9Bg"`, `pickup_code = 3288` — and the card came up
with no button on it.

**Zoom's `phone.callee_ringing` for a queue-distributed leg does not identify the
queue.** The whole of the `forwarded_by` node, verified on production:

```json
{ "name": "Test Dev Call Queue", "extension_type": "callQueue", "extension_number": "805" }
```

No `id`, no `extension_id`. `ZoomWebhookController::resolveQueue()` read only
those two, so the live row stored `queue_id` null with the name resolved,
`ZoomLiveQueueCall::present()` had nothing to key the pickup-code map with, and
the card had no code to offer. `isExcludedQueue()` was blind for exactly the same
reason, so **a queue the operator had opted out of popped anyway** — the same
fault, quieter.

The queue LISTING carries the id, the name and the extension number together, so
the bridge is built where they are all in our hands:

- **`zoom_call_queue_settings.extension_number`** (string 16, nullable, indexed) —
  migration `2026_09_16_100000_add_extension_number_to_zoom_call_queue_settings_table`.
  Written by the settings page from Zoom's own listing, on the existing rows only
  (a queue nobody has configured has nothing to look up), and published on the
  settings payload — including from the stored copy when Zoom is unreachable,
  which is the one day that column has nothing else to show.
- **`ZoomCallQueueSetting::idsByExtensionAndName()`** — both maps behind a
  ten-minute cache (`call_queue.queue_id_cache_ttl`, forgotten on every settings
  save). **No Zoom API call on the webhook path**, which matters more here than
  anywhere: Zoom retries a webhook it is not answered promptly and disables the
  subscription after enough failures.
- **`resolveQueue()`** resolves an id when the node carries none — by extension
  number first, compared EXACTLY (an extension number is an identifier, and `805`
  is not `0805`), then by name, compared case-insensitively (a display name's case
  is not meaningful, and MySQL folds it while SQLite does not). The resolved id is
  the real one and feeds `queue_id`, `pickup_key` and the exclusion check alike.
  The `direct` pseudo-row is excluded from both maps: a queue genuinely called
  "Direct calls" must not inherit the account-wide direct pickup code.

A node matching neither an extension nor a name keeps today's behaviour exactly —
named, popped, no pickup key — because a queue nobody has configured has no code
to offer and one fewer button is better than no card.

Tests: `CallQueueWebhookTest` (+6 — resolution by extension, the exact-match rule,
resolution by name, neither, the exclusion, and the direct pseudo-row),
`CallQueueSettingsTest` (+4 — the stored number, the unreachable fallback, no row
for an unconfigured queue, and the cache flush that makes a newly learnt number
work on the next call rather than in ten minutes).

## 4.15.2

### Fixed — Messaging: Zoom's own STOP block, and the red bubble it drew

Production, 11 Sep 2026 15:37. A campaign recipient replied "Stop". The module
recorded the opt-out correctly and sent the configured confirmation — and Zoom
refused it with

```json
{ "code": 7037, "message": "61415033181" }
```

The `message` is the recipient's own number and nothing else, so the inbox drew
**Failed — 61415033181** with a Retry link beside it: the module doing exactly
its job, rendered as the application being broken.

**Zoom does handle STOP, and the module's note said it did not.** The one thing
it does is block: once a recipient texts STOP, Zoom refuses every further
outbound message from that Zoom number to that recipient until they text START.
There is no endpoint that lists it and nothing tells us it happened, so the
`sms_opt_outs` register is still the only thing that knows who has unsubscribed
and is still what stops bulk — Zoom's block is the backstop underneath it. The
consequence is that the confirmation we compose **in answer to** a STOP is
refused as a matter of course, on every send, for ever.

- **`Support\ZoomSmsErrors`** — a short table of Zoom Phone codes to the
  sentence each one means, consulted by `ZoomSmsClient::errorMessage()` **before**
  `data.message`. `7037` ("This number has opted out of texts from this line
  with Zoom, so nothing can be sent to it until it replies START") and `7639`
  (the identity refusal a Server-to-Server token gets for any sender but the
  account owner). An unrecognised code falls through to Zoom's own text exactly
  as before — Zoom's sentence beats a guess of ours — and the whole body is kept
  verbatim on the result's `raw` either way. The table is deliberately short: a
  register of half-remembered codes eventually contradicts the provider.
- **`SmsSendResult` gained `code` (int|null) and `optedOut` (bool)**, beside
  `retryAfter`/`rateLimited` and appended for the same reason — a transport that
  has not thought about the question keeps behaving exactly as it did.
  `optedOut` is a fact about the NUMBER, not about the request: it is the
  opposite of `retryable` (7037 arrives on a 4xx and is never retried) and is
  the one refusal a caller may want to treat as expected.
- **The STOP confirmation is the one message in the module allowed to
  disappear.** `SmsService::deliver()` — `send()`'s body, with one flag — deletes
  the row and returns null when a suppressible send comes back `optedOut`,
  **before** the thread pointer is re-stamped and before the broadcast goes out.
  Deleting it afterwards would leave every open tab holding a red bubble until
  it happened to refetch, and there is no removal event on the wire to take one
  back with. One `Log::info('sms.opt_out confirmation suppressed', …)` carries
  the code, the number and the inbound message that caused it. Any other
  refusal — a 5xx, a dead line — fails visibly as it always has, because that is
  a fault.
- **START is never suppressed**: releasing the number is what lifts Zoom's
  block, so that confirmation can be delivered and should be.
- **A staff member answering somebody who wrote in is still never blocked.** The
  inbox does not refuse a reply to an opted-out number and did not start to;
  what changed is that when Zoom refuses it, the row's `error` is a sentence
  rather than a phone number.

Tests: six added to `MessagingOptOutTest` (52 in the file, 329 in
`tests/Platform/Messaging`).

## 4.15.1

### Added — Messaging: pacing the bulk sender

Product owner: *"put in place some strategy to not cause spam with the Zoom API
and delay each send a reasonable amount of time — I assume we cannot just send
thousands of API requests at once."*

**Zoom's published rate limits are not the constraint.** Phone endpoints allow
20 requests a second (Light) or 10 (Medium) on a Pro account, and sending
anywhere near that would sit inside every published limit and still be the wrong
thing to do: Zoom applies an **unpublished per-user daily SMS cap** (community
reports put it between 20 and 100), and carriers filter on shape — a burst of
near-identical messages from one mobile number is what a spam run looks like
from the network's side, and the number gets filtered rather than the messages
refused, so there is no error anywhere to read. The strategy is therefore **to
look like a person texting**, not to use the API budget efficiently.

- `Services\Sms\SmsBulkPacing` — the one place that knows about intervals,
  cooldowns, allowances, the waiting sentence and the estimate.
- **`messaging.bulk.send_interval_ms`** (default **2000**). The sender waits
  that long between sends. A skipped recipient costs no delay — nothing reached
  Zoom. It also caps a run at `floor(50_000 / interval)` so its sends and its
  sleeps fit inside the scheduler's minute: overshooting makes
  `withoutOverlapping()` skip the next tick entirely rather than queue.
- **A per-line cooldown on a 429.** `Retry-After` is read in both RFC 9110
  forms, defaults to 60s when absent and is capped at 15 minutes; stored at
  `messaging:bulk:cooldown:{line_id}`. Later runs **skip** a campaign on a
  cooling line, counting it `retry_wait`, **without touching its recipients or
  their `retries`** — a run that never opened a socket on somebody's behalf must
  not spend their retry budget. Per LINE, so a campaign on another number
  carries on. A 5xx cools nothing: Zoom having a bad morning is not an
  instruction about our pace.
- **`messaging.bulk.per_day`** (default **100**, 0 = unlimited) — per line,
  counted across every campaign on it off the recipients' `sent_at`, in the
  application's own timezone. Reaching it **never pauses the campaign**: the
  recipients stay pending, the campaign stays `sending`, and the first run after
  local midnight carries on.
- **`waiting` on every campaign payload** — `null`, or
  `{reason: 'cooldown'|'daily_allowance', until, message}`, with the sentence
  built server-side in one method.
- **`eta`** — `{minutes, label, at}` beside the existing
  `estimated_minutes_remaining`, which now accounts for the interval and the
  allowance: a 500-recipient campaign on a 100-a-day line reads "about 4 days"
  rather than "about 20 minutes".
- The campaign list's `settings` gains `per_run`, `send_interval_ms` and
  `per_day`; the command reports the budget it was actually held to.
- `SmsSendResult` gains `retryAfter` and `rateLimited`; `ZoomSmsTransport` gains
  `lastStatus()` / `lastHeaders()` / `lastRetryAfter()`, and both Zoom client
  paths now return the response `headers` alongside `success` / `http_code` /
  `data` — an additive key, since every existing reader takes the other three by
  name.

Tests: `tests/Platform/Messaging/MessagingCampaignPacingTest.php` (25).

## 4.15.0

### Added — Messaging: opt-outs

**Always on when messaging is enabled**, because the obligation is not part of
any feature: the Spam Act 2003 requires a functional unsubscribe on a commercial
electronic message, and **Zoom performs no STOP handling for Australian
numbers** — so a client texting STOP produces a webhook payload and nothing
else.

- `sms_opt_outs` (`messaging.tables.opt_outs`): one row per number, unique on it.
  Per NUMBER rather than per line or per campaign, because that is what somebody
  who types STOP means.
- `Services\Sms\SmsOptOuts` — `normalise()`, `isOptedOut()`, `optedOutAmong()`,
  `record()`, `release()`, `keywordIn()`. The keyword rule is the whole message,
  or its first word (or first two), after trimming and stripping surrounding
  punctuation: `stop please` unsubscribes, **`Please stop sending these on a
  Sunday` does not**.
- `SmsService::recordInbound()` reads the keyword, records or releases, and sends
  the configured confirmation on the same thread. Never throws into the webhook;
  never acts on a sender ID.
- `GET` / `POST {base}/opt-outs`, `DELETE {base}/opt-outs/{id}` — manage-gated.
- `opted_out` on every thread payload. A **label**, not a gate: a staff member
  answering somebody who has written in is a conversation, and the endpoint still
  accepts it.
- `messaging.opt_out.keywords` / `opt_in_keywords` / `reply` / `opt_in_reply`.

### Added — Messaging: bulk campaigns (`messaging.bulk`, ships disabled)

Import a list, write one message with placeholders, and send it one recipient at
a time through the existing transport — each into an **ordinary thread**, so the
replies land in the inbox.

- `sms_campaigns` and `sms_campaign_recipients`, both named through
  `messaging.tables`.
- `Services\Sms\SmsCampaignSender` — a `Cache::lock`-guarded run with a budget
  per minute, driven by **`php artisan sms:send-campaigns [--budget=]`**. THE
  PACKAGE REGISTERS NO SCHEDULE; the application schedules it
  (`->everyMinute()->withoutOverlapping()`).
- `Services\Sms\SmsCampaignRenderer` — `{name}`, `{first_name}`, `{last_name}`
  and any imported column; unknown placeholders resolve to an empty string. The
  unsubscribe footer is appended by the server unless the body already contains
  it, and is snapshotted onto the campaign at create time.
- `Support\SmsSegments` — GSM-7 160/153, UCS-2 70/67, counted in UTF-16 code
  units. The GSM escape table is deliberately counted as UCS-2 (over-counting is
  the safe direction).
- `SmsCampaignController` — list, create (with a `report` naming every invalid,
  duplicate and opted-out row), preview, show, recipients, start, pause, cancel,
  retry-failed, delete-draft. Illegal transitions answer 422 with a sentence.
- `messaging.bulk.permission` — a grant of its own for the campaign endpoints,
  falling back to `messaging.permissions.manage`.
- `archive_threads`: a thread a campaign CREATES is archived until the client
  replies; an existing conversation is never archived.

### Changed

- **`SmsSendResult` carries `retryable`** (last constructor argument,
  `failed(..., bool $retryable = false)`), and `ZoomSmsTransport` sets it for a
  429, a 5xx, or a request that never completed. Default false, so every
  existing transport behaves exactly as it did. Read only by the campaign sender,
  through the new `SmsService::lastResult()`.
- **An inbound message un-archives its thread.** The other half of
  `archive_threads` — without it, archiving would be a way of losing answers.
- `SmsPayload::thread()` takes an optional pre-computed `$optedOut`, so a page of
  threads costs one query rather than fifty. `SmsController` batches it.
- `SmsPayload` gained `campaign()`, `campaignRecipient()` and `optOut()`.

### Fixed

- `SmsCampaignController` reads the imported rows from the request rather than
  from `validate()`'s return value: Laravel rebuilds that array rule by rule, so
  a row missing an optional key (a bare number with no name — most of a pasted
  column) is **appended after** the rows that have one. Reading it would have
  pointed the import report's `row` numbers at the wrong lines of somebody's
  spreadsheet.

### Upgrading

```bash
php artisan vendor:publish --tag=visns-packages-migrations
php artisan migrate
```

Nothing else changes for an application that leaves `messaging.bulk.enabled`
false: no campaign routes are registered and neither campaign table is read. The
opt-out register IS live as soon as the migration has run — and until it has, the
reads degrade to "not opted out" with a log line rather than taking the inbox
down.
