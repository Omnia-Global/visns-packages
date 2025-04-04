<?php

namespace Visnsstudio\VisnsPackages\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

use Visnsstudio\VisnsPackages\Models\Audit;

class AuditController extends \App\Http\Controllers\Controller
{
    public function show($id)
    {
        $data = Audit::with('user')->find($id);

        return response()->json($data);
    }

    public function table(Request $request)
    {
        $data = Audit::with('user')->orderBy('created_at', 'desc');

        if ($request->has('where') && $request->filled('where')) {
            foreach ($request->input('where') as $a => $b) {
                $data->where($b['id'], $b['value']);
            }
        }

        $data = $data->paginate(
            $request->input('take') ? $request->input('take') : 10
        );

        return response()->json($data);
    }
}
