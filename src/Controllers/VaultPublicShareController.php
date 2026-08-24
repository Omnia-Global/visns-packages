<?php

namespace Visnsstudio\VisnsPackages\Controllers;

use Illuminate\Http\Request;
use InvalidArgumentException;
use Visnsstudio\VisnsPackages\Models\VaultAccessLog;
use Visnsstudio\VisnsPackages\Models\VaultEntry;
use Visnsstudio\VisnsPackages\Models\VaultShare;
use Visnsstudio\VisnsPackages\Services\VaultOtpService;

/**
 * The recipient's side of a share link. Unauthenticated, by definition.
 *
 * WHY THE GET SHOWS NOTHING
 *
 * Every chat client on earth fetches a URL the moment it is pasted, to build a
 * preview card - Slack, Teams, WhatsApp, iMessage, Outlook's Safe Links, half
 * the mail scanners in existence. If the secret were in the GET response then
 * pasting the link into the very chat used to send it would (a) put the
 * password into that service's preview cache and (b) spend the view on a one-
 * view link before the recipient ever clicked it. So the GET is inert: a
 * sentence and a button, no credential anywhere in the body, and the reveal is
 * a POST that only a person pressing a button can make.
 *
 * That is also why the reveal is not a GET with a query parameter, or a link,
 * or an auto-submitting form. A bot follows links; it does not press buttons.
 *
 * THE SAME ANSWER FOR EVERY FAILURE
 *
 * Unknown token, revoked, expired, out of views - all four render the identical
 * "no longer available" page with the identical status. Telling them apart
 * would answer questions the holder of a bad link has no business asking:
 * whether a token was ever real, whether a share still exists, whether somebody
 * else has already opened it.
 *
 * HEADERS
 *
 * `X-Robots-Tag: noindex, nofollow` on both responses, because a link forwarded
 * into anything a crawler can reach must not end up in an index.
 * `Referrer-Policy: no-referrer` so that the token in the address bar is not
 * sent to whatever the recipient clicks next - a URL that IS the secret must
 * not travel in a Referer header. `Cache-Control: no-store` on the reveal for
 * the reason every reveal in this module carries it.
 *
 * TOTP
 *
 * A share that includes `totp` gets the CURRENT SIX-DIGIT CODE, computed here
 * at reveal time from the entry's seed. The seed itself is never shared and
 * there is no option to share it. A code is worth one thirty-second window; a
 * seed is a permanent second factor, and a second factor sitting in the same
 * message as the password it is supposed to be a second factor to is not a
 * second factor at all.
 */
class VaultPublicShareController extends \App\Http\Controllers\Controller
{
    public function __construct(private VaultOtpService $otp)
    {
    }

    /**
     * The neutral landing page. No secret material in this response, ever.
     */
    public function show(Request $request, string $token)
    {
        $share = $this->openShare($token);

        if ($share === null) {
            return $this->gone();
        }

        return $this->page([
            'state' => 'prompt',
            'token' => $token,
            'fields' => (array) ($share->fields_shared ?? []),
            'expires_at' => $share->expires_at,
            'views_left' => $share->max_views === null
                ? null
                : max(0, (int) $share->max_views - (int) $share->views),
        ]);
    }

    /**
     * Spend one view and hand the fields over.
     *
     * The order here is load-bearing. `spend()` runs BEFORE anything is
     * decrypted, and its return value is the only thing that authorises the
     * read - so two simultaneous clicks on a one-view link race inside a single
     * UPDATE and exactly one of them wins. Checking first and incrementing
     * afterwards would let both through, which on a one-view link is the whole
     * guarantee gone.
     */
    public function reveal(Request $request, string $token)
    {
        $share = $this->openShare($token);

        if ($share === null || ! $share->spend()) {
            return $this->gone();
        }

        // Re-read the entry rather than trusting the one loaded for the
        // advisory check: this is the moment the values are decided, and
        // "live, at reveal time" has to mean this line.
        $entry = VaultEntry::withTrashed()->find($share->vault_entry_id);

        if ($entry === null || $entry->deleted_at !== null) {
            // The view is spent and that is correct - it was a real click on a
            // real link. There is simply nothing behind it any more.
            return $this->gone();
        }

        // The recipient's IP and user agent, against the CRM account that
        // created the link. See VaultShareController's docblock for why the
        // user on this row is the creator.
        VaultAccessLog::recordAs(
            $entry,
            'share_view',
            $request,
            $share->created_by_user_id
        );

        return $this->page([
            'state' => 'revealed',
            'token' => $token,
            'fields' => (array) ($share->fields_shared ?? []),
            'values' => $this->values($share, $entry),
            'expires_at' => $share->expires_at,
            'views_left' => $share->max_views === null
                ? null
                : max(0, (int) $share->max_views - (int) $share->views),
        ])->header('Pragma', 'no-cache');
    }

