<?php

namespace Visnsstudio\VisnsPackages\Controllers;

use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Visnsstudio\VisnsPackages\Models\VaultAccessLog;
use Visnsstudio\VisnsPackages\Models\VaultEntry;
use Visnsstudio\VisnsPackages\Models\VaultShare;
use Visnsstudio\VisnsPackages\Support\ModuleConfig;

/**
 * The staff side of external share links: create one, list an entry's, revoke.
 *
 * Sits behind exactly the gates the rest of the vault sits behind - the access
 * permission on the route, and `visibleTo` on the entry - because handing a
 * credential to somebody outside the CRM must not be reachable from a session
 * that could not read the credential itself.
 *
 * TWO RULES SPECIFIC TO THIS CONTROLLER
 *
 * **The URL exists once.** `store()` returns the full link in its response and
 * the raw token is then thrown away; only its SHA-256 is written. `index()`
 * cannot reproduce it, this controller cannot reproduce it, and a database dump
 * does not contain it. That is deliberate and it is the reason the front end
 * says "you will not see this link again" - it is a statement of fact, not a
 * nudge.
 *
 * **Every share is logged, at both ends.** Creating one writes a
 * `share_create` row; revoking writes `share_revoke`; each external reveal
 * writes `share_view` (from the public controller). An entry's access log is
 * how "who has had this password" is answered, and a link that let a credential
 * out of the building without appearing there would put a hole straight through
 * the middle of it.
 *
 * A note on `user_id` in those log rows. The access log's user is the CRM
 * account ACCOUNTABLE for the access, and for an external view that is the
 * person who created the link - the recipient has no account and inventing one
 * would be worse than attributing it. The `ip` and `user_agent` on the row are
 * the recipient's, so the two together read as "the link Reyhan created was
 * opened from 203.0.113.9", which is the sentence that is actually wanted.
 */
class VaultShareController extends \App\Http\Controllers\Controller
{
    /**
     * The longest a link may live, in days, unless config says otherwise.
     *
     * There is no "never expires" option and that is the point: a permanent
     * public URL to a credential is not a share, it is a leak with a nice UI.
     */
    private const DEFAULT_MAX_DAYS = 30;

    /** A cap on the view budget. Anything larger is effectively unlimited. */
    private const MAX_VIEWS_CEILING = 100;

    /**
     * Every share on one entry, newest first.
     *
     * Includes the closed ones. A revoked or spent link is exactly what
     * somebody asking "did we send this out, and to how many people" needs to
     * see, and hiding it would make the list answer a different question than
     * the one it is opened for.
     */
    public function index(Request $request, $id)
    {
        $entry = $this->visibleEntry($request, $id);

        $shares = VaultShare::query()
            ->where('vault_entry_id', $entry->id)
            ->with('createdBy')
            ->orderByDesc('id')
            ->get();

        return response()->json([
            'data' => $shares->map(fn(VaultShare $share) => $this->row($share))->values(),
        ]);
    }

    /**
     * Create a link.
     *
     * The response carries the URL and this is the only time it does. Marked
     * `no-store` for the same reason a password reveal is: a shared proxy or a
     * back/forward cache holding this response is holding a working link to a
     * credential.
     */
    public function store(Request $request, $id)
    {
        $user = $request->user();
        $entry = $this->visibleEntry($request, $id);

        $maxDays = (int) ModuleConfig::get('vault.share.max_days', self::DEFAULT_MAX_DAYS);
        $maxDays = $maxDays > 0 ? $maxDays : self::DEFAULT_MAX_DAYS;

        $data = $request->validate([
            'fields' => ['required', 'array', 'min:1'],
            'fields.*' => ['required', 'string', Rule::in(VaultShare::FIELDS)],
            // Hours rather than a date: the client is choosing "24 hours", not
            // "9:41pm tomorrow", and letting it send an absolute timestamp
            // would put the recipient's expiry on the sender's clock.
            'expires_in_hours' => ['required', 'integer', 'min:1', 'max:' . ($maxDays * 24)],
            'max_views' => ['nullable', 'integer', 'min:1', 'max:' . self::MAX_VIEWS_CEILING],
        ]);

        $fields = VaultShare::cleanFields($data['fields']);

        if ($fields === []) {
            return response()->json([
                'message' => 'Choose at least one field to share.',
            ], 422);
        }

        // Refuse to share a field the entry does not hold. A link promising a
        // password to a recipient who then finds an empty box is a support call
        // and a second, more careless, attempt at sending it.
        if ($missing = $this->fieldsNotOnEntry($entry, $fields)) {
            return response()->json([
                'message' => 'This entry has no ' . implode(' or ', $missing) . ' to share.',
            ], 422);
        }

        $token = VaultShare::newToken();

        $share = VaultShare::create([
            'vault_entry_id' => $entry->id,
            'token_hash' => VaultShare::hashToken($token),
            'fields_shared' => $fields,
            'created_by_user_id' => $user?->id,
            'expires_at' => now()->addHours((int) $data['expires_in_hours']),
            'max_views' => $data['max_views'] ?? null,
            'views' => 0,
        ]);

        VaultAccessLog::record($entry, 'share_create', $request);

        return response()
            ->json(
                $this->row($share) + [
                    // The one and only time this appears anywhere.
                    'url' => self::publicUrl($token),
                ],
                201
            )
            ->header('Cache-Control', 'no-store');
    }

