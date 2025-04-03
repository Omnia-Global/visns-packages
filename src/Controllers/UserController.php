<?php

namespace Visnsstudio\VisnsPackages\Controllers;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

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

        // Verify password before enabling 2FA
        if (!Hash::check($request->password, $user->password)) {
            throw ValidationException::withMessages([
                'password' => ['The provided password is incorrect.'],
            ]);
        }

        // Check if the user model supports 2FA
        if (!method_exists($user, 'generateTwoFactorSecret')) {
            return response()->json(
                [
                    'error' =>
                        'Two-factor authentication is not supported for this user.',
                ],
                400
            );
        }

        // Generate the secret key
        $user->generateTwoFactorSecret();
        $user->save();

        // Get the QR code SVG and recovery codes
        $qrCodeSvg = null;
        $recoveryCodes = null;

        if (method_exists($user, 'twoFactorQrCodeSvg')) {
            $qrCodeSvg = $user->twoFactorQrCodeSvg();
        }

        if (method_exists($user, 'recoveryCodes')) {
            $recoveryCodes = $user->recoveryCodes();
        }

        return response()->json([
            'two_factor_enabled' => true,
            'two_factor_confirmed' => false,
            'qr_code' => $qrCodeSvg,
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

        // Check if the user model supports 2FA
        if (!method_exists($user, 'validateTwoFactorCode')) {
            return response()->json(
                [
                    'error' =>
                        'Two-factor authentication is not supported for this user.',
                ],
                400
            );
        }

        // Verify the code
        if (!$user->validateTwoFactorCode($request->code)) {
            throw ValidationException::withMessages([
                'code' => [
                    'The provided two-factor authentication code was invalid.',
                ],
            ]);
        }

        // Mark 2FA as confirmed
        if (method_exists($user, 'confirmTwoFactorAuth')) {
            $user->confirmTwoFactorAuth();
        } else {
            // Fallback if the method doesn't exist
            $user->two_factor_confirmed_at = now();
            $user->save();
        }

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

        // Verify password before disabling 2FA
        if (!Hash::check($request->password, $user->password)) {
            throw ValidationException::withMessages([
                'password' => ['The provided password is incorrect.'],
            ]);
        }

        // Check if the user model supports 2FA
        if (!method_exists($user, 'two_factor_secret')) {
            return response()->json(
                [
                    'error' =>
                        'Two-factor authentication is not supported for this user.',
                ],
                400
            );
        }

        // Disable 2FA
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

        // Check if the user model supports 2FA
        if (!method_exists($user, 'generateNewRecoveryCodes')) {
            return response()->json(
                [
                    'error' =>
                        'Two-factor authentication is not supported for this user.',
                ],
                400
            );
        }

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
        $user->generateNewRecoveryCodes();

        return response()->json([
            'recovery_codes' => $user->recoveryCodes(),
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

        // Check if the user model supports 2FA
        if (!method_exists($user, 'two_factor_secret')) {
            return response()->json([
                'two_factor_supported' => false,
            ]);
        }

        return response()->json([
            'two_factor_supported' => true,
            'two_factor_enabled' => !is_null($user->two_factor_secret),
            'two_factor_confirmed' => !is_null($user->two_factor_confirmed_at),
        ]);
    }
}
