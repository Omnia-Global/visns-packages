<?php

namespace Visnsstudio\VisnsPackages\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use Visnsstudio\VisnsPackages\Models\VaultAccessLog;
use Visnsstudio\VisnsPackages\Models\VaultEntry;
use Visnsstudio\VisnsPackages\Services\VaultOtpService;
use Visnsstudio\VisnsPackages\Support\ModuleConfig;

/**
 * The vault: staff-shared credentials, their one-time codes, and the record of
 * who read them.
 *
 * Three rules run through every method here and are worth stating once.
 *
 * **Secrets are never in a list.** `index()` builds its rows field by field and
 * the three encrypted columns are not among them - not hidden, not nulled,
 * absent. A list is the payload most likely to be cached by a proxy, logged by
 * an APM or left sitting in a browser's memory, and the cheapest way to keep
 * secrets out of all three is for them never to be put in.
 *
 * **An entry you cannot see does not exist.** Every miss is a 404, including the
 * ones that are really "you are not allowed". A 403 on a private entry would
 * answer the question "does the CEO have a vault entry called Payroll" for
 * anyone who cared to ask.
 *
 * **Revealing is a separate act from reading.** Opening an entry needs only the
 * access permission; getting the plaintext out needs a fresh password
 * confirmation on top (see EnsureVaultPasswordConfirmed) and is written to the
 * access log every single time.
 *
 * Permissions come from config: `vault.permissions.access` gates every route,
 * `vault.permissions.manage` is the administrative grant checked in here.
 * Setting either to null in config removes that gate entirely - which for
 * `manage` means every user with access is treated as an administrator, so do
 * that only if something else is enforcing it.
 */
class VaultController extends \App\Http\Controllers\Controller
{
    private const MAX_PER_PAGE = 100;
    private const DEFAULT_PER_PAGE = 25;

    /** The only action a browser is allowed to report for itself. */
    private const CLIENT_ACTIONS = ['copy_username'];

    public function __construct(private VaultOtpService $otp)
    {
    }

    /**
     * The entries this user may see.
     *
     * Paginated rather than complete: a vault is small today and a browser that
     * has to be handed all of it is a browser holding every title, username and
     * URL in the organisation in one response.
     */
    public function index(Request $request)
    {
        $user = $request->user();

        // The only parameter here worth rejecting rather than correcting: page
        // size and sort are preferences with sane fallbacks, but a client
        // filter that silently becomes `client_id = 0` would answer "this
        // client has no credentials" to a caller that asked something else.
        $request->validate([
            'client_id' => ['nullable', 'integer', 'min:1'],
        ]);

        $perPage = (int) $request->input('per_page', self::DEFAULT_PER_PAGE);
        $perPage = max(1, min($perPage, self::MAX_PER_PAGE));

        $sort = (string) $request->input('sort', 'title');

        if (! in_array($sort, VaultEntry::sortableColumns(), true)) {
            // Silently corrected rather than rejected: a sort is a preference,
            // and an unknown one is far more likely a stale bookmark than an
            // attack. The whitelist is what matters - ORDER BY on an arbitrary
            // column is a way to read a column you were never shown.
            $sort = 'title';
        }

        $direction = strtolower((string) $request->input('direction', 'asc')) === 'desc'
            ? 'desc'
            : 'asc';

        $query = VaultEntry::query()
            ->visibleTo($user)
            ->search($request->input('search'));

        // Scoped to one client, which is what a customer's Credentials tab
        // asks for. Applied AFTER `visibleTo`, never instead of it — narrowing
        // to a client must not widen what the user is allowed to see.
        if ($request->filled('client_id')) {
            $query->where('client_id', (int) $request->input('client_id'));
        }

        // Soft-deleted entries are an administrator's concern; for everyone else
        // a deleted entry is simply gone.
        if ($request->boolean('include_deleted') && $this->manages($user)) {
            $query->withTrashed();
        }

        $entries = $query
            ->orderBy($sort, $direction)
            // A stable tiebreak, so page 2 cannot repeat a row from page 1 when
            // several entries share a title.
            ->orderBy('id', 'asc')
            ->paginate($perPage);

        return response()->json(
            $entries->through(fn(VaultEntry $entry) => $this->summary($entry, $user))
        );
    }

