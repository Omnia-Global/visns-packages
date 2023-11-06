<?php

use App\Models\File;

use Illuminate\Http\Request;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Storage;

use Carbon\Carbon;

class DynamicController extends App\Http\Controllers\Controller
{
    protected $model;

    public function __construct(Request $request)
    {
        // Resolve model dynamically from the route parameter
        $modelName = Str::singular(ucfirst($request->route('model')));
        $modelClass = "App\\Models\\$modelName";

        // Check if the model class exists
        if (class_exists($modelClass)) {
            $this->model = new $modelClass();

            // Set the folder based on the route
            $this->folder = $request->route('model');
        }
    }

    public function dropdown(Request $request)
    {
        $data = [];

        // Assuming a default ordering method if customOrder is not available
        $query = method_exists($this->model, 'scopeCustomOrder')
            ? $this->model::customOrder('label', 'asc')
            : $this->model::orderBy('label', 'asc');

        if ($request->has('where') && $request->filled('where')) {
            foreach ($request->input('where') as $condition) {
                $query->where($condition['id'], $condition['value']);
            }
        }

        // Fields to be retrieved, defaulting to ['id', 'label']
        $fields = $request->input('fields', ['id', 'label']);

        foreach ($query->get($fields) as $item) {
            $itemData = [];
            foreach ($fields as $field) {
                $itemData[$field] = $item->{$field};
            }
            array_push($data, $itemData);
        }

        return response()->json(['data' => $data]);
    }

    public function show($model, $id)
    {
        $resource = $this->model::findOrFail($id);

        // Check if the model has defined loadable relations
        if (method_exists($this->model, 'loadableRelations')) {
            $resource->load($this->model->loadableRelations());
        }

        return response()->json($resource);
    }

    public function table(Request $request)
    {
        // Get array of casts on the model
        $casts = $this->model->getCasts();

        // Assuming the model has defined relationships and custom methods
        $query = $this->model::query();

        if (method_exists($this->model, 'loadableRelations')) {
            $query->with($this->model->loadableRelations());
        }

        // Custom ordering and searching methods
        if (method_exists($this->model, 'scopeCustomOrder')) {
            $query->customOrder(
                $request->input('sortBy'),
                $request->input('sort')
            );
        }
        if (method_exists($this->model, 'scopeCustomSearch')) {
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

        return response()->json($data);
    }

    public function store(Request $request, $relationshipMethod = 'file')
    {
        // Validate the request based on the model's rules
        $validatedData = $request->validate(
            $this->model->validationRules($request->all())
        );

        // Iterate over the $casts array of the model
        foreach ($this->model->getCasts() as $field => $type) {
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
        $resource = $this->model::create($validatedData);

        // Handle file upload if 'key' is present in the request
        if ($request->has('key')) {
            $unique_name =
                $request->input('uuid') . '.' . $request->input('extension');
            $path = $this->folder . '/' . $unique_name;

            Storage::copy(
                $request->input('key'),
                str_replace(
                    'tmp/',
                    $this->folder . '/',
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

        if ($this->folder == 'users' && $request->has('role')) {
            $resource->assignRole($request->input('role'));
        }

        return response()->json(['error' => '']);
    }

    public function update(Request $request, $model, $id)
    {
        // Find the resource
        $resource = $this->model::findOrFail($id);

        // Validate the request based on the model's rules
        $validatedData = $request->validate(
            $this->model->validationRules($request->all())
        );

        // Iterate over the $casts array of the model
        foreach ($this->model->getCasts() as $field => $type) {
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
        if ($request->has('key')) {
            $unique_name =
                $request->input('uuid') . '.' . $request->input('extension');
            $path = $this->folder . '/' . $unique_name;

            Storage::copy(
                $request->input('key'),
                str_replace(
                    'tmp/',
                    $this->folder . '/',
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

        if ($this->folder == 'users' && $request->has('role')) {
            $resource->syncRoles([$request->input('role')]);
        }

        return response()->json(['error' => '']);
    }

    public function destroy($id)
    {
        $item = $this->model::findOrFail($id);
        $item->delete();
        return response()->json(['error' => '']);
    }
}
