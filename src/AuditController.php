<?php

namespace Visnsstudio\VisnsPackages;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class AuditController extends \App\Http\Controllers\Controller
{
    public function show($id)
    {
        $data = DB::table("audits")
            ->with("user")
            ->where("id", $id)
            ->first();

        return response()->json($data);
    }

    public function table(Request $request)
    {
        $data = DB::table("audits")->orderBy("created_at", "desc");

        if ($request->has("where") && $request->filled("where")) {
            foreach ($request->input("where") as $a => $b) {
                $data->where($b["id"], $b["value"]);
            }
        }

        $data = $data->paginate(
            $request->input("take") ? $request->input("take") : 10
        );

        return response()->json($data);
    }
}