    /**
     * One entry, with its notes - but never its password or seed.
     *
     * `access_log_count` includes the `view` this request just wrote: the log
     * entry is recorded before the payload is built, so that a serialisation
     * failure cannot lose the record of somebody having opened the entry.
     *
     * Notes are decrypted here because they are what the entry is *for* half the
     * time ("the recovery answers are ...", "ring the desk on x304 first"), and
     * a note the user has to click twice to read gets kept somewhere worse
     * instead. The password and the seed stay behind their own endpoints.
     */
    public function show(Request $request, $id)
    {
        $user = $request->user();
        $entry = $this->visibleEntry($request, $id);

        VaultAccessLog::record($entry, 'view', $request);

        return response()->json($this->detail($entry, $user));
    }

    /**
     * Create an entry.
     *
     * A shared entry is one everybody with access can read, so creating one is
     * an administrative act and needs `manage`. A user without it can still keep
     * private entries, which is the common case.
     */
    public function store(Request $request)
    {
        $user = $request->user();
        $data = $this->validateEntry($request);

        $visibility = $data['visibility'] ?? 'shared';

        if ($visibility === 'shared' && ! $this->manages($user)) {
            return response()->json([
                'message' => 'Only a vault administrator can create a shared entry.',
            ], 403);
        }

        $entry = new VaultEntry();

        $entry->title = $data['title'];
        $entry->username = $data['username'] ?? null;
        $entry->url = $data['url'] ?? null;
        $entry->notes = $data['notes'] ?? null;
        $entry->tags = $this->cleanTags($data['tags'] ?? null);
        $this->assignClient($entry, $data);
        $entry->visibility = $visibility;
        $entry->owner_user_id = $user?->id;
        $entry->updated_by_user_id = $user?->id;

        $password = $data['password'] ?? null;

        if (is_string($password) && $password !== '') {
            $entry->password = $password;
            $entry->password_rotated_at = now();
        }

        $this->applyTotp($entry, $data['totp_secret'] ?? null);

        $entry->save();

        return response()->json($this->detail($entry, $user), 201);
    }

    /**
     * Update an entry.
     *
     * Two semantics live here and they are different on purpose.
     *
     * The ordinary fields are a partial update: a key that is not in the request
     * is not touched. The one exception is `title`, which is required, because
     * an entry with no title is unfindable in a list that sorts by it.
     *
     * The two secrets are three-state, which a plain "present or not" cannot
     * express: **absent** leaves the stored secret alone, **null or empty**
     * clears it, and a **value** replaces it. Without the first state every save
     * from a form that (correctly) never received the current password would
     * wipe it.
     */
    public function update(Request $request, $id)
    {
        $user = $request->user();
        $entry = $this->editableEntry($request, $id);
        $data = $this->validateEntry($request);

        $targetVisibility = $data['visibility'] ?? $entry->visibility;

        // Both halves of this matter: publishing a private entry to everyone is
        // an administrative act, and so is editing something everyone already
        // relies on.
        if (
            ($targetVisibility === 'shared' || $entry->visibility === 'shared')
            && ! $this->manages($user)
        ) {
            return response()->json([
                'message' => 'Only a vault administrator can change a shared entry.',
            ], 403);
        }

        $entry->title = $data['title'];

        foreach (['username', 'url', 'notes'] as $field) {
            if (array_key_exists($field, $data)) {
                $entry->{$field} = $data[$field];
            }
        }

        $this->assignClient($entry, $data);

        if (array_key_exists('tags', $data)) {
            $entry->tags = $this->cleanTags($data['tags']);
        }

        $entry->visibility = $targetVisibility;

        if (array_key_exists('password', $data)) {
            $password = is_string($data['password']) && $data['password'] !== ''
                ? $data['password']
                : null;

            // Rotation is about the secret changing, not about the row being
            // saved - a rename must not make a five-year-old password look
            // fresh in a rotation report.
            if ($password !== $entry->password) {
                $entry->password = $password;
                $entry->password_rotated_at = $password === null ? null : now();
            }
        }

        if (array_key_exists('totp_secret', $data)) {
            $this->applyTotp($entry, $data['totp_secret']);
        }

        $entry->updated_by_user_id = $user?->id;
        $entry->save();

        return response()->json($this->detail($entry, $user));
    }

