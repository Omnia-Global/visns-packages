<?php

namespace Visnsstudio\VisnsPackages\Controllers;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

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

            // Return JSON response
            return response()->json($userArray);
        }
    }
}