    /* ---------------------------------------------------------------------
     | Internals
     | ------------------------------------------------------------------- */

    /**
     * The share behind this token, if it would open - otherwise null.
     *
     * Advisory. Nothing is handed out on the strength of it; `spend()` decides.
     * It exists so that the landing page can say "no longer available" without
     * making the recipient press a button first, and it collapses all four
     * failure modes into one null so that no caller can accidentally tell them
     * apart.
     */
    private function openShare(string $token): ?VaultShare
    {
        $share = VaultShare::findByToken($token);

        if ($share === null || ! $share->isOpen()) {
            return null;
        }

        // A soft-deleted credential closes its links immediately. The FK
        // cascade only covers a hard delete, and the vault does not do those.
        $entry = VaultEntry::query()->find($share->vault_entry_id);

        return $entry === null ? null : $share;
    }

    /**
     * The values this share exposes, read live off the entry.
     *
     * Built by walking the STORED field list, not anything from the request:
     * what a link exposes was decided when it was created and cannot be
     * widened by whoever is holding it.
     *
     * @return array<string, string>
     */
    private function values(VaultShare $share, VaultEntry $entry): array
    {
        $values = [];

        foreach (VaultShare::FIELDS as $field) {
            if (! $share->shares($field)) {
                continue;
            }

            if ($field === 'totp') {
                // A seed that no longer generates is a broken entry, not a
                // broken link: the rest of the fields still go out and the
                // code is simply absent, which is a far better outcome for the
                // recipient than a 500 on the whole page.
                try {
                    $values['totp'] = $this->otp->currentCode($entry)['code'];
                } catch (InvalidArgumentException | \Throwable $e) {
                    // Deliberately swallowed; see above.
                }

                continue;
            }

            $value = trim((string) ($entry->{$field} ?? ''));

            if ($value !== '') {
                $values[$field] = $value;
            }
        }

        return $values;
    }

    /**
     * The one response every failure gets.
     *
     * 404 rather than 410: "gone" asserts the resource once existed, which for
     * a token that was never real is a small piece of information this endpoint
     * has no reason to give away.
     */
    private function gone()
    {
        return $this->page(['state' => 'gone'], 404);
    }

    /**
     * @param  array<string, mixed>  $data
     * @return \Illuminate\Http\Response
     */
    private function page(array $data, int $status = 200)
    {
        $view = (string) config(
            'visns-packages.vault.share.view',
            'visns-packages::vault.share'
        );

        return response()
            ->view($view, $data + [
                'state' => 'gone',
                'token' => '',
                'fields' => [],
                'values' => [],
                'expires_at' => null,
                'views_left' => null,
                'brand' => (string) config('app.name', 'Credential share'),
                'postUrl' => VaultShareController::publicUrl(
                    (string) ($data['token'] ?? '')
                ),
            ], $status)
            // A link forwarded into anything a crawler can reach must not be
            // indexed, and the URL is the secret so it must not leak as a
            // Referer either.
            ->header('X-Robots-Tag', 'noindex, nofollow, noarchive')
            ->header('Referrer-Policy', 'no-referrer')
            ->header('X-Frame-Options', 'DENY')
            ->header('X-Content-Type-Options', 'nosniff')
            // On EVERY state, not just the reveal. The landing page carries a
            // CSRF token bound to a session, and a cached copy of it - in a
            // corporate proxy, in the browser's own back/forward cache - hands
            // the recipient a stale token and a 419 on the one button the page
            // has.
            ->header('Cache-Control', 'no-store, no-cache, must-revalidate, private');
    }
}
