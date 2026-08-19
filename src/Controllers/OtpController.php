<?php

namespace Visnsstudio\VisnsPackages\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;

use Visnsstudio\VisnsPackages\Contracts\OtpContactResolver;
use Visnsstudio\VisnsPackages\Contracts\OtpSender;
use Visnsstudio\VisnsPackages\Otp\DefaultOtpContactResolver;
use Visnsstudio\VisnsPackages\Otp\LogOtpSender;
use Visnsstudio\VisnsPackages\Support\MinimalUserPayload;
use Visnsstudio\VisnsPackages\Support\ModuleConfig;
use Visnsstudio\VisnsPackages\Support\Relations;

use Carbon\Carbon;

/**
 * Passwordless login: a contact detail is exchanged for a one-time code, and
 * the code for a Sanctum token.
 *
 * Both endpoints are unauthenticated, which is why the shapes here are so
 * deliberate:
 *
 *  - the code is only ever stored hashed, and only ever compared with
 *    Hash::check(), so a database read cannot recover a live code;
 *  - the "we sent it to..." line returns a masked contact, never the address;
 *  - the plaintext code leaves the server in the response body only outside
 *    production, and only when the application opts in
 *    (otp.expose_code_outside_production), so a staging login is possible
 *    without a live SMS gateway;
 *  - every failure path answers with a configured, generic message.
 */
class OtpController extends \App\Http\Controllers\Controller
{
    /**
     * Issue a one-time code to the contact detail supplied.
     */
    public function requestOtp(Request $request)
    {
        try {
            $request->validate([
                'contact' => 'required|string',
            ]);

            $contactInfo = $request->input('contact');

            // Step 1: resolve the raw contact string into the record the code
            // is stored against. Which fields are searched is entirely the
            // application's business (the CRM searches company_contact on
            // email1/email2/username/mobile); the package only needs a record.
            $resolver = $this->resolver();
            $contact = $resolver($contactInfo);

            if (! $contact) {
                return response()->json([
                    'error' => ModuleConfig::message('otp', 'contact_not_found'),
                ], 404);
            }

            // Step 2: the contact must have a portal account to log in to.
            $user = ModuleConfig::userQuery('otp')
                ->where(ModuleConfig::get('otp.user_foreign_key', 'company_contact_id'), $contact->id)
                ->first();

            if (! $user) {
                return response()->json([
                    'error' => ModuleConfig::message('otp', 'no_portal_access'),
                ], 403);
            }

            // Step 3: rate limiting.
            if ($this->isRateLimited($contact)) {
                return response()->json([
                    'error' => ModuleConfig::message('otp', 'rate_limited'),
                ], 429);
            }

            // Step 4: which contact field matched, in the vocabulary the
            // application's sender understands.
            $contactMethod = $resolver->matchedMethod($contact, $contactInfo);

            // Step 5: generate the code.
            $otpCode = $this->generateCode();

            // Step 6: store it against the contact record.
            //
            // Direct property assignment plus save(), never update(): the
            // columns are not necessarily fillable on the application's model,
            // and update() would silently drop them.
            $columns = $this->columns();

            $contact->{$columns['code']} = Hash::make($otpCode);
            $contact->{$columns['sent_at']} = now();
            $contact->{$columns['contact_method']} = $contactMethod;
            $contact->{$columns['attempts']} = 0;
            $contact->save();

            $maskedContact = $resolver->maskedContact($contact, $contactMethod);

            // Step 7: deliver it. Outside production the code can be handed
            // straight back instead, so staging needs no SMS gateway; an
            // application that wants staging to behave like production turns
            // expose_code_outside_production off.
            $exposeCode = ! app()->environment('production')
                && (bool) ModuleConfig::get('otp.expose_code_outside_production', true);

            if (! $exposeCode) {
                $this->sender()->send($contact, $contactMethod, $otpCode);

                return response()->json([
                    'error' => '',
                    'success' => true,
                    'message' => ModuleConfig::message('otp', 'sent'),
                    'contact_method' => $contactMethod,
                    'masked_contact' => $maskedContact,
                ]);
            }

            return response()->json([
                'error' => '',
                'success' => true,
                'message' => ModuleConfig::message('otp', 'generated'),
                'contact_method' => $contactMethod,
                'masked_contact' => $maskedContact,
                'dev_otp' => $otpCode,
            ]);
        } catch (\Throwable $e) {
            Log::error('Exception during OTP request', [
                'message' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'error' => ModuleConfig::message('otp', 'request_failed'),
            ], 500);
        }
    }