    /**
     * Soft delete. The row stays, so the access log rows pointing at it keep
     * their subject and an administrator can undo a mistake.
     */
    public function destroy(Request $request, $id)
    {
        $entry = $this->editableEntry($request, $id);

        $entry->delete();

        return response()->noContent();
    }

    /**
     * Bring a soft-deleted entry back. Administrators only - it republishes a
     * credential somebody decided to remove.
     */
    public function restore(Request $request, $id)
    {
        $user = $request->user();

        if (! $this->manages($user)) {
            return response()->json([
                'message' => 'Only a vault administrator can restore an entry.',
            ], 403);
        }

        $entry = VaultEntry::withTrashed()->find($id);

        if ($entry === null) {
            return response()->json(['message' => 'Not found.'], 404);
        }

        $entry->restore();

        return response()->json($this->detail($entry, $user));
    }

    /**
     * Re-check the signed-in user's own password and stamp the session.
     *
     * Answers 204, not a token: the confirmation is server state with a
     * deliberate expiry, and handing the browser something to keep would defeat
     * the point of it expiring.
     *
     * A failure is logged with no entry attached. That is the row worth watching
     * - a burst of them against one user is somebody sitting at a session that
     * is not theirs.
     */
    public function confirmPassword(Request $request)
    {
        $request->validate([
            'password' => ['required', 'string'],
        ]);

        $user = $request->user();

        if ($user === null || ! Hash::check($request->input('password'), (string) $user->password)) {
            VaultAccessLog::record(null, 'confirm_failed', $request);

            return response()->json([
                'message' => 'That password is not correct.',
            ], 422);
        }

        $request->session()->put('visns.vault.confirmed_at', now()->getTimestamp());

        return response()->noContent();
    }

    /**
     * Hand over the plaintext password.
     *
     * Guarded by a fresh password confirmation and a rate limit, and logged
     * unconditionally. `no-store` is not decoration: without it a shared proxy or
     * the browser's own back/forward cache is entitled to keep this response,
     * and a password in a disk cache outlives every other control here.
     */
    public function reveal(Request $request, $id)
    {
        $entry = $this->visibleEntry($request, $id);

        VaultAccessLog::record($entry, 'reveal_password', $request);

        return $this->noStore(response()->json([
            'password' => $entry->password !== null && $entry->password !== ''
                ? $entry->password
                : null,
        ]));
    }

    /**
     * The current one-time code.
     *
     * Rate limited alongside reveal rather than exempted: a code is less
     * dangerous than a password but it is still half of a login, and a client
     * polling faster than the period is a client with a bug.
     */
    public function otp(Request $request, $id)
    {
        $entry = $this->visibleEntry($request, $id);

        if (! $entry->has_totp) {
            return response()->json([
                'message' => 'This entry has no authenticator secret.',
            ], 404);
        }

        try {
            $code = $this->otp->currentCode($entry);
        } catch (InvalidArgumentException $e) {
            // A seed that no longer works is a broken entry, not a bad request
            // from this caller - say so plainly rather than 500ing.
            return response()->json([
                'message' => $e->getMessage(),
            ], 422);
        }

        VaultAccessLog::record($entry, 'otp', $request);

        return $this->noStore(response()->json($code));
    }

    /**
     * Record something the browser did that the server cannot see.
     *
     * Copying a username never touches an endpoint, so without this the log
     * would show a `view` and then nothing, and "who has been using the shared
     * admin account" would have no answer. The accepted actions are whitelisted:
     * a client must not be able to write arbitrary strings into an audit trail.
     */
    public function log(Request $request, $id)
    {
        $entry = $this->visibleEntry($request, $id);

        $data = $request->validate([
            'action' => ['required', 'string', Rule::in(self::CLIENT_ACTIONS)],
        ]);

        VaultAccessLog::record($entry, $data['action'], $request);

        return response()->noContent();
    }

    /**
     * One entry's access history. Administrators only.
     */
    public function entryLog(Request $request, $id)
    {
        if (! $this->manages($request->user())) {
            return response()->json([
                'message' => 'Only a vault administrator can read the access log.',
            ], 403);
        }

        $entry = VaultEntry::withTrashed()->find($id);

        if ($entry === null) {
            return response()->json(['message' => 'Not found.'], 404);
        }

        return response()->json(
            $this->logPage(
                VaultAccessLog::query()->where('vault_entry_id', $entry->id),
                $request,
                false
            )
        );
    }

