<?php

namespace Visnsstudio\VisnsPackages\Controllers;

use Illuminate\Http\Request;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Storage;
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

        $data = collect($item->data)->firstWhere("id", $request->input("id"));

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

        $filteredData = collect($item->data)->filter(function ($dataItem) use (
            $request
        ) {
            return $dataItem["id"] != $request->input("id");
        });

        $resetData = $filteredData->values()->map(function ($dataItem, $key) {
            $dataItem["id"] = $key + 1; // Resetting id to be incremental starting from 1
            return $dataItem;
        });

        $item->data = $resetData->all();
        $item->save();

        return response()->json(["error" => ""], 200);
    }
}
