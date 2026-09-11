<?php

namespace Visnsstudio\VisnsPackages\Controllers;

use Illuminate\Http\Request;
use Visnsstudio\VisnsPackages\Models\SmsOptOut;
use Visnsstudio\VisnsPackages\Services\Sms\SmsOptOuts;
use Visnsstudio\VisnsPackages\Support\SmsPayload;

/**
 * The opt-out register, as a screen.
 *
 * A controller of its own rather than three more methods on
 * SmsCampaignController, and the reason is which module each belongs to:
 * campaigns are an OPT-IN sub-module that ships disabled, while the register is
 * part of messaging itself and is registered whenever messaging is. Putting
 * always-on endpoints in a class named for the thing that is usually off is how
 * somebody later "tidies them up" behind the wrong config flag.
 *
 * Everything here is manage-gated in the ROUTE (see the service provider), not
 * in the controller as the template and line-settings endpoints are. The
 * difference is what is behind it: a list of every client who has asked not to
 * be contacted, and a button that takes somebody OFF that list. Deciding
 * whether a person may see it at all is a middleware decision; there is nothing
 * here a non-administrator should get a partial view of.
 */
class SmsOptOutController extends \App\Http\Controllers\Controller
{
    /**
     * How many rows one listing returns.
     *
     * Not paginated, deliberately. This is a compliance register somebody
     * searches, not a feed they scroll: the useful question is "is this number
     * on the list", which the search box answers, and 200 recent rows is enough
     * to see that the mechanism is working. A practice whose register has grown
     * past that has a reporting requirement rather than a paging one.
     */
    private const LIMIT = 200;

    public function __construct(private SmsOptOuts $optOuts)
    {
    }

    /**
     * The register, newest first.
     *
     * `search` is a substring of the NUMBER, matched against the stored E.164
     * and against its digits, so somebody typing "0412 345" off a screen finds
     * "+61412345678". Same reasoning as SmsThread::scopeSearch - a number you
     * can see and cannot find reads as a broken search box.
     */
    public function index(Request $request)
    {
        $query = SmsOptOut::query()->with('user');

        $term = trim((string) $request->input('search', ''));

        if ($term !== '') {
            $digits = preg_replace('/\D+/', '', $term) ?? '';

            $query->where(function ($q) use ($term, $digits) {
                $q->where('number', 'like', '%' . $term . '%');

                if ($digits !== '') {
                    $q->orWhere('number', 'like', '%' . ltrim($digits, '0') . '%');
                }
            });
        }

        $rows = $query
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->limit(self::LIMIT)
            ->get();

        return response()->json([
            'opt_outs' => $rows->map(fn (SmsOptOut $row) => SmsPayload::optOut($row))->values(),
        ]);
    }

    /**
     * Record one by hand.
     *
     * The keyword path covers somebody who texts STOP. This covers every other
     * way a person asks - on the phone, by email, across the desk - and it is
     * the half that makes the register trustworthy: a practice that could only
     * record the requests that arrived by SMS would have a register that was
     * quietly incomplete.
     */
    public function store(Request $request)
    {
        $data = $request->validate([
            'number' => ['required', 'string', 'max:32'],
            'note' => ['nullable', 'string', 'max:191'],
        ]);

        $e164 = $this->optOuts->normalise($data['number']);

        if ($e164 === null) {
            // 422 rather than a best guess. Adding the wrong number to this list
            // silently stops texting somebody who never asked, and nobody would
            // ever find out.
            $message = 'That does not look like a phone number we can text.';

            return response()->json([
                'message' => $message,
                'errors' => ['number' => [$message]],
            ], 422);
        }

        $optOut = $this->optOuts->record(
            $e164,
            SmsOptOut::SOURCE_MANUAL,
            null,
            null,
            $request->user(),
            $data['note'] ?? null
        );

        // 201 even when the row already existed and was updated: the caller
        // asked for this number to be on the list and it is, which is the only
        // thing they wanted to know.
        return response()->json([
            'opt_out' => SmsPayload::optOut($optOut->fresh('user')),
        ], 201);
    }

    /**
     * Take a number off the register.
     *
     * A real delete - see Services\Sms\SmsOptOuts::release() for why this one
     * table is not soft-deleted like everything else in the module.
     */
    public function destroy(Request $request, $id)
    {
        $optOut = SmsOptOut::find($id);

        if ($optOut === null) {
            abort(404, 'Not found.');
        }

        $this->optOuts->release((string) $optOut->number);

        return response()->noContent();
    }
}