    /**
     * The whole access log, filterable by user and action. Administrators only.
     */
    public function logIndex(Request $request)
    {
        if (! $this->manages($request->user())) {
            return response()->json([
                'message' => 'Only a vault administrator can read the access log.',
            ], 403);
        }

        $query = VaultAccessLog::query();

        if ($request->filled('user_id')) {
            $query->where('user_id', (int) $request->input('user_id'));
        }

        if ($request->filled('action')) {
            $query->where('action', (string) $request->input('action'));
        }

        return response()->json($this->logPage($query, $request, true));
    }

    /* ---------------------------------------------------------------------
     | Internals
     | ------------------------------------------------------------------- */

    /**
     * An entry this user is allowed to look at, or a 404.
     *
     * @throws \Symfony\Component\HttpKernel\Exception\HttpException
     */
    private function visibleEntry(Request $request, $id): VaultEntry
    {
        $entry = VaultEntry::query()
            ->visibleTo($request->user())
            ->find($id);

        if ($entry === null) {
            abort(404, 'Not found.');
        }

        return $entry;
    }

    /**
     * An entry this user is allowed to change, or a 404.
     *
     * "Allowed to change" is their own entries, or everything if they hold
     * manage. Anything else is 404 rather than 403 - the caller has no business
     * knowing whether the id it guessed at is a private entry of somebody
     * else's or nothing at all.
     */
    private function editableEntry(Request $request, $id): VaultEntry
    {
        $user = $request->user();
        $entry = $this->visibleEntry($request, $id);

        if (! $this->manages($user) && (int) $entry->owner_user_id !== (int) ($user->id ?? 0)) {
            abort(404, 'Not found.');
        }

        return $entry;
    }

    /**
     * Whether this user holds the administrative grant.
     *
     * A permission name that has never been seeded makes Spatie throw; that is a
     * deployment gap, not an authorisation, so it fails closed. A permission
     * configured as null is a deliberate "do not gate this here" and passes.
     */
    private function manages($user): bool
    {
        $permission = ModuleConfig::get('vault.permissions.manage', 'Vault Manage');

        if (! is_string($permission) || $permission === '') {
            return true;
        }

        if ($user === null) {
            return false;
        }

        try {
            return (bool) $user->hasPermissionTo($permission);
        } catch (\Throwable $e) {
            return false;
        }
    }

    /**
     * The list row. Note what is not here.
     *
     * @return array<string, mixed>
     */
    private function summary(VaultEntry $entry, $user): array
    {
        return [
            'id' => $entry->id,
            'title' => $entry->title,
            'username' => $entry->username,
            'url' => $entry->url,
            'has_totp' => $entry->has_totp,
            'visibility' => $entry->visibility,
            'client_id' => $entry->client_id,
            'client_label' => $entry->client_label,
            'client_url' => $this->clientUrl($entry->client_id),
            'tags' => $entry->tags ?? [],
            'owner_user_id' => $entry->owner_user_id,
            'updated_at' => $entry->updated_at?->toIso8601String(),
            'password_rotated_at' => $entry->password_rotated_at?->toIso8601String(),
            'can_edit' => $this->manages($user)
                || (int) $entry->owner_user_id === (int) ($user->id ?? 0),
            'deleted_at' => $entry->deleted_at?->toIso8601String(),
        ];
    }

    /**
     * The detail payload: the list row, plus the fields only worth loading one
     * entry at a time. Still no password and still no seed.
     *
     * @return array<string, mixed>
     */
    private function detail(VaultEntry $entry, $user): array
    {
        return $this->summary($entry, $user) + [
            'notes' => $entry->notes,
            'totp_digits' => (int) $entry->totp_digits,
            'totp_period' => (int) $entry->totp_period,
            'created_at' => $entry->created_at?->toIso8601String(),
            'updated_by_user_id' => $entry->updated_by_user_id,
            'access_log_count' => $entry->accessLogs()->count(),
        ];
    }

