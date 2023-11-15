<?php

namespace Visnsstudio\VisnsPackages;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

use Spatie\Permission\Models\Role;

class UserController extends \App\Http\Controllers\Controller
{
	public function notifications(Request $request)
	{
		return response()->json([
			"data" => auth()->user()->unreadNotifications,
		]);
	}

	public function notificationTable(Request $request)
	{
		$data = auth()
			->user()
			->notifications()
			->fastPaginate(10);

		return response()->json($data);
	}

	public function markAsRead(Request $request)
	{
		foreach (auth()->user()->unreadNotifications as $item) {
			if ($item->id == $request->input("id")) {
				$item->markAsRead();
			}
		}

		return response()->json([
			"success" => true,
		]);
	}

	public function profile()
	{
		$user = Auth::user();

		return response()->json($user->load("roles.permissions"));
	}
}
