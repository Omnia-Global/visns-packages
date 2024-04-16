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
    protected $model;
    protected $folder;

    public function __construct(Request $request)
    {
        // Get the current path from the request
        $path = $request->path(); // e.g., "ajax/companies/something"

        // Split the path into segments
        $segments = explode("/", $path);

        // Find the index of 'ajax' segment to determine where the model name should be
        $ajaxIndex = array_search("ajax", $segments);

        // Assuming the segment after 'ajax' is the model name
        $modelNameSegment = $segments[$ajaxIndex + 1] ?? null;

        // Convert the URL segment to StudlyCase as it's the convention for model names in Laravel
        $modelName = $modelNameSegment
            ? Str::studly(Str::singular($modelNameSegment))
            : null;

        // Generate the fully qualified class name of the model
        $modelClass = $modelName ? "App\\Models\\{$modelName}" : null;

        // Check if the model class exists and instantiate it if it does
        if ($modelClass && class_exists($modelClass)) {
            $this->model = new $modelClass();
            $this->folder = $modelName;
            $this->original = $modelNameSegment;
        } else {
            // Handle the case where the model does not exist or the segment is not provided
            // You might want to throw an exception or handle this case appropriately
        }
    }

    public function templateSort(Request $request, $id)
    {
        $validated = $request->validate([
            "detail" => ["required"],
        ]);

        $resource = $this->model::findOrFail($id);

        if ($request->has("detail")) {
            $resource->detail = $request->input("detail");
        }

        $resource->save();

        return response()->json([
            "error" => "",
        ]);
    }

    public function dropdown(Request $request)
    {
        $data = [];

        // Determine the sorting field
        $sortField = "label"; // default sorting field
        $sort = "asc";
        $fields = $request->input("fields", ["id", "label"]);

        if ($request->filled("order")) {
            $sort = $request->input("order");
        }

        if (count($fields) >= 2) {
            if ($request->filled("orderBy")) {
                $sortField = $request->input("orderBy");
            } else {
                $sortField = $fields[1]; // use the second key if available
            }
        }

        // Assuming a default ordering method if customOrder is not available
        $query = method_exists($this->model, "scopeCustomOrder")
            ? $this->model::customOrder($sortField, $sort)
            : $this->model::orderBy($sortField, $sort);

        if ($request->has("where") && $request->filled("where")) {
            foreach ($request->input("where") as $condition) {
                $query->where($condition["id"], $condition["value"]);
            }
        }

        // Fields to be retrieved, defaulting to ['id', 'label']
        foreach ($query->get() as $item) {
            $itemData = [];

            // First key-value pair
            $firstKey = $fields[0];
            $itemData[$firstKey] = $item->{$firstKey};

            // Set 'label' to be the value of the second key in $fields
            $secondKey = $fields[1] ?? "label"; // Default to 'label' if there is no second key
            $itemData["label"] = $item->{$secondKey};

            // Add remaining fields
            foreach ($fields as $key => $field) {
                if ($key > 1) {
                    $itemData[$field] = $item->{$field};
                }
            }

            array_push($data, $itemData);
        }

        return response()->json(["data" => $data], 200);
    }

    public function show($id)
    {
        $resource = $this->model::findOrFail($id);

        // Check if the model has defined loadable relations
        if (method_exists($this->model, "loadableRelations")) {
            $resource->load($this->model->loadableRelations());
        }

        return response()->json($resource);
    }

    public function table(Request $request)
    {
        $query = $this->initializeQuery();

        $this->applyRelationships($query);
        $this->applyCustomOrderAndSearch($query, $request);
        $this->applyFilters($query, $request);

        return $this->paginateAndRespond($query, $request->input("take", 10));
    }

    protected function initializeQuery()
    {
        return $this->model::query();
    }

    protected function applyRelationships($query)
    {
        if (method_exists($this->model, "loadableRelations")) {
            $query->with($this->model->loadableRelations());
        }
    }

    protected function applyCustomOrderAndSearch($query, Request $request)
    {
        if (method_exists($this->model, "scopeCustomOrder")) {
            $query->customOrder(
                $request->input("sortBy"),
                $request->input("sort")
            );
        }
        if (method_exists($this->model, "scopeCustomSearch")) {
            $query->customSearch($request->input("search"));
        }
    }

    protected function applyFilters($query, Request $request)
    {
        if ($request->has("where") && $request->filled("where")) {
            foreach ($request->input("where") as $condition) {
                $this->applyFilterCondition($query, $condition);
            }
        }
    }

    protected function applyFilterCondition($query, $condition)
    {
        $value = $condition["value"] ?? null;
        $casts = $this->model->getCasts();

        if (isset($condition["id"]) && isset($casts[$condition["id"]])) {
            $value = $this->castValue($value, $casts[$condition["id"]]);
        }

        if (isset($condition["whereHas"])) {
            $query->whereHas($condition["whereHas"], function ($q) use (
                $condition,
                $value
            ) {
                // Reuse the applyConditionBasedOnOperator method inside whereHas
                $this->applyConditionBasedOnOperator($q, $condition, $value);
            });
        } else {
            $this->applyConditionBasedOnOperator($query, $condition, $value);
        }
    }

    protected function castValue($value, $type)
    {
        switch ($type) {
            case "datetime":
            case "date":
                return $this->handleDateValue($value);
            default:
                return $value;
        }
    }

    protected function handleDateValue($value)
    {
        if (
            is_array($value) &&
            isset($value["start"]) &&
            isset($value["end"])
        ) {
            return [
                Carbon::createFromFormat("d-m-Y", $value["start"]),
                Carbon::createFromFormat("d-m-Y", $value["end"]),
            ];
        }

        if ($value === "now") {
            return Carbon::now();
        }

        return Carbon::createFromTimestamp(strtotime($value));
    }

    protected function applyConditionBasedOnOperator($query, $condition, $value)
    {
        $operator = $condition["operator"] ?? "=";
        $id = $condition["id"] ?? null;

        if (!$id) {
            return;
        }

        switch ($operator) {
            case "contains":
                $query->where($id, "like", "%" . $value . "%");
                break;
            case "gt":
                $query->where($id, ">", $value);
                break;
            case "gte":
                $query->where($id, ">=", $value);
                break;
            case "inlist":
                $query->whereIn($id, $value);
                break;
            case "inrange":
                if (is_array($value) && count($value) === 2) {
                    $query->whereBetween($id, $value);
                }
                break;
            case "lt":
                $query->where($id, "<", $value);
                break;
            case "lte":
                $query->where($id, "<=", $value);
                break;
            default:
                $query->where($id, $operator, $value);
                break;
        }
    }

    protected function paginateAndRespond($query, $perPage)
    {
        $data = $query->paginate($perPage);
        return response()->json($data, 200);
    }

    public function store(Request $request)
    {
        $error = "";

        // Validate the request based on the model's rules
        $validatedData = $request->validate(
            $this->model->validationRules("store", $request->all())
        );

        // Merge validated data with the entire request data
        $allData = $this->deepMerge($request->all(), $validatedData);

        // Process array fields like 'integration_detail'
        foreach ($this->model->getCasts() as $field => $type) {
            if ($type === "array") {
                foreach ($allData as $key => $value) {
                    // Check if the key starts with the field name followed by a dot
                    if (strpos($key, $field . ".") === 0) {
                        // Extract the sub-key and set the value in the array
                        $subKey = substr($key, strlen($field) + 1);
                        $allData[$field][$subKey] = $value;

                        // Remove the original key-value pair from $allData
                        unset($allData[$key]);
                    }
                }
            } elseif (
                ($type === "datetime" || $type === "date") &&
                isset($allData[$field])
            ) {
                // Convert the field using Carbon
                $allData[$field] = Carbon::createFromTimestamp(
                    strtotime($allData[$field])
                );
            }
        }

        // Create a new resource
        $resource = $this->model::create($allData);

        // Initialize an array to hold many-to-many relationships
        $manyToManyRelations = [];

        // Load necessary relations to avoid N+1 problems
        $this->model->loadMissing($this->model->loadableRelations());

        foreach ($this->model->loadableRelations() as $relation) {
            // Skip the relation if it contains a dot ('.')
            if (strpos($relation, ".") !== false) {
                continue;
            }

            // Check if the relationship type is many-to-many by using instanceof with BelongsToMany
            if (
                $this->model->$relation() instanceof
                \Illuminate\Database\Eloquent\Relations\BelongsToMany
            ) {
                $manyToManyRelations[] = $relation;
            }
        }

        foreach ($manyToManyRelations as $relationship) {
            if ($request->filled($relationship)) {
                $input = $request->input($relationship);

                // Initialize $ids as an empty array to handle the case where $input is not an array of objects or IDs
                $ids = [];

                // Check if input is valid and not null
                if (!is_null($input)) {
                    // Check if input is an array of objects and extract IDs
                    if (
                        is_array($input) &&
                        isset($input[0]) &&
                        is_array($input[0])
                    ) {
                        $ids = array_map(function ($item) {
                            // Assuming each item has either 'id' or 'value' key
                            return $item["id"] ?? $item["value"];
                        }, $input);
                    } elseif (is_array($input)) {
                        // Assuming direct array of IDs
                        $ids = $input;
                    } else {
                        $ids = [$input];
                    }

                    // Use sync method to update the many-to-many relationship
                    $resource->$relationship()->sync($ids);
                }
            }
        }

        // Handle file upload if 'key' is present in the request
        if ($request->has("key") && $request->has("file_relationship")) {
            $relationshipMethod = $request->input("file_relationship");
            $unique_name =
                $request->input("uuid") . "." . $request->input("extension");
            $path = $this->folder . "/" . $unique_name;

            Storage::copy(
                $request->input("key"),
                str_replace(
                    "tmp/",
                    $this->folder . "/",
                    $request->input("key")
                ) .
                    "." .
                    $request->input("extension")
            );

            $file = new File([
                "file_path" => $path,
                "file_name" => $request->input("filename"),
                "file_extension" => $request->input("extension"),
                "file_size" => $request->input("filesize"),
            ]);

            // Dynamically attach the file to the resource
            $resource->$relationshipMethod()->save($file);
        }

        // Handle file upload if $request->file is present

        if ($request->files->count() > 0) {
            foreach ($request->allFiles() as $fileKey => $file) {
                // Ensure each file is valid before processing
                if (
                    $request->hasFile($fileKey) &&
                    $request->file($fileKey)->isValid()
                ) {
                    $fileUpload = $request->file($fileKey);
                    $extension = $fileUpload->getClientOriginalExtension();
                    $fileName = $fileUpload->getClientOriginalName();
                    $fileSize = $fileUpload->getSize();
                    $filePath = $fileName; // Custom path in your S3 bucket

                    // Upload file to S3
                    Storage::put(
                        $this->folder . "/" . $filePath,
                        file_get_contents($fileUpload)
                    );

                    // Create a record in the files table
                    $file = new File([
                        "file_path" => $filePath, // Assuming 'file_path' is the full path in the bucket
                        "file_name" => $fileName,
                        "file_extension" => $extension,
                        "file_size" => $fileSize,
                        "fileable_field" => $fileKey, // Assuming this field denotes the purpose or type of the file
                    ]);

                    $resource->$fileKey()->save($file);
                }
            }
        }

        if ($this->folder == "User" && $request->has("role")) {
            $resource->assignRole([$request->input("role")]);
        }

        // Check if the model has defined loadable relations
        if (method_exists($this->model, "loadableRelations")) {
            $resource->load($this->model->loadableRelations());
        }

        return response()->json(
            ["data" => $resource ?? "", "error" => $error ?? ""],
            $error == "" ? 200 : 400
        );
    }

    public function update(Request $request, $id)
    {
        $error = "";

        // Find the resource
        $resource = $this->model::findOrFail($id);

        // Add the $id to the request data
        $requestData = $request->all() + ["id" => $id];

        // Validate the request based on the model's rules
        $validatedData = $request->validate(
            $this->model->validationRules("update", $requestData)
        );
        // Deep merge validated data with the entire request data to preserve nested unvalidated data
        $allData = $this->deepMerge($request->all(), $validatedData);

        // Process array fields like 'integration_detail'
        foreach ($this->model->getCasts() as $field => $type) {
            if ($type === "array") {
                foreach ($allData as $key => $value) {
                    if ($key == $field) {
                        // Check if the key starts with the field name followed by a dot
                        if (strpos($key, $field . ".") === 0) {
                            // Extract the sub-key and set the value in the array
                            $subKey = substr($key, strlen($field) + 1);
                            $allData[$field][$subKey] = $value;

                            // Remove the original key-value pair from $allData
                            unset($allData[$key]);
                        }
                    }
                }
            } elseif (
                ($type === "datetime" || $type === "date") &&
                isset($allData[$field])
            ) {
                // Convert the field using Carbon
                $allData[$field] = Carbon::createFromTimestamp(
                    strtotime($allData[$field])
                );
            }
        }

        // Update the resource
        $resource->update($allData);

        // Initialize an array to hold many-to-many relationships
        $manyToManyRelations = [];

        // Load necessary relations to avoid N+1 problems
        $this->model->loadMissing($this->model->loadableRelations());

        foreach ($this->model->loadableRelations() as $relation) {
            // Skip the relation if it contains a dot ('.')
            if (strpos($relation, ".") !== false) {
                continue;
            }

            // Check if the relationship type is many-to-many by using instanceof with BelongsToMany
            if (
                $this->model->$relation() instanceof
                \Illuminate\Database\Eloquent\Relations\BelongsToMany
            ) {
                $manyToManyRelations[] = $relation;
            }
        }

        foreach ($manyToManyRelations as $relationship) {
            if ($request->filled($relationship)) {
                $input = $request->input($relationship);

                // Initialize $ids as an empty array to handle the case where $input is not an array of objects or IDs
                $ids = [];

                // Check if input is valid and not null
                if (!is_null($input)) {
                    // Check if input is an array of objects and extract IDs
                    if (
                        is_array($input) &&
                        isset($input[0]) &&
                        is_array($input[0])
                    ) {
                        $ids = array_map(function ($item) {
                            // Assuming each item has either 'id' or 'value' key
                            return $item["id"] ?? $item["value"];
                        }, $input);
                    } elseif (is_array($input)) {
                        // Assuming direct array of IDs
                        $ids = $input;
                    } else {
                        $ids = [$input];
                    }

                    // Use sync method to update the many-to-many relationship
                    $resource->$relationship()->sync($ids);
                }
            }
        }

        // Handle file upload if 'key' is present in the request
        if ($request->has("key") && $request->has("file_relationship")) {
            $relationshipMethod = $request->input("file_relationship");
            $unique_name =
                $request->input("uuid") . "." . $request->input("extension");
            $path = $this->folder . "/" . $unique_name;

            Storage::copy(
                $request->input("key"),
                str_replace(
                    "tmp/",
                    $this->folder . "/",
                    $request->input("key")
                ) .
                    "." .
                    $request->input("extension")
            );

            $file = new File([
                "file_path" => $path,
                "file_name" => $request->input("filename"),
                "file_extension" => $request->input("extension"),
                "file_size" => $request->input("filesize"),
            ]);

            // Dynamically attach the file to the resource
            $resource->$relationshipMethod()->delete();
            $resource->$relationshipMethod()->save($file);
        }

        // Handle file upload if $request->file is present

        if ($request->files->count() > 0) {
            foreach ($request->allFiles() as $fileKey => $file) {
                // Ensure each file is valid before processing
                if (
                    $request->hasFile($fileKey) &&
                    $request->file($fileKey)->isValid()
                ) {
                    $fileUpload = $request->file($fileKey);
                    $extension = $fileUpload->getClientOriginalExtension();
                    $fileName = $fileUpload->getClientOriginalName();
                    $fileSize = $fileUpload->getSize();
                    $filePath = $fileName; // Custom path in your S3 bucket

                    // Upload file to S3
                    Storage::put(
                        $this->folder . "/" . $filePath,
                        file_get_contents($fileUpload)
                    );

                    // Create a record in the files table
                    $file = new File([
                        "file_path" => $filePath, // Assuming 'file_path' is the full path in the bucket
                        "file_name" => $fileName,
                        "file_extension" => $extension,
                        "file_size" => $fileSize,
                        "fileable_field" => $fileKey, // Assuming this field denotes the purpose or type of the file
                    ]);

                    $resource->$fileKey()->delete();
                    $resource->$fileKey()->save($file);
                }
            }
        }

        if ($this->folder == "User" && $request->has("role")) {
            $resource->syncRoles([$request->input("role")]);
        }

        // Check if the model has defined loadable relations
        if (method_exists($this->model, "loadableRelations")) {
            $resource->load($this->model->loadableRelations());
        }

        return response()->json(
            ["data" => $resource ?? "", "error" => $error ?? ""],
            $error == "" ? 200 : 400
        );
    }

    /**
     * Deep merge two arrays, giving priority to non-null values in the second array.
     */
    private function deepMerge(array $original, array $overrides)
    {
        foreach ($overrides as $key => $value) {
            if (
                is_array($value) &&
                isset($original[$key]) &&
                is_array($original[$key])
            ) {
                $original[$key] = $this->deepMerge($original[$key], $value);
            } else {
                $original[$key] = $value;
            }
        }
        return $original;
    }

    public function updateGallery(Request $request, $id)
    {
        $error = "";

        // Find the resource
        $resource = $this->model::findOrFail($id);

        $validated = $request->validate([
            "key" => ["required"],
            "uuid" => ["required"],
            "extension" => ["required"],
            "filename" => ["required"],
            "fileable_field" => ["required"],
            "fileable_type" => ["required"],
        ]);

        if ($request->filled("key")) {
            $filePath =
                str_replace("tmp/", "", $request->input("key")) .
                "." .
                $request->input("extension");
            $destinationPath =
                $this->original .
                "/" .
                $request->input("uuid") .
                "." .
                $request->input("extension");

            Storage::copy($request->input("key"), $destinationPath);

            $nextOrder = File::where("fileable_id", $resource->id)
                ->where("fileable_field", $request->input("fileable_field"))
                ->where("fileable_type", $request->input("fileable_type"))
                ->max("sort_order");

            $file = new File([
                "fileable_field" => $request->input("fileable_field"),
                "file_path" => $filePath,
                "file_name" => $request->input("filename"),
                "file_extension" => $request->input("extension"),
                "file_size" => $request->input("filename"),
                "sort_order" => $nextOrder + 1,
            ]);

            $resource->{$request->input("fileable_field")}()->save($file);
        }

        return response()->json(
            ["data" => $resource ?? "", "error" => $error ?? ""],
            $error == "" ? 200 : 400
        );
    }

    public function destroy($id)
    {
        $item = $this->model::findOrFail($id);
        $item->delete();

        return response()->json(["error" => ""], 200);
    }
}