    /**
     * A page of log rows, newest first.
     *
     * @param  \Illuminate\Database\Eloquent\Builder  $query
     * @return \Illuminate\Contracts\Pagination\LengthAwarePaginator
     */
    private function logPage($query, Request $request, bool $withEntry)
    {
        $perPage = (int) $request->input('per_page', self::DEFAULT_PER_PAGE);
        $perPage = max(1, min($perPage, self::MAX_PER_PAGE));

        $with = ['user'];

        if ($withEntry) {
            // Soft-deleted entries still have log rows pointing at them, and a
            // log that showed a blank title for every removed credential would
            // be useless exactly when it is needed.
            $with['entry'] = fn($q) => $q->withTrashed();
        }

        return $query
            ->with($with)
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->paginate($perPage)
            ->through(function (VaultAccessLog $log) use ($withEntry) {
                $row = [
                    'id' => $log->id,
                    'action' => $log->action,
                    'ip' => $log->ip,
                    // The per-action detail - today, the address a share link
                    // was emailed to. Non-secret facts about the access only;
                    // see VaultAccessLog. Administrators only, like the rest
                    // of this payload.
                    'meta' => $log->meta ?: null,
                    'created_at' => $log->created_at?->toIso8601String(),
                    'user' => $log->user === null ? null : [
                        'id' => $log->user->id,
                        'name' => $this->displayName($log->user),
                    ],
                ];

                if ($withEntry) {
                    $row['entry'] = $log->entry === null ? null : [
                        'id' => $log->entry->id,
                        'title' => $log->entry->title,
                    ];
                }

                return $row;
            });
    }

    /**
     * Applications disagree about where a user's display name lives; take
     * whichever of the usual shapes this one has.
     */
    private function displayName($user): ?string
    {
        $name = trim((string) ($user->name ?? ''));

        if ($name !== '') {
            return $name;
        }

        $name = trim(
            trim((string) ($user->firstname ?? '')) . ' '
            . trim((string) ($user->surname ?? ''))
        );

        return $name !== '' ? $name : null;
    }

    /**
     * Store a TOTP seed, or clear it.
     *
     * The parameters travel with the seed: an 8-digit or 60-second entry whose
     * seed was kept and whose parameters were not produces confidently wrong
     * codes forever.
     *
     * @throws ValidationException
     */
    private function applyTotp(VaultEntry $entry, $input): void
    {
        if (! is_string($input) || trim($input) === '') {
            $entry->totp_secret = null;
            $entry->totp_digits = 6;
            $entry->totp_period = 30;
            $entry->totp_algorithm = 'sha1';

            return;
        }

        try {
            $normalised = $this->otp->normaliseSecret($input);

            // Proved, not assumed - see VaultOtpService::validateSecret().
            $this->otp->validateSecret($normalised);
        } catch (InvalidArgumentException $e) {
            throw ValidationException::withMessages([
                'totp_secret' => [$e->getMessage()],
            ]);
        }

        $entry->totp_secret = $normalised['secret'];
        $entry->totp_digits = $normalised['digits'];
        $entry->totp_period = $normalised['period'];
        $entry->totp_algorithm = $normalised['algorithm'];
    }

    /**
     * Trim, drop blanks, drop duplicates, keep the order the user typed.
     *
     * @return array<int, string>|null
     */
    private function cleanTags($tags): ?array
    {
        if (! is_array($tags)) {
            return null;
        }

        $clean = [];

        foreach ($tags as $tag) {
            $tag = trim((string) $tag);

            if ($tag !== '' && ! in_array($tag, $clean, true)) {
                $clean[] = $tag;
            }
        }

        return $clean;
    }

