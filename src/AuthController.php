<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;

use App\Mail\GenericMail;

use App\Models\User;

use Carbon\Carbon;

class AuthController extends Controller
{
	public function forgot(Request $request)
	{
		$error = "";

		$request->validate(["email" => "required|email"]);

		$checkUser = User::where("email", $request->only("email"))->count();

		if ($checkUser > 0) {
			$token = Str::random(60);

			DB::table("password_resets")->insert([
				"email" => $request->email,
				"token" => $token,
				"created_at" => Carbon::now(),
			]);

			if (env("APP_ENV") == "production") {
				$to = $request->only("email");
			} else {
				$to = env("MAIL_TO_DEV");
			}

			if ($to != "") {
				$content =
					'<p>Click on the following <a href="' .
					env("APP_URL") .
					"/verify/" .
					$token .
					'">link</a> to reset your password.</p>';

				$content .=
					"<p>If you have not requested for a password reset, please ignore this email.</p>";

				Mail::to($to)->send(
					new GenericMail(
						$content,
						env("MAIL_FROM_ADDRESS"),
						env("APP_NAME") . " - Password Reset Request"
					)
				);
			}
		} else {
			$error = "The email address is not found, please try again.";
		}

		return response()->json([
			"error" => $error,
		]);
	}

	public function reset(Request $request)
	{
		$error = "";

		$request->validate([
			"code" => "required",
			"password" => "required|min:8|confirmed",
		]);

		$checkToken = DB::table("password_resets")
			->where("token", $request->input("code"))
			->count();

		if ($checkToken == 0) {
			$error =
				"The token is no longer valid, please start the password request process again.";
		} else {
			$token = DB::table("password_resets")
				->where("token", $request->input("code"))
				->first();

			$user = User::where("email", $token->email)->first();
			$user->password = Hash::make($request->input("password"));
			$user->save();

			DB::table("password_resets")
				->where("email", $user->email)
				->delete();
		}

		return response()->json([
			"error" => $error,
		]);
	}

	public function authenticate(Request $request)
	{
		$error = "";

		// Determine if the input is an email address or a username
		$isEmail =
			filter_var($request->input("email"), FILTER_VALIDATE_EMAIL) !==
			false;

		// Prepare credentials based on the input type
		$credentials = $isEmail
			? [
				"email" => $request->input("email"),
				"password" => $request->input("password"),
			]
			: [
				"username" => $request->input("email"),
				"password" => $request->input("password"),
			];

		$user = "";

		if (Auth::attempt($credentials)) {
			Auth::logoutOtherDevices($request->input("password"));
			$user = Auth::user()->load("roles.permissions");
		} else {
			$error = "Login unsuccessful, please try again.";
			$request->session()->flash("errors");
		}

		return response()->json([
			"error" => $error,
			"previous" => $request->input("location"),
			"user" => $user,
		]);
	}

	public function logout(Request $request)
	{
		Auth::logout();

		$request->session()->invalidate();

		$request->session()->regenerateToken();

		return redirect("/login");
	}

	public function login_api(Request $request)
	{
		$username = $request->input("username");
		$password = $request->input("password");

		if (Auth::attempt(["email" => $username, "password" => $password])) {
			$user = Auth::user();
			$token = $user->createToken("authToken");
			return response()->json(["id" => $token->plainTextToken], 200);
		} else {
			return response()->json(["error" => "Unauthenticated"], 401);
		}
	}
}
