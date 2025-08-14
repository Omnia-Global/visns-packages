<?php

namespace Visnsstudio\VisnsPackages\Controllers;

use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Illuminate\Http\Request;

class RoleController extends \App\Http\Controllers\Controller
{
    public function dropdown(Request $request)
    {
        $data = [];

        $query = Role::orderBy('name', 'asc');

        if ($request->has('where') && $request->filled('where')) {
            foreach ($request->input('where') as $condition) {
                $query->where($condition['id'], $condition['value']);
            }
        }

        foreach ($query->get(['id', 'name']) as $item) {
            array_push($data, [
                'id' =>
                    $request->has('useIdField') &&
                    $request->input('useIdField') == true
                        ? $item->id
                        : $item->name,
                'label' => $item->name,
            ]);
        }

        $results = [
            'data' => $data,
        ];

        return response()->json($results);
    }

    public function show($id)
    {
        $role = Role::find($id);

        return response()->json($role->load('permissions'));
    }

    public function table(Request $request)
    {
        $data = Role::with('permissions')
            ->orderBy($request->input('sortBy'), $request->input('sort'))
            ->where('name', 'like', '%' . $request->input('search') . '%');

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

        $role = new Role();

        if ($request->has('name')) {
            $role->name = $request->input('name');
        }

        $role->save();

        if ($request->has('permissions')) {
            $permissions = $request->input('permissions');
            
            // Handle new format: array of permission objects
            if (is_array($permissions) && !empty($permissions) && isset($permissions[0]['id'])) {
                // Get permission IDs from the array
                $permissionIds = array_column($permissions, 'id');
                
                // Sync permissions: remove all current permissions and add the new ones
                $role->permissions()->detach();
                $role->permissions()->attach($permissionIds);
            } 
            // Handle old format: permission-{id}: boolean
            else {
                foreach ($permissions as $a => $b) {
                    $permission = Permission::find(
                        str_replace('permission-', '', $a)
                    );
                    if ($b === true) {
                        $role->givePermissionTo($permission);
                    } else {
                        $role->revokePermissionTo($permission);
                    }
                }
            }
        }

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

        $role = Role::find($id);

        if ($request->has('name')) {
            $role->name = $request->input('name');
        }

        $role->save();

        if ($request->has('permissions')) {
            $permissions = $request->input('permissions');
            
            // Handle new format: array of permission objects
            if (is_array($permissions) && !empty($permissions) && isset($permissions[0]['id'])) {
                // Get permission IDs from the array
                $permissionIds = array_column($permissions, 'id');
                
                // Sync permissions: remove all current permissions and add the new ones
                $role->permissions()->detach();
                $role->permissions()->attach($permissionIds);
            } 
            // Handle old format: permission-{id}: boolean
            else {
                foreach ($permissions as $a => $b) {
                    $permission = Permission::find(
                        str_replace('permission-', '', $a)
                    );
                    if ($b === true) {
                        $role->givePermissionTo($permission);
                    } else {
                        $role->revokePermissionTo($permission);
                    }
                }
            }
        }

        return response()->json([
            'error' => $error,
        ]);
    }

    public function destroy($id)
    {
        $error = '';

        $role = Role::find($id);
        $role->delete();

        return response()->json([
            'error' => $error,
        ]);
    }
}
