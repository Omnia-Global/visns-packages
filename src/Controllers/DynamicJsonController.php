<?php

namespace Visnsstudio\VisnsPackages\Controllers;

use Illuminate\Http\Request;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Carbon\Carbon;

class DynamicJsonController extends \App\Http\Controllers\Controller
{
    protected $model;
    protected $folder;
    protected $original;

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
            abort(404, "Model not found.");
        }
    }

    public function jsonSortList(Request $request)
    {
        $request->validate([
            "id" => "required",
        ]);

        $item = $this->model->find($request->input("id"));

        if (!$item || !isset($item->data)) {
            return response()->json(["error" => "Data not found"], 404);
        }

        $data = collect($item->data)->map(function ($dataItem) {
            return [
                "id" => $dataItem["id"],
                "label" => $dataItem["label"] ?? "No Label",
                "image_url" => "",
            ];
        });

        return response()->json($data);
    }

    public function jsonSortUpdate(Request $request)
    {
        $request->validate([
            "id" => "required",
            "list" => "required|array",
        ]);

        $item = $this->model->find($request->input("id"));

        if (!$item || !isset($item->data)) {
            return response()->json(["error" => "Data not found"], 404);
        }

        $sortedData = [];
        foreach ($request->input("list") as $key => $sortedItem) {
            foreach ($item->data as $originalItem) {
                if ($originalItem["id"] == $sortedItem["id"]) {
                    $sortedData[] = array_merge($originalItem, [
                        "id" => $key + 1,
                    ]);
                }
            }
        }

        $item->data = $sortedData;
        $item->save();

        return response()->json(["error" => ""], 200);
    }

    public function jsonGet(Request $request)
    {
        $request->validate([
            "id" => "required",
            "dataId" => "required",
        ]);

        $item = $this->model->find($request->input("dataId"));

        if (!$item) {
            return response()->json(["error" => "Item not found"], 404);
        }

        if ($request->has("key") && $request->filled("key")) {
            $data = collect($item[$request->input("key")])->firstWhere(
                "id",
                $request->input("id")
            );
        } else {
            $data = collect($item->data)->firstWhere(
                "id",
                $request->input("id")
            );
        }

        if (!$data) {
            return response()->json(["error" => "Data not found"], 404);
        }

        return response()->json($data, 200);
    }

    public function jsonTable(Request $request)
    {
        $request->validate([
            "where" => "required|array",
        ]);

        $dataKey = $request->input("dataKey");
        $data = [];
        $id = 0;
        $dataKey = "";

        foreach ($request->input("where") as $filter) {
            switch ($filter["id"]) {
                case "id":
                    $id = $filter["value"];
                    break;
                case "dataKey":
                    $dataKey = $filter["value"];
                    break;
            }
        }

        if ($id > 0 && $dataKey != "") {
            $item = $this->model->find($id);

            if ($item && !is_null($item->{$dataKey})) {
                $data = $item->{$dataKey};
            }
        }

        return response()->json(
            [
                "data" => $data,
                "total" => count($data),
            ],
            200
        );
    }

    public function jsonStore(Request $request)
    {
        $validator = Validator::make($request->all(), [
            "dataId" => "required|exists:{$this->model->getTable()},id",
            "key" => "required|string",
        ]);

        if ($validator->fails()) {
            return response()->json(["error" => $validator->errors()], 422);
        }

        $item = $this->model->find($request->input("dataId"));

        // Fetch the data field dynamically based on the provided key
        $data = $item->{$request->input("key")} ?? [];

        // Create a new data object
        $dataObj = $this->createOrUpdateDataObj($request, $data);

        // Append the new data object to the existing data
        $item->{$request->input("key")} = array_merge($data, [$dataObj]);

        // Save the updated item
        $item->save();

        return response()->json(["error" => ""], 200);
    }

    public function jsonUpdate(Request $request)
    {
        $validator = Validator::make($request->all(), [
            "dataId" => "required|exists:{$this->model->getTable()},id",
            "key" => "required|string",
            "id" => "required|integer", // Required for updating the specific entry
        ]);

        if ($validator->fails()) {
            return response()->json(["error" => $validator->errors()], 422);
        }

        $item = $this->model->find($request->input("dataId"));

        // Fetch the data field dynamically based on the provided key
        $data = $item->{$request->input("key")} ?? [];

        // Find the index of the existing item to update using 'id'
        $index = collect($data)->search(function ($dataItem) use ($request) {
            return $dataItem["id"] == $request->input("id");
        });

        if ($index === false) {
            return response()->json(["error" => "Item not found"], 404);
        }

        // Update the data object at the found index
        $data[$index] = $this->createOrUpdateDataObj($request, $data, $index);

        // Save the updated data array back to the model's key
        $item->{$request->input("key")} = $data;
        $item->save();

        return response()->json(["error" => ""], 200);
    }

    private function createOrUpdateDataObj(
        $request,
        $existingData,
        $index = null
    ) {
        if (is_null($index)) {
            // Create a new object
            $dataObj = [
                "id" => count($existingData) + 1, // Auto-increment ID
            ];
        } else {
            // Update the existing object
            $dataObj = &$existingData[$index];
        }

        // Add/update fields dynamically based on request data
        $fields = $request->except(["dataId", "key", "id"]);
        foreach ($fields as $field => $value) {
            $dataObj[$field] = $value;
        }

        return $dataObj;
    }

    private function storeFile($file, $uuid, &$dataObj)
    {
        // Derive the folder name from the model's class name, converting it to snake_case
        $folder = Str::snake(class_basename($this->model));

        // Construct the file path dynamically based on the model's name
        $path = "{$folder}/{$uuid}/{$file["filename"]}";

        // Store the file using the key and copy it to the constructed path
        Storage::copy($file["key"], $path);

        // Add the file information to the data object
        $fileInfo = [
            "file_path" => $path,
            "file_name" => $file["filename"],
            "file_extension" => $file["extension"],
            "file_size" => $file["filesize"],
        ];

        // Append the file info to the files array in dataObj
        $dataObj["files"][] = $fileInfo;
    }

    public function jsonDelete(Request $request)
    {
        $request->validate([
            "dataId" => "required",
            "id" => "required",
        ]);

        $item = $this->model->find($request->input("dataId"));

        if (!$item) {
            return response()->json(["error" => "Item not found"], 404);
        }

        $filteredData = collect(
            $request->filled("key")
                ? $item->{$request->input("key")}
                : $item->data
        )->filter(function ($dataItem) use ($request) {
            return $dataItem["id"] != $request->input("id");
        });

        $resetData = $filteredData->values()->map(function ($dataItem, $key) {
            $dataItem["id"] = $key + 1; // Resetting id to be incremental starting from 1
            return $dataItem;
        });

        if ($request->filled("key")) {
            $item->{$request->input("key")} = $resetData->all();
        } else {
            $item->data = $resetData->all();
        }
        $item->save();

        return response()->json(["error" => ""], 200);
    }
}
