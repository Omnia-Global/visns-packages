<?php

namespace Visnsstudio\VisnsPackages;

use App\Models\File;

use Illuminate\Http\Request;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Storage;

use Carbon\Carbon;

class DynamicController extends \App\Http\Controllers\Controller
{
    protected function getModelInstance($model)
    {
        $modelName = Str::singular(ucfirst($model));
        $modelClass = "App\\Models\\$modelName";

        if (!class_exists($modelClass)) {
            throw new \Exception("Model class {$modelClass} does not exist.");
        }

        return app($modelClass);
    }

    public function dropdown(Request $request, $model)
    {
        $data = [];
        $modelInstance = $this->getModelInstance($model);

        // Determine the sorting field
        $sortField = 'label'; // default sorting field
        $fields = $request->input('fields', ['id', 'label']);
        if (count($fields) >= 2) {
            $sortField = $fields[1]; // use the second key if available
        }

        // Assuming a default ordering method if customOrder is not available
        $query = method_exists($modelInstance, 'scopeCustomOrder')
            ? $modelInstance::customOrder($sortField, 'asc')
            : $modelInstance::orderBy($sortField, 'asc');

        if ($request->has('where') && $request->filled('where')) {
            foreach ($request->input('where') as $condition) {
                $query->where($condition['id'], $condition['value']);
            }
        }

        // Fields to be retrieved, defaulting to ['id', 'label']
        foreach ($query->get($fields) as $item) {
            $itemData = [];

            // First key-value pair
            $firstKey = $fields[0];
            $itemData[$firstKey] = $item->{$firstKey};

            // Set 'label' to be the value of the second key in $fields
            $secondKey = $fields[1] ?? 'label'; // Default to 'label' if there is no second key
            $itemData['label'] = $item->{$secondKey};

            // Add remaining fields
            foreach ($fields as $key => $field) {
                if ($key > 1) {
                    $itemData[$field] = $item->{$field};
                }
            }

            array_push($data, $itemData);
        }


        return response()->json(['data' => $data], 200);
    }

    public function show($id, $model)
    {
        $modelInstance = $this->getModelInstance($model);
        $resource = $modelInstance::findOrFail($id);

        // Check if the model has defined loadable relations
        if (method_exists($modelInstance, 'loadableRelations')) {
            $resource->load($modelInstance->loadableRelations());
        }

        return response()->json($resource);
    }

    public function table(Request $request, $model)
    {
        $modelInstance = $this->getModelInstance($model);

        // Get array of casts on the model
        $casts = $modelInstance->getCasts();

        // Assuming the model has defined relationships and custom methods
        $query = $modelInstance::query();

        if (method_exists($modelInstance, 'loadableRelations')) {
            $query->with($modelInstance->loadableRelations());
        }

        // Custom ordering and searching methods
        if (method_exists($modelInstance, 'scopeCustomOrder')) {
            $query->customOrder(
                $request->input('sortBy'),
                $request->input('sort')
            );
        }
        if (method_exists($modelInstance, 'scopeCustomSearch')) {
            $query->customSearch($request->input('search'));
        }

        // Filtering
        if ($request->has('where') && $request->filled('where')) {
            foreach ($request->input('where') as $condition) {
                $value = $condition['value'];

                if (isset($casts[$condition['id']])) {
                    switch ($casts[$condition['id']]) {
                        case 'datetime':
                            $value = Carbon::createFromTimestamp(
                                strtotime($value)
                            );
                            break;
                        case 'date':
                            $value = Carbon::createFromTimestamp(
                                strtotime($value)
                            );
                            break;
                    }
                }

                if (isset($condition['operator'])) {
                    switch ($condition['operator']) {
                        case 'contains':
                            $query->where(
                                $condition['id'],
                                'like',
                                '%' . $value . '%'
                            );
                            break;
                        case 'inlist':
                            $query->whereIn($condition['id'], $value);
                            break;
                        case 'inrange':
                            $query->whereBetween($condition['id'], $value);
                            break;
                        default:
                            $query->where($condition['id'], $value);
                            break;
                    }
                } else {
                    $query->where($condition['id'], $value);
                }
            }
        }

        // Pagination
        $perPage = $request->input('take', 10);
        $data = $query->paginate($perPage);

        return response()->json($data, 200);
    }

