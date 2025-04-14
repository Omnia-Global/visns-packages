<?php

namespace Visnsstudio\VisnsPackages\Controllers;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use Illuminate\Support\Str;
use PragmaRX\Google2FA\Google2FA;
use BaconQrCode\Renderer\ImageRenderer;
use BaconQrCode\Renderer\Image\SvgImageBackEnd;
use BaconQrCode\Renderer\RendererStyle\RendererStyle;
use BaconQrCode\Writer;

use Spatie\Permission\Models\Role;

class UserController extends \App\Http\Controllers\Controller
{
    public function notifications(Request $request)
    {
        return response()->json([
            'data' => auth()->user()->unreadNotifications,
        ]);
    }

    public function notificationTable(Request $request)
    {
        $data = auth()
            ->user()
            ->notifications()
            ->paginate(10);

        return response()->json($data);
    }

    public function markAsRead(Request $request)
    {
        foreach (auth()->user()->unreadNotifications as $item) {
            if ($item->id == $request->input('id')) {
                $item->markAsRead();
            }
        }

        return response()->json([
            'success' => true,
        ]);
    }

    public function profile()
    {
        $user = Auth::user();

        if (!$user) {
            return response()->json(['error' => ''], 200);
        } else {
            $model = new User();

            if (method_exists($model, 'loadableRelations')) {
                $user->load($model->loadableRelations());
            }

            // Convert the user object to an array
            $userArray = $user->toArray();

            // Add 2FA status if the user model supports it
            if (method_exists($user, 'two_factor_secret')) {
                $userArray['two_factor_enabled'] = !is_null(
                    $user->two_factor_secret
                );
            }

            // Return JSON response
            return response()->json($userArray);
        }
    }

    /**
     * Enable two-factor authentication for the user.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\Response
     */
    public function enableTwoFactorAuth(Request $request)
    {
        $user = Auth::user();

        // Generate a new secret key
        $google2fa = new Google2FA();
        $secretKey = $google2fa->generateSecretKey();

        // Store the secret key in the session for now (until confirmed)
        session(['2fa_secret' => $secretKey]);

        // Generate QR code
        $qrCodeUrl = $google2fa->getQRCodeUrl(
            config(
                'visns-packages.2fa_app_name',
                env('APP_NAME', config('app.name'))
            ),
            $user->email,
            $secretKey
        );

        // Convert QR code URL to SVG
        $renderer = new ImageRenderer(
            new RendererStyle(200),
            new SvgImageBackEnd()
        );
        $writer = new Writer($renderer);
        $qrCodeSvg = $writer->writeString($qrCodeUrl);

        // Generate recovery codes
        $recoveryCodes = [];
        for ($i = 0; $i < 8; $i++) {
            $recoveryCodes[] = Str::random(10);
        }

        // Store recovery codes in session for now
        session(['2fa_recovery_codes' => $recoveryCodes]);

        return response()->json([
            'two_factor_enabled' => true,
            'two_factor_confirmed' => false,
            'qr_code' => $qrCodeSvg,
            'secret_key' => $secretKey, // Include the secret key for manual entry
            'recovery_codes' => $recoveryCodes,
        ]);
    }

    /**
     * Confirm two-factor authentication for the user.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\Response
     */
    public function confirmTwoFactorAuth(Request $request)
    {
        $request->validate([
            'code' => 'required|string',
        ]);

        $user = Auth::user();
        $secretKey = session('2fa_secret');
        $recoveryCodes = session('2fa_recovery_codes', []);

        if (!$secretKey) {
            return response()->json(
                [
                    'error' =>
                        'No two-factor authentication setup in progress.',
                ],
                400
            );
        }

        // Verify the code
        $google2fa = new Google2FA();
        $valid = $google2fa->verifyKey($secretKey, $request->code);

        if (!$valid) {
            throw ValidationException::withMessages([
                'code' => [
                    'The provided two-factor authentication code was invalid.',
                ],
            ]);
        }

        // Save the 2FA settings to the user
        $user->two_factor_secret = encrypt($secretKey);
        $user->two_factor_recovery_codes = encrypt(json_encode($recoveryCodes));
        $user->two_factor_confirmed_at = now();
        $user->save();

        // Clear the session data
        session()->forget(['2fa_secret', '2fa_recovery_codes']);

        return response()->json([
            'two_factor_enabled' => true,
            'two_factor_confirmed' => true,
        ]);
    }

    /**
     * Disable two-factor authentication for the user.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\Response
     */
    public function disableTwoFactorAuth(Request $request)
    {
        $user = Auth::user();

        // Disable 2FA by clearing all related fields
        $user->two_factor_secret = null;
        $user->two_factor_recovery_codes = null;
        $user->two_factor_confirmed_at = null;
        $user->save();

        return response()->json([
            'two_factor_enabled' => false,
        ]);
    }