    /**
     * @return array<string, mixed>
     */
    private function validateEntry(Request $request): array
    {
        return $request->validate([
            'title' => ['required', 'string', 'max:191'],
            'username' => ['nullable', 'string', 'max:191'],
            'url' => ['nullable', 'string', 'max:2048', $this->urlRule()],
            'password' => ['nullable', 'string', 'max:4096'],
            'totp_secret' => ['nullable', 'string', 'max:2048'],
            // The ceiling is the storage column, worked backwards. `notes` is
            // TEXT — 65,535 BYTES on MySQL — and the `encrypted` cast stores
            // Laravel's envelope rather than the plaintext: base64 of a JSON
            // object holding the iv, the base64 ciphertext and the mac, which
            // measures ~1.78x the plaintext, not the ~1.4x a plain base64
            // estimate suggests. 32,000 x 1.78 is roughly 57KB, comfortably
            // inside the column; the old 20,000 was simply cautious.
            //
            // It is also exactly where the consuming application's Zoho raw
            // backup writes its mirrors (CredentialBackup::MAX_NOTES_BYTES
            // splits a payload at this same number), so a lower cap here would
            // make one of those entries impossible to save from the edit form
            // — the user would open a legitimate entry, change its title, and
            // be told the notes are too long.
            'notes' => ['nullable', 'string', 'max:32000'],
            'tags' => ['nullable', 'array', 'max:50'],
            // `nullable` because the framework's ConvertEmptyStringsToNull
            // middleware turns a blank tag into null before this ever runs;
            // cleanTags() drops it.
            'tags.*' => ['nullable', 'string', 'max:40'],
            'visibility' => ['nullable', Rule::in(['shared', 'private'])],
            // Not `exists:` — the client table lives in the consuming
            // application and this package does not know its name. The id is
            // resolved against the configured model below, and an id that
            // resolves to nothing simply clears the assignment.
            'client_id' => ['nullable', 'integer'],
        ]);
    }

    /**
     * Laravel's `url` rule requires a scheme, and nobody types one. What is
     * actually being defended against is a `javascript:` URL that a front end
     * would happily render as a clickable link, and whitespace that suggests the
     * field is being used for something other than an address.
     */
    private function urlRule(): callable
    {
        return function (string $attribute, $value, $fail): void {
            $value = trim((string) $value);

            if ($value === '') {
                return;
            }

            if (preg_match('/\s/', $value)) {
                $fail('The :attribute must not contain spaces.');

                return;
            }

            $scheme = strtolower((string) parse_url($value, PHP_URL_SCHEME));

            if ($scheme !== '' && ! in_array($scheme, ['http', 'https'], true)) {
                $fail('The :attribute must be an http or https address.');

                return;
            }

            $candidate = $scheme === '' ? 'https://' . $value : $value;

            if (filter_var($candidate, FILTER_VALIDATE_URL) === false) {
                $fail('The :attribute must be a valid address.');
            }
        };
    }

    /**
     * @param  \Illuminate\Http\JsonResponse  $response
     * @return \Illuminate\Http\JsonResponse
     */
    private function noStore($response)
    {
        return $response->header('Cache-Control', 'no-store');
    }

    // -- client assignment ----------------------------------------------------

    /**
     * Resolve a client id to its label, or null.
     *
     * The label is stored ON the entry rather than joined at read time: the
     * list shows it on every row, and a package cannot join a table whose name
     * it does not know. It is refreshed on every write, so a renamed client
     * corrects itself the next time the entry is saved.
     */
    private function clientLabel($clientId): ?string
    {
        $model = config('visns-packages.vault.client.model');

        if (!$clientId || !$model || !class_exists($model)) {
            return null;
        }

        $column = config('visns-packages.vault.client.label_column', 'name');

        return $model::query()->whereKey($clientId)->value($column);
    }

    private function clientUrl($clientId): ?string
    {
        $pattern = config('visns-packages.vault.client.url');

        return ($clientId && $pattern)
            ? str_replace('{id}', (string) $clientId, $pattern)
            : null;
    }

    /**
     * Apply a validated client assignment to an entry.
     *
     * Absent key = leave the assignment alone (a partial update must not
     * silently unassign). Present but empty = clear it. An id that resolves to
     * no client also clears it, rather than storing a dangling reference and a
     * label nobody can explain.
     */
    private function assignClient(VaultEntry $entry, array $data): void
    {
        if (!array_key_exists('client_id', $data)) {
            return;
        }

        $clientId = $data['client_id'] ?: null;
        $label = $this->clientLabel($clientId);

        $entry->client_id = $label === null ? null : $clientId;
        $entry->client_label = $label;
    }

