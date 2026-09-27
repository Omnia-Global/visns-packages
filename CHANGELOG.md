# Changelog

Notable changes to `visnsstudio/visns-packages`.

Entries before 4.15.0 were not kept in a file; the git log and the README's
per-module sections are the record for those.

## 4.17.3 — security

### Fixed

- **The OAuth leg of an integration needs a signed-in user who may manage
  integrations.** `integrations/oauth/{provider}/authorize` and `/callback`
  answered a guest, and the state they checked was a cache key tied to nobody,
  so anybody could run the consent leg with an account of their own and
  replace the organisation's connection. Both routes are now behind `auth`,
  every OAuth action checks `integrations_permission` through the new
  `Support\IntegrationsGate` (shared with `IntegrationsController`), and the
  state records the user who started the flow: the callback is accepted only
  from that user, once, within ten minutes.

## 4.17.2 — security

Request input reached three unsafe places in the dynamic entity controller.
Existing screens keep working; one behaviour changes (nested writes) and has a
switch.

### Fixed

- **A relation name from a request must be a real relation.** Eloquent
  resolves `whereHas`, `whereDoesntHave`, `with`/`load` and `$model->name()`
  by calling the method of that name, so a request could call any public
  zero-argument model method. The new `Support\RelationGuard` decides, without
  calling anything, whether a name is a relation: listed in the model's own
  `loadableRelations()`, `getSortableRelationshipFields()` or a new optional
  `$filterableRelations` property, or a public, parameterless, user-land method
  whose return type is a `Relation` (or, untyped, whose body returns one of
  Eloquent's relation builders). It guards the `whereHas` / `whereDoesntHave`
  filters (dropdowns included), the dotted `relation.column` filter, the merge
  endpoint's `relationships` (422 for an unknown name, before anything moves),
  nested objects on store/update, the file-relation fields on store, update
  and `updateGallery` (422), `dropdownWithGroups`' `fields`, the JSON
  sub-resource `key` / `dataKey` (`DynamicJsonController`, must be a column),
  and relationship sorting in `HasRelationshipSorting`. Unknown names are
  skipped and logged.
- **Nested writes respect `$fillable` and never write unrelated rows.** A
  nested BelongsTo carrying the related key now only sets the parent's foreign
  key (when that row exists); the looked-up row is never written. Writing
  attributes onto a related row is opt-in per entity with
  `entity_config.<entity>.nested_writable` (default none), goes through
  `fill()`, and only ever writes the row already related to the record (or a
  new one when there is none). A key that is not a relation is left to the
  ordinary fill.
- **No request text is interpolated into SQL.** The `contain_json` filter
  checks its column against the schema, allows only `[A-Za-z0-9_]` path
  segments and binds the path; `isValidColumn()` validates the base column and
  segments of a `column->path` instead of accepting any `->`; JSON sorting
  checks the column and segments and binds the path; the sort direction is
  normalised to `asc`/`desc` in `scopeCustomOrder` and in the controller; a
  relationship sort checks its column against the related table; and a dotted
  sort key that is neither a relation, a JSON column nor this table's own
  qualified column is ignored instead of reaching the database.

Tests: `tests/Platform/Security/RequestInputHardeningTest.php` (12) and
`tests/Platform/Security/RelationGuardTest.php` (5).

## 4.17.1 — security

### Fixed

- **The package `User` model no longer serialises a secret.** `$hidden` now
  carries the Microsoft sign-in tokens (`provider_token`,
  `provider_refresh_token`), the two-factor secret and recovery codes, the
  code-channel token, `otp_code` and `api_token`; before this every payload
  that serialised a user (profile, user grids, eager-loaded owners and
  assignees) carried the first two in plaintext.
- **The sign-in tokens are encrypted at rest** with `Casts\EncryptedOrPlain`,
  which still reads a token written before the cast and re-encrypts it on the
  next save. An application with existing rows can encrypt them in place; the
  omnia-global-app migration `2026_09_27_100000_encrypt_stored_oauth_tokens`
  is the worked example.
- **The same columns are kept out of the audit trail** (`$auditExclude`).

Tests: `tests/Platform/Security/UserSecretsTest.php` (2).

## 4.17.0 — security

A security review of the package's routes. Every change keeps existing screens
working; two defaults change, and both have a switch.

### Changed — defaults

- **Self-registration is off** (`auth.registration_enabled`,
  `VISNS_REGISTRATION_ENABLED`). `POST /register` and `POST /api/register`
  answer 404 until an application turns it on. A registered account passes
  every route gated on `auth` alone, and nothing in visns-components calls
  these routes — the stack signs people up by invitation.
- **Sign-in, password reset, two-factor and registration are rate limited**
  (`auth_throttle`, default `throttle:visns-auth`): ten a minute per address
  and IP (a two-factor post is keyed on its session), plus sixty a minute per
  IP. An application defining its own `visns-auth` limiter keeps it; null
  turns the throttle off.

### Fixed

- **The file, role, permission, PDF, notification and two-factor routes need
  a signed-in user** of their own, instead of relying on each application's
  `routes_middleware` carrying `auth`. `ajax/user/profile` still answers a
  guest with its empty payload, which the sign-in screen reads. Roles and
  permissions take `roles_middleware` (default `['auth']`; add your
  administrator permission).
- **PDF routes never evaluate PHP.** Every render forces `isPhpEnabled` off,
  PDF JavaScript and debug temp files off, and reads confined to `public/`
  unless a call site names its own root; the `options` a caller posts to
  `generate-from-html` are limited to layout keys. Remote images stay on by
  default for S3-hosted pictures (`pdf.remote_enabled`).
- **`ajax/files/downloadByPath` reaches only a path the `files` table knows**,
  refuses traversal, and always sends an attachment with a filename that
  cannot break the header. DataGrid's use (a file row without its id) is
  unchanged.
- **`DynamicController` copies only a fresh upload** (`tmp/<uuid>`, as Vapor
  issues) onto a record. A posted key naming any other object in the bucket
  used to be copied onto the record and then downloadable from it —
  `DynamicJsonController` already refused this.
- **The free-form report builder hides credential tables and secret
  columns** (`Support\ReportSchemaPolicy`): sessions, tokens, password resets,
  integration settings, the vault, jobs; and any column named like
  `password`, `*_token`, `*_secret`, `api_key`, `credentials`. They are left
  out of every listing and a query naming one — main table, join, column,
  formula, filter, sort or distinct field — is refused with a 422 before any
  SQL runs. `report_builder.denied_tables` / `denied_columns` add to it and
  `allowed_tables` narrows it to a list. The semantic (v2) builder is
  unchanged; it only names what the registry declares.

### Added

- `entity_default_middleware` (default `['auth']`): what a dynamic entity with
  no middleware of its own gets.

Tests: `tests/Platform/Security` (13).

## 4.16.0

### Added — Email campaigns (`email_campaigns`), on Resend Broadcasts

Off by default. A newsletter manager in the shape of the popular ones:

- **Lists** — built from the host's contacts through an `EmailContactSource`
  (`email_campaigns.contact_source`), or imported from a spreadsheet. Each list
  is a Resend **segment**; `email-campaigns:sync` (schedule it every minute —
  there is no queue worker assumed) pushes members at `sync.per_second` inside
  a `sync.seconds` budget, and a 429 ends the tick without failing anybody.
  A CRM list is refreshed on demand: new people added, departed people removed.
- **A block editor's renderer** — heading, text, image, button, divider,
  spacer, columns — rendered server-side to table-based email HTML with the
  brand block (`email_campaigns.brand`). Rich text is cleaned (no script, no
  handlers, no `javascript:`), and the footer with the physical address and
  `{{{RESEND_UNSUBSCRIBE_URL}}}` is always added — it is not a block anybody
  can delete. `{first_name}` style merge tags become Resend's triple-brace
  syntax with a fallback.
- **Send test, schedule, send now, cancel, duplicate, templates.** Every
  blocker is reported at once as a sentence.
- **Reports** off the Resend webhook (`api/resend/webhook`, Svix-verified):
  delivered, opened, clicked, bounced, complained, unsubscribed, per campaign.
  A `contact.updated` unsubscribe and a complaint take the person off every
  list's future sends.

Permissions `Email Campaigns Access` / `Email Campaigns Manage` are the host's
to seed. The button and link colour is `brand.button` separately from the
accent, because a brand green is rarely 4.5:1 on white.

## 4.15.4

### Fixed — Call queue: an answered call stayed on everybody else's screen for two minutes

Product owner: *"a call rang and I picked it up but the call pop on other user
browser did not dismiss for about 2 minutes"*. Read off production's ledger
(2026-09-17, call `7686398976166803828`, a direct call to one extension with two
devices):

| received | event | device | outcome |
| --- | --- | --- | --- |
| 15:13:45.000 | `callee_ringing` | A | `ringing_recorded_direct` |
| 15:13:57.000 | `callee_ringing` | B | `ringing_recorded_direct` |
| 15:13:57.000 | `callee_answered` | B | `answered` |
| 15:13:57.000 | `callee_ended` | A | **`ended_leg`** |
| 15:16:30.000 | `callee_ended` | B | `closed` |

**Zoom delivered device B's ring and its answer in the same millisecond, to two
PHP-FPM workers.** `handleClosingEvent()` DELETES the live row on an answer; the
ring, a moment behind, ran `updateOrCreate(['call_id' => …])`, found nothing and
**created the row again** — a brand new ringing call, broadcast to every browser,
for a conversation already in progress. The fourth line is the proof: device A's
`callee_ended` found a row with a leg "still ringing" and kept it, where
`closed_no_match` would have been the honest outcome. Nothing was ever going to
close that row, so the card sat there until `max_ringing_seconds` (120) expired
it. The browsers' 30-second reconcile could not help: the server agreed the call
was live.

Answering deletes the row, so the row cannot remember it was answered. **A
short-lived cache marker does** (`call_queue.answered_grace_seconds`, **10**; 0
switches it off), and the order of operations is the guarantee:

- the answer writes the marker **before** it reads or deletes the row;
- the ring looks for it **before it writes**, and **again after its row lock and
  before it broadcasts** — and a ring whose row was deleted under it (the lock
  finds nothing) is no longer broadcast from a stale model either.

Whichever way the two requests interleave, either the answer's delete removes
the ring's row or the ring's second look sees the marker and removes it itself.
Both are recorded as **`ringing_after_answer`**, which the diagnostics panel draws
in its neutral grey. The one interleaving left is a few instructions wide and
leaves a card with **no row behind it**, which the pop's own reconcile clears.

**Only an answer leaves the marker, never an `ended`.** A call that ends and rings
again under one `call_id` is something Zoom really does — a sequential ring moving
from the desk phone to the mobile, a queue re-offering a call — and that ring
must still pop. A transfer of a call somebody has just answered rings later than
ten seconds and pops as before.

The marker needs a cache store every worker shares (`database`, `redis`, `file`);
`array` is per-process and would make the guard a no-op outside a test suite.

Tests: seven in `DirectCallPopTest` — the ring after the answer, the row deleted
under the ring, the marker appearing mid-ring, the window expiring, `ended`
leaving no marker, another call unaffected, and the switch. The first three fail
against 4.15.3.

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