    public function store(Request $request, $model = null)
    {
        $modelInstance = $this->getModelInstance($model);
        $error = '';

        // Validate the request based on the model's rules
        $validatedData = $request->validate(
            $modelInstance->validationRules('store', $request->all())
        );

        // Iterate over the $casts array of the model
        foreach ($modelInstance->getCasts() as $field => $type) {
            // Check if the field is cast as 'datetime' or 'date' and is present in the validated data
            if (
                ($type === 'datetime' || $type === 'date') &&
                isset($validatedData[$field])
            ) {
                // Convert the field using Carbon
                $validatedData[$field] = Carbon::createFromTimestamp(
                    strtotime($validatedData[$field])
                );
            }
        }

        // Create a new resource
        $resource = $modelInstance::create($validatedData);

        // Handle file upload if 'key' is present in the request
        if ($request->has('key') && $request->has('file_relationship')) {
            $relationshipMethod = $request->input('file_relationship');
            $unique_name =
                $request->input('uuid') . '.' . $request->input('extension');
            $path = $model . '/' . $unique_name;

            Storage::copy(
                $request->input('key'),
                str_replace(
                    'tmp/',
                    $model . '/',
                    $request->input('key')
                ) .
                    '.' .
                    $request->input('extension')
            );

            $file = new File([
                'file_path' => $path,
                'file_name' => $request->input('filename'),
                'file_extension' => $request->input('extension'),
                'file_size' => $request->input('filesize'),
            ]);

            // Dynamically attach the file to the resource
            $resource->$relationshipMethod()->save($file);
        }

        if ($model == 'users' && $request->has('role')) {
            $resource->assignRole($request->input('role'));
        }

        return response()->json(['data' => $resource ?? '', 'error' => $error ?? ''], $error == '' ? 200 : 400);
    }

    public function update(Request $request, $model = null, $id)
    {
        $modelInstance = $this->getModelInstance($model);
        $error = '';

        // Find the resource
        $resource = $modelInstance::findOrFail($id);

        // Validate the request based on the model's rules
        $validatedData = $request->validate(
            $modelInstance->validationRules('update', $request->all())
        );

        // Iterate over the $casts array of the model
        foreach ($modelInstance->getCasts() as $field => $type) {
            // Check if the field is cast as 'datetime' or 'date' and is present in the validated data
            if (
                ($type === 'datetime' || $type === 'date') &&
                isset($validatedData[$field])
            ) {
                // Convert the field using Carbon
                $validatedData[$field] = Carbon::createFromTimestamp(
                    strtotime($validatedData[$field])
                );
            }
        }

        // Update the resource
        $resource->update($validatedData);

        // Handle file upload if 'key' is present in the request
        if ($request->has('key') && $request->has('file_relationship')) {
            $relationshipMethod = $request->input('file_relationship');
            $unique_name =
                $request->input('uuid') . '.' . $request->input('extension');
            $path = $model . '/' . $unique_name;

            Storage::copy(
                $request->input('key'),
                str_replace(
                    'tmp/',
                    $model . '/',
                    $request->input('key')
                ) .
                    '.' .
                    $request->input('extension')
            );

            $file = new File([
                'file_path' => $path,
                'file_name' => $request->input('filename'),
                'file_extension' => $request->input('extension'),
                'file_size' => $request->input('filesize'),
            ]);

            // Dynamically attach the file to the resource
            $resource->$relationshipMethod()->delete();
            $resource->$relationshipMethod()->save($file);
        }

        if ($model == 'users' && $request->has('role')) {
            $resource->syncRoles([$request->input('role')]);
        }

        return response()->json(['data' => $resource ?? '', 'error' => $error ?? ''], $error == '' ? 200 : 400);
    }

    public function destroy($id, $model)
    {
        $modelInstance = $this->getModelInstance($model);
        $item = $modelInstance::findOrFail($id);
        $item->delete();

        return response()->json(['error' => ''], 200);
    }
}