    /**
     * Typeahead for the client picker.
     *
     * Returns nothing at all when no client model is configured, so a
     * consuming application without a client concept gets an inert picker
     * rather than an error.
     */
    public function clients(Request $request)
    {
        $model = config('visns-packages.vault.client.model');

        if (!$model || !class_exists($model)) {
            return response()->json(['data' => []]);
        }

        $term = trim((string) $request->input('q', ''));
        $labelColumn = config('visns-packages.vault.client.label_column', 'name');
        $searchColumns = (array) config(
            'visns-packages.vault.client.search_columns',
            [$labelColumn]
        );

        $query = $model::query();

        if ($term !== '') {
            $query->where(function ($q) use ($searchColumns, $term) {
                foreach ($searchColumns as $i => $column) {
                    $q->{$i === 0 ? 'where' : 'orWhere'}($column, 'like', '%' . $term . '%');
                }
            });
        }

        $rows = $query->orderBy($labelColumn)->limit(20)->get();

        return response()->json([
            'data' => $rows->map(fn($row) => [
                'id' => $row->getKey(),
                'label' => $row->{$labelColumn},
            ])->values(),
        ]);
    }

    /**
     * The clients that actually have entries in this vault.
     *
     * This is what fills the list's "client" filter, and it is deliberately
     * NOT `clients()`: that one is a typeahead over every client in the CRM -
     * thousands of them, almost none with a credential - whereas a filter is
     * only useful offering the handful that would return something.
     *
     * Read off the ENTRY table rather than joined to the client model, for two
     * reasons. It works in an application that has no client model configured
     * at all (the label is denormalised onto the row), and it cannot offer a
     * client whose only entries this user is not allowed to see - the list is
     * built through `visibleTo`, so a private entry of somebody else's cannot
     * put a client's name in front of you.
     *
     * The count travels with each row: "which of these is worth filtering to"
     * is the question being asked, and a name on its own does not answer it.
     */
    public function entryClients(Request $request)
    {
        $user = $request->user();

        $query = VaultEntry::query()
            ->visibleTo($user)
            ->whereNotNull($this->entriesTable() . '.client_id');

        // Matches the list: with "Show deleted" on, a client whose entries are
        // all deleted is still worth being able to filter to.
        if ($request->boolean('include_deleted') && $this->manages($user)) {
            $query->withTrashed();
        }

        // Kept on the Eloquent builder rather than dropped to the base query:
        // the soft-delete scope is applied when the Eloquent builder runs, and
        // `getQuery()` would hand back a query with that scope missing - which
        // would count deleted entries into every row above.
        $rows = $query
            ->select('client_id', 'client_label')
            ->selectRaw('COUNT(*) as entries')
            // Grouped by BOTH columns rather than by the id alone: a client
            // renamed between two saves leaves two labels on the table, and
            // `GROUP BY client_id` while selecting `client_label` is exactly
            // what ONLY_FULL_GROUP_BY refuses. The pair is folded back to one
            // row per client below, where the newest label wins.
            ->groupBy('client_id', 'client_label')
            ->get();

        $clients = [];

        foreach ($rows as $row) {
            $id = (int) $row->client_id;
            $label = trim((string) ($row->client_label ?? ''));

            if (!isset($clients[$id])) {
                $clients[$id] = [
                    'id' => $id,
                    'label' => $label,
                    'entries' => 0,
                    'url' => $this->clientUrl($id),
                ];
            }

            $clients[$id]['entries'] += (int) $row->entries;

            // A row whose label was never filled in must not blank out a name
            // the other rows do have.
            if ($clients[$id]['label'] === '' && $label !== '') {
                $clients[$id]['label'] = $label;
            }
        }

        $clients = array_values($clients);

        foreach ($clients as $i => $client) {
            // An id with no name anywhere is still selectable - the entries
            // exist and the filter has to be able to reach them.
            if ($client['label'] === '') {
                $clients[$i]['label'] = 'Client #' . $client['id'];
            }
        }

        // Sorted here rather than in SQL: the collation that decides whether
        // "acme" comes before "Zeta" differs between MySQL and SQLite, and the
        // list is short enough that PHP settling it costs nothing.
        usort(
            $clients,
            fn($a, $b) => strcasecmp((string) $a['label'], (string) $b['label'])
        );

        return response()->json(['data' => $clients]);
    }

    /** The entry table's configured name, for a qualified column. */
    private function entriesTable(): string
    {
        return (new VaultEntry())->getTable();
    }

}
