<?php

namespace Visnsstudio\VisnsPackages\Controllers;

use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Visnsstudio\VisnsPackages\Models\SmsLine;
use Visnsstudio\VisnsPackages\Services\Sms\SmsService;
use Visnsstudio\VisnsPackages\Services\Zoom\ZoomSmsClient;
use Visnsstudio\VisnsPackages\Support\ModuleConfig;
use Visnsstudio\VisnsPackages\Support\PhoneNumber;
use Visnsstudio\VisnsPackages\Support\SmsAccess;
use Visnsstudio\VisnsPackages\Support\SmsPayload;

/**
 * Settings -> Messaging: the lines, and who works them.
 *
 * Administrators only, all of it. Attaching a user to a line is granting them
 * sight of every conversation on it, past and future, which is a bigger act than
 * it looks on a screen full of checkboxes.
 *
 * When the Zoom transport is configured, the page also offers the account's real
 * phone users and their numbers so a line can be pointed at one by picking
 * rather than typing. Zoom being unreachable is NOT an error here: the page has
 * to keep working for the pivot and the labels, so the list comes back empty
 * with `zoom_error` set and the UI warns.
 */
class SmsLineSettingsController extends \App\Http\Controllers\Controller
{
    public function __construct(private SmsService $sms)
    {
    }

    /**
     * Every line, with its staff - and Zoom's own numbers when it answers.
     */
    public function index(Request $request)
    {
        $this->requireManage($request);

        $lines = SmsLine::query()
            ->withTrashed()
            ->with('users')
            ->orderBy('label')
            ->get();

        $payload = [
            'data' => $lines->map(fn (SmsLine $line) => $this->row($line))->values(),
            'transport' => $this->sms->transportName(),
            'zoom_connected' => $this->sms->connected(),
            'zoom_users' => [],
        ];

        if (! $this->sms->connected()) {
            return response()->json($payload);
        }

        try {
            $result = app(ZoomSmsClient::class)->listPhoneUsers();

            if ($result['success']) {
                $payload['zoom_users'] = $result['users'];
            } else {
                $payload['zoom_error'] = $result['error'] ?? 'Failed to reach the Zoom API';
            }
        } catch (\Throwable $e) {
            // Never 500 because Zoom is down - the rest of this page is local
            // data and is exactly what somebody would be here to fix.
            $payload['zoom_error'] = 'Could not reach Zoom: ' . $e->getMessage();
        }

        return response()->json($payload);
    }

    public function store(Request $request)
    {
        $this->requireManage($request);

        $data = $this->validated($request, null);

        $line = SmsLine::create([
            'label' => $data['label'],
            'phone_number' => $data['phone_number'],
            'zoom_user_id' => $data['zoom_user_id'] ?? null,
            'zoom_user_email' => $data['zoom_user_email'] ?? null,
            'active' => $data['active'] ?? true,
        ]);

        $this->syncUsers($line, $data);

        return response()->json($this->row($line->fresh('users')), 201);
    }

    public function update(Request $request, $id)
    {
        $this->requireManage($request);

        $line = SmsLine::withTrashed()->find($id);

        if ($line === null) {
            abort(404, 'Not found.');
        }

        $data = $this->validated($request, (int) $line->id);

        foreach (['label', 'phone_number', 'zoom_user_id', 'zoom_user_email', 'active'] as $field) {
            if (array_key_exists($field, $data)) {
                $line->{$field} = $data[$field];
            }
        }

        $line->save();

        $this->syncUsers($line, $data);

        return response()->json($this->row($line->fresh('users')));
    }

    /**
     * Soft delete.
     *
     * The line's threads and messages stay exactly where they are - they are
     * client communications under a record-keeping obligation, and a number
     * being handed back to the carrier is not a reason to lose the history of
     * what was said on it. Deactivating (`active = false`) is usually what is
     * actually wanted.
     */
    public function destroy(Request $request, $id)
    {
        $this->requireManage($request);

        $line = SmsLine::find($id);

        if ($line === null) {
            abort(404, 'Not found.');
        }

        $line->delete();

        return response()->noContent();
    }

    /* ---------------------------------------------------------------------
     | Internals
     | ------------------------------------------------------------------- */

    /**
     * @return array<string, mixed>
     */
    private function row(SmsLine $line): array
    {
        return [
            'id' => $line->id,
            'label' => $line->label,
            'phone_number' => $line->phone_number,
            'display_number' => $line->display_number,
            'zoom_user_id' => $line->zoom_user_id,
            'zoom_user_email' => $line->zoom_user_email,
            'active' => (bool) $line->active,
            'deleted' => $line->trashed(),
            'users' => $line->users->map(fn ($user) => [
                'id' => $user->id,
                'name' => SmsPayload::displayName($user),
            ])->values(),
        ];
    }

    /**
     * Replace the line's staff list, when the request carried one.
     *
     * A request that omits `user_ids` entirely leaves the pivot alone - saving a
     * label from a form that never loaded the user list must not silently
     * detach everybody.
     *
     * @param  array<string, mixed>  $data
     */
    private function syncUsers(SmsLine $line, array $data): void
    {
        if (! array_key_exists('user_ids', $data)) {
            return;
        }

        $ids = array_values(array_unique(array_map(
            'intval',
            (array) ($data['user_ids'] ?? [])
        )));

        $line->users()->sync($ids);
    }

    private function requireManage(Request $request): void
    {
        if (! SmsAccess::manages($request->user())) {
            abort(403, 'Only a messaging administrator can change lines.');
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function validated(Request $request, ?int $ignoreId): array
    {
        $table = (string) ModuleConfig::get('messaging.tables.lines', 'sms_lines');
        $creating = $ignoreId === null;

        $unique = Rule::unique($table, 'phone_number');

        if (! $creating) {
            $unique = $unique->ignore($ignoreId);
        }

        $data = $request->validate([
            'label' => [$creating ? 'required' : 'sometimes', 'string', 'max:191'],
            'phone_number' => [
                $creating ? 'required' : 'sometimes',
                'string',
                'max:32',
                // Normalised below, so uniqueness is checked against the
                // canonical form rather than whatever spelling was typed. The
                // closure runs on the RAW input, so it also rejects a number
                // this module could never send to.
                function (string $attribute, $value, $fail) {
                    if (PhoneNumber::toE164((string) $value, $this->country()) === null) {
                        $fail('The :attribute must be a phone number we can text.');
                    }
                },
            ],
            'zoom_user_id' => ['nullable', 'string', 'max:191'],
            'zoom_user_email' => ['nullable', 'string', 'max:191'],
            'active' => ['sometimes', 'boolean'],
            'user_ids' => ['sometimes', 'array'],
            'user_ids.*' => ['integer'],
        ]);

        if (array_key_exists('phone_number', $data)) {
            $data['phone_number'] = PhoneNumber::toE164(
                (string) $data['phone_number'],
                $this->country()
            );

            // Uniqueness has to be checked on the canonical form: "0412 345 678"
            // and "+61412345678" are the same inbox, and two rows claiming it
            // would make every inbound message for it ambiguous.
            $request->merge(['phone_number' => $data['phone_number']]);

            $request->validate([
                'phone_number' => ['required', 'string', $unique],
            ]);
        }

        return $data;
    }

    private function country(): string
    {
        return (string) ModuleConfig::get('messaging.default_country', 'AU');
    }
}