    /**
     * Exchange a valid code for an API token.
     */
    public function loginOtp(Request $request)
    {
        try {
            // Inside the try on purpose. A ValidationException raised here is
            // caught by the handler at the bottom and answered as the generic
            // 500 failure, NOT as Laravel's 422 field error - which is what the
            // controller this was ported from does, and what the portal front
            // end reads in production today. It is a wart, and it is contract:
            // moving the validate() call outside the try would change the
            // status and body a mistyped code produces.
            $request->validate([
                'contact' => 'required|string',
                'otp_code' => 'required|string|size:' . $this->codeLength(),
            ]);

            $contactInfo = $request->input('contact');
            $otpCode = $request->input('otp_code');

            // Step 1: same resolution as request-otp.
            $contact = ($this->resolver())($contactInfo);

            if (! $contact) {
                // 401 rather than the 404 request-otp answers with: at this
                // point the caller is presenting a credential, so every
                // failure looks the same from outside.
                return response()->json([
                    'error' => ModuleConfig::message('otp', 'contact_not_found'),
                ], 401);
            }

            // Step 2: validate the code (expiry, attempt ceiling, hash).
            if (! $this->validateCode($contact, $otpCode)) {
                return response()->json([
                    'error' => ModuleConfig::message('otp', 'invalid_code'),
                ], 401);
            }

            // Step 3: the portal account behind the contact.
            $userModel = ModuleConfig::userModel('otp');

            $user = ModuleConfig::userQuery('otp')
                ->where(ModuleConfig::get('otp.user_foreign_key', 'company_contact_id'), $contact->id)
                // Only the relations this model actually defines: the shipped
                // default names relations that exist in the applications this
                // grew out of, and eager-loading a missing one would throw into
                // the catch-all below and surface as a generic 500.
                ->with(Relations::supported(
                    new $userModel(),
                    (array) ModuleConfig::get('otp.user_relations', [])
                ))
                ->first();

            if (! $user) {
                return response()->json([
                    'error' => ModuleConfig::message('otp', 'no_portal_access_login'),
                ], 403);
            }

            // Step 4: clear the throttling state now the code has been spent.
            $columns = $this->columns();

            $contact->{$columns['attempts']} = 0;
            $contact->{$columns['locked_until']} = null;
            $contact->save();

            // Step 5: mint the token and stamp the login.
            $token = $user
                ->createToken(ModuleConfig::get('otp.token_name', 'auth_token'))
                ->plainTextToken;

            // Both stamps are application-schema specific, so each is written
            // only when the column is actually present on the user row. A
            // freshly retrieved model carries every table column in its
            // attribute bag, so array_key_exists() answers this for free -
            // unlike Schema::hasColumn(), which would cost an introspection
            // query on every single login.
            $attributes = $user->getAttributes();
            $ipColumn = ModuleConfig::get('auth.two_factor.ip_column', 'last_logged_ip_address');

            if (array_key_exists('dateLastLogged', $attributes)) {
                $user->dateLastLogged = Carbon::now();
            }

            if (is_string($ipColumn) && array_key_exists($ipColumn, $attributes)) {
                $user->{$ipColumn} = $request->ip();
            }

            $user->save();

            // The caller asks for the whitelisted payload when it intends to
            // keep the user in a cookie (4KB limit) - the full model does not
            // fit, and should not travel there anyway.
            $returnMinimal = $request->input('minimal_response', false);
            $userData = $returnMinimal
                ? MinimalUserPayload::build($user, 'otp')
                : $user;

            return response()->json([
                'error' => '',
                'access_token' => $token,
                'user' => $userData,
                // Flag telling the client the full profile is available via an
                // authenticated route.
                'full_data_available' => $returnMinimal,
            ]);
        } catch (\Throwable $e) {
            Log::error('Exception during OTP login', [
                'message' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'error' => ModuleConfig::message('otp', 'login_failed'),
            ], 500);
        }
    }

    /**
     * The configured contact resolver, falling back to the package default.
     */
    protected function resolver(): OtpContactResolver
    {
        return ModuleConfig::service(
            'otp.contact_resolver',
            OtpContactResolver::class,
            DefaultOtpContactResolver::class
        );
    }