    /**
     * Generate new recovery codes for the user.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\Response
     */
    public function regenerateRecoveryCodes(Request $request)
    {
        $user = Auth::user();

        // Check if 2FA is enabled
        if (!$user->two_factor_secret) {
            return response()->json(
                [
                    'error' =>
                        'Two-factor authentication is not enabled for this user.',
                ],
                400
            );
        }

        // Generate new recovery codes
        $recoveryCodes = [];
        for ($i = 0; $i < 8; $i++) {
            $recoveryCodes[] = Str::random(10);
        }

        // Save the new recovery codes
        $user->two_factor_recovery_codes = encrypt(json_encode($recoveryCodes));
        $user->save();

        return response()->json([
            'recovery_codes' => $recoveryCodes,
        ]);
    }

    /**
     * Get the two-factor authentication status for the user.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\Response
     */
    public function getTwoFactorStatus(Request $request)
    {
        $user = Auth::user();
        $twoFactorEnabled = !is_null($user->two_factor_secret);
        $twoFactorConfirmed = !is_null($user->two_factor_confirmed_at);

        $response = [
            'two_factor_supported' => true,
            'two_factor_enabled' => $twoFactorEnabled,
            'two_factor_confirmed' => $twoFactorConfirmed,
        ];

        // If 2FA is enabled and confirmed, include the secret key for manual setup
        if (
            $twoFactorEnabled &&
            $twoFactorConfirmed &&
            $request->has('include_secret')
        ) {
            $secretKey = decrypt($user->two_factor_secret);
            $response['secret_key'] = $secretKey;

            // Generate QR code URL if requested
            if ($request->has('include_qr')) {
                $google2fa = new Google2FA();
                $qrCodeUrl = $google2fa->getQRCodeUrl(
                    config(
                        'visns-packages.2fa_app_name',
                        env('APP_NAME', config('app.name'))
                    ),
                    $user->email,
                    $secretKey
                );

                // Convert QR code URL to SVG
                $renderer = new ImageRenderer(
                    new RendererStyle(200),
                    new SvgImageBackEnd()
                );
                $writer = new Writer($renderer);
                $response['qr_code'] = $writer->writeString($qrCodeUrl);
            }
        }

        return response()->json($response);
    }

    /**
     * Authenticate with a two-factor authentication code.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\Response
     */
    public function authenticateTwoFactor(Request $request)
    {
        $request->validate([
            'user_id' => 'required|exists:users,id',
            'code' => 'required|string',
        ]);

        $user = User::find($request->user_id);

        if (!$user) {
            return response()->json(['error' => 'User not found.'], 401);
        }

        if ($this->validateTwoFactorCode($user, $request->code)) {
            // Log the user in
            Auth::login($user);

            return response()->json([
                'success' => true,
                'user' => $user->load('roles.permissions'),
            ]);
        }

        return response()->json(
            [
                'error' => 'Invalid authentication code.',
            ],
            401
        );
    }

    /**
     * Authenticate with a two-factor authentication code for API access.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\Response
     */
    public function authenticateTwoFactorApi(Request $request)
    {
        $request->validate([
            'user_id' => 'required|exists:users,id',
            'code' => 'required|string',
        ]);

        $user = User::find($request->user_id);

        if (!$user) {
            return response()->json(['error' => 'User not found.'], 401);
        }

        if ($this->validateTwoFactorCode($user, $request->code)) {
            // Create a token for API access
            $token = $user->createToken('authToken');

            return response()->json(
                [
                    'id' => $token->plainTextToken,
                ],
                200
            );
        }

        return response()->json(
            [
                'error' => 'Invalid authentication code.',
            ],
            401
        );
    }

    /**
     * Validate a two-factor authentication code.
     *
     * @param  \App\Models\User  $user
     * @param  string  $code
     * @return bool
     */
    public function validateTwoFactorCode(User $user, string $code)
    {
        if (!$user->two_factor_secret) {
            return false;
        }

        // Check if it's a recovery code
        if (strlen($code) > 6) {
            return $this->validateTwoFactorRecoveryCode($user, $code);
        }

        // Decrypt the secret key
        $secretKey = decrypt($user->two_factor_secret);

        // Verify the code
        $google2fa = new Google2FA();
        return $google2fa->verifyKey($secretKey, $code);
    }

    /**
     * Validate a two-factor authentication recovery code.
     *
     * @param  \App\Models\User  $user
     * @param  string  $code
     * @return bool
     */
    public function validateTwoFactorRecoveryCode(User $user, string $code)
    {
        if (!$user->two_factor_recovery_codes) {
            return false;
        }

        // Decrypt and decode recovery codes
        $recoveryCodes = json_decode(
            decrypt($user->two_factor_recovery_codes),
            true
        );

        // Find the matching recovery code
        $key = array_search($code, $recoveryCodes);

        if ($key !== false) {
            // Remove the used recovery code
            unset($recoveryCodes[$key]);

            // Re-index the array
            $recoveryCodes = array_values($recoveryCodes);

            // Save the updated recovery codes
            $user->two_factor_recovery_codes = encrypt(
                json_encode($recoveryCodes)
            );
            $user->save();

            return true;
        }

        return false;
    }
}
