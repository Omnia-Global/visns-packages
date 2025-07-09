<?php

namespace Visnsstudio\VisnsPackages\Controllers;

use Spatie\Permission\Models\Permission;
use Illuminate\Http\Request;

class PermissionController extends \App\Http\Controllers\Controller
{
    public function dropdown(Request $request)
    {
        $data = [];

        $query = Permission::orderBy('name', 'asc');

        if ($request->input('id')) {
            $query->where($request->input('filter'), $request->input('id'));
        }

        foreach ($query->get(['id', 'name']) as $item) {
            array_push($data, [
                'id' => $item->id,
                'label' => $item->name,
                'key' => $item->id,
            ]);
        }

        $results = [
            'data' => $data,
        ];

        return response()->json($results);
    }

    public function show($id)
    {
        $permission = Permission::find($id);

        return response()->json($permission);
    }

    public function table(Request $request)
    {
        $data = Permission::orderBy(
            $request->input('sortBy'),
            $request->input('sort')
        )->where('name', 'like', '%' . $request->input('search') . '%');

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

    public function store(Request $request)
    {
        $error = '';

        $request->validate([
            'name' => 'required',
        ]);

        $permission = new Permission();

        if ($request->has('name')) {
            $permission->name = $request->input('name');
        }

        $permission->save();

        return response()->json([
            'error' => $error,
        ]);
    }

    public function update(Request $request, $id)
    {
        $error = '';

        $request->validate([
            'name' => 'required',
        ]);

        $permission = Permission::find($id);

        if ($request->has('name')) {
            $permission->name = $request->input('name');
        }

        $permission->save();

        return response()->json([
            'error' => $error,
        ]);
    }

    public function destroy($id)
    {
        $error = '';

        $permission = Permission::find($id);
        $permission->delete();

        return response()->json([
            'error' => $error,
        ]);
    }
}