    /**
     * The configured sender, falling back to the fail-visible logging sender.
     */
    protected function sender(): OtpSender
    {
        return ModuleConfig::service(
            'otp.sender',
            OtpSender::class,
            LogOtpSender::class
        );
    }

    /**
     * The column map, with the package defaults filled in so an application
     * overriding one key does not have to restate the rest.
     *
     * @return array<string, string>
     */
    protected function columns(): array
    {
        return array_merge([
            'code' => 'otp_code',
            'sent_at' => 'otp_sent_at',
            'contact_method' => 'otp_contact_method',
            'attempts' => 'otp_attempts',
            'locked_until' => 'otp_locked_until',
        ], (array) ModuleConfig::get('otp.columns', []));
    }

    /**
     * Number of digits in a code. Clamped to what random_int() can express
     * exactly, so a nonsense config cannot produce a code that is not uniform.
     */
    protected function codeLength(): int
    {
        return max(1, min(9, (int) ModuleConfig::get('otp.code_length', 6)));
    }

    /**
     * A zero-padded numeric code drawn from the CSPRNG.
     *
     * random_int(), never rand()/mt_rand(): this value is a credential, and a
     * predictable PRNG makes it guessable from a couple of observed codes.
     */
    protected function generateCode(): string
    {
        $length = $this->codeLength();

        return str_pad(
            (string) random_int(0, (10 ** $length) - 1),
            $length,
            '0',
            STR_PAD_LEFT
        );
    }

    /**
     * Is the submitted code the live one?
     *
     * Returns false - never an exception, never a distinguishing message - for
     * every kind of failure, so a caller cannot tell "expired" from "wrong"
     * from "too many tries".
     */
    protected function validateCode(object $contact, string $input): bool
    {
        $columns = $this->columns();

        $stored = $contact->{$columns['code']} ?? null;
        $sentAt = $this->asDate($contact->{$columns['sent_at']} ?? null);

        if (! $stored || ! $sentAt) {
            return false;
        }

        $expiryMinutes = (int) ModuleConfig::get('otp.expiry_minutes', 5);

        if ($sentAt->copy()->addMinutes($expiryMinutes)->isPast()) {
            return false;
        }

        // Attempt ceiling: without it the code is brute-forceable inside its
        // expiry window.
        $maxAttempts = (int) ModuleConfig::get('otp.max_attempts', 3);

        if ((int) ($contact->{$columns['attempts']} ?? 0) >= $maxAttempts) {
            return false;
        }

        if (Hash::check($input, $stored)) {
            return true;
        }

        $contact->increment($columns['attempts']);

        return false;
    }

    /**
     * Is this contact currently barred from requesting another code?
     */
    protected function isRateLimited(object $contact): bool
    {
        $columns = $this->columns();

        // Hard lock, set by the application when it decides a contact has had
        // enough.
        $lockedUntil = $this->asDate($contact->{$columns['locked_until']} ?? null);

        if ($lockedUntil && $lockedUntil->isFuture()) {
            return true;
        }

        $sentAt = $this->asDate($contact->{$columns['sent_at']} ?? null);

        if ($sentAt) {
            $window = (int) ModuleConfig::get('otp.rate_limit_window_minutes', 15);
            $cooldown = (int) ModuleConfig::get('otp.resend_cooldown_minutes', 2);

            // The outer window check is vestigial: any moment inside the
            // cooldown is also inside the (longer) window, so the inner test
            // decides every case. It is kept because it is what runs in
            // production today, and because an application shortening the
            // window below the cooldown would change behaviour if it were
            // removed.
            if ($sentAt->isAfter(now()->subMinutes($window))) {
                if ($sentAt->isAfter(now()->subMinutes($cooldown))) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Coerce a stored timestamp to Carbon.
     *
     * Applications normally cast these columns to datetime on the model; this
     * only covers a contact record that does not, so date maths cannot fatal
     * on a raw string.
     */
    protected function asDate($value): ?Carbon
    {
        if ($value === null || $value === '') {
            return null;
        }

        if ($value instanceof \DateTimeInterface) {
            return Carbon::instance($value);
        }

        try {
            return Carbon::parse((string) $value);
        } catch (\Throwable $e) {
            return null;
        }
    }
}