    /**
     * Close a link.
     *
     * Idempotent: revoking an already-revoked share answers 200 with the row
     * rather than an error. The caller's intent - "this must not open" - is
     * already true, and a front end retrying after a dropped connection should
     * not be told it failed.
     *
     * Revocable by whoever created it, or by a vault administrator. Not by
     * every user who can see the entry: on a shared entry that would be
     * everyone, and closing somebody else's link out from under them is an
     * administrative act.
     */
    public function destroy(Request $request, $id, $shareId)
    {
        $user = $request->user();
        $entry = $this->visibleEntry($request, $id);

        $share = VaultShare::query()
            ->where('vault_entry_id', $entry->id)
            ->find($shareId);

        if ($share === null) {
            return response()->json(['message' => 'Not found.'], 404);
        }

        if (
            ! $this->manages($user)
            && (int) $share->created_by_user_id !== (int) ($user->id ?? 0)
        ) {
            return response()->json([
                'message' => 'Only the person who created this link, or a vault administrator, can revoke it.',
            ], 403);
        }

        if (! $share->isRevoked()) {
            $share->revoked_at = now();
            $share->save();

            VaultAccessLog::record($entry, 'share_revoke', $request);
        }

        return response()->json($this->row($share->refresh()));
    }

    /* ---------------------------------------------------------------------
     | Internals
     | ------------------------------------------------------------------- */

    /**
     * The full public URL for a token.
     *
     * Built from the configured public base so that moving the public route
     * moves the links the create endpoint hands out, in one place.
     */
    public static function publicUrl(string $token): string
    {
        $base = trim(
            (string) ModuleConfig::get('vault.share.uris.public', 'vault/share'),
            '/'
        ) ?: 'vault/share';

        return url('/' . $base . '/' . $token);
    }

    /**
     * The list row. No token, no hash, and nothing off the credential itself -
     * a share list is not a place a password may appear.
     *
     * @return array<string, mixed>
     */
    private function row(VaultShare $share): array
    {
        return [
            'id' => $share->id,
            'vault_entry_id' => $share->vault_entry_id,
            'fields' => (array) ($share->fields_shared ?? []),
            'status' => $share->status(),
            'views' => (int) $share->views,
            'max_views' => $share->max_views,
            'expires_at' => $share->expires_at?->toIso8601String(),
            'revoked_at' => $share->revoked_at?->toIso8601String(),
            'last_viewed_at' => $share->last_viewed_at?->toIso8601String(),
            'created_at' => $share->created_at?->toIso8601String(),
            'created_by_user_id' => $share->created_by_user_id,
            'created_by' => $share->relationLoaded('createdBy') && $share->createdBy
                ? $this->displayName($share->createdBy)
                : null,
        ];
    }

    /**
     * Which of the requested fields the entry has nothing to put in.
     *
     * `totp` is checked against `has_totp` rather than the seed itself, and
     * `notes`/`url`/`username` against emptiness after trimming - a field
     * holding a single space is not a field.
     *
     * @param  array<int, string>  $fields
     * @return array<int, string>
     */
    private function fieldsNotOnEntry(VaultEntry $entry, array $fields): array
    {
        $missing = [];

        foreach ($fields as $field) {
            $empty = match ($field) {
                'totp' => ! $entry->has_totp,
                'password' => trim((string) ($entry->password ?? '')) === '',
                'username' => trim((string) ($entry->username ?? '')) === '',
                'url' => trim((string) ($entry->url ?? '')) === '',
                'notes' => trim((string) ($entry->notes ?? '')) === '',
                default => true,
            };

            if ($empty) {
                $missing[] = $field === 'totp' ? '2FA code' : $field;
            }
        }

        return $missing;
    }

    /**
     * An entry this user is allowed to look at, or a 404 - the same rule, and
     * the same silence on a miss, as VaultController.
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
}
