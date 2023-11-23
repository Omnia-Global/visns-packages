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
		} else {
			// Handle the case where the model does not exist or the segment is not provided
			// You might want to throw an exception or handle this case appropriately
		}
	}

	public function dropdown(Request $request)
	{
		$data = [];

		// Determine the sorting field
		$sortField = "label"; // default sorting field
		$fields = $request->input("fields", ["id", "label"]);
		if (count($fields) >= 2) {
			$sortField = $fields[1]; // use the second key if available
		}

		// Assuming a default ordering method if customOrder is not available
		$query = method_exists($this->model, "scopeCustomOrder")
			? $this->model::customOrder($sortField, "asc")
			: $this->model::orderBy($sortField, "asc");

		if ($request->has("where") && $request->filled("where")) {
			foreach ($request->input("where") as $condition) {
				$query->where($condition["id"], $condition["value"]);
			}
		}

		// Fields to be retrieved, defaulting to ['id', 'label']
		foreach ($query->get($fields) as $item) {
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
		// Get array of casts on the model
		$casts = $this->model->getCasts();

		// Assuming the model has defined relationships and custom methods
		$query = $this->model::query();

		if (method_exists($this->model, "loadableRelations")) {
			$query->with($this->model->loadableRelations());
		}

		// Custom ordering and searching methods
		if (method_exists($this->model, "scopeCustomOrder")) {
			$query->customOrder(
				$request->input("sortBy"),
				$request->input("sort")
			);
		}
		if (method_exists($this->model, "scopeCustomSearch")) {
			$query->customSearch($request->input("search"));
		}

		// Filtering
		if ($request->has("where") && $request->filled("where")) {
			foreach ($request->input("where") as $condition) {
				$value = $condition["value"];

				if (isset($casts[$condition["id"]])) {
					switch ($casts[$condition["id"]]) {
						case "datetime":
							$value = Carbon::createFromTimestamp(
								strtotime($value)
							);
							break;
						case "date":
							$value = Carbon::createFromTimestamp(
								strtotime($value)
							);
							break;
					}
				}

				if (isset($condition["operator"])) {
					switch ($condition["operator"]) {
						case "contains":
							$query->where(
								$condition["id"],
								"like",
								"%" . $value . "%"
							);
							break;
						case "gt":
							$query->where($condition["id"], ">", $value);
							break;
						case "gte":
							$query->where($condition["id"], ">=", $value);
							break;
						case "inlist":
							$query->whereIn($condition["id"], $value);
							break;
						case "inrange":
							$query->whereBetween($condition["id"], $value);
							break;
						case "lt":
							$query->where($condition["id"], "<", $value);
							break;
						case "lte":
							$query->where($condition["id"], "<=", $value);
							break;
						default:
							$query->where($condition["id"], $value);
							break;
					}
				} else {
					if (
						isset($condition["whereHas"]) &&
						$condition["whereHas"] != ""
					) {
						$query->whereHas($condition["whereHas"], function (
							$q
						) use ($condition, $value) {
							$q->where($condition["id"], $value);
						});
					} else {
						$query->where($condition["id"], $value);
					}
				}
			}
		}

		// Pagination
		$perPage = $request->input("take", 10);
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
		$allData = array_merge($request->all(), $validatedData);

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

		// Update any many to many relationships
		foreach ($this->model->loadableRelations() as $relation) {
			$belongsToManyRelations[] = $relation;
		}

		foreach ($belongsToManyRelations as $relationship) {
			if ($request->filled($relationship)) {
				$input = $request->input($relationship);

				// Check if input is an array of objects and extract IDs
				if (
					is_array($input) &&
					isset($input[0]) &&
					is_array($input[0])
				) {
					$ids = array_map(function ($item) {
						return $item["id"] ?? $item["value"]; // Assuming each item has either 'id' or 'value' key
					}, $input);
				} else {
					$ids = $input; // Assuming direct array of IDs
				}

				$resource->$relationship()->sync($ids);
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

		if ($this->folder == "users" && $request->has("role")) {
			$resource->assignRole($request->input("role"));
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

		// Validate the request based on the model's rules
		$validatedData = $request->validate(
			$this->model->validationRules("update", $request->all())
		);

		// Merge validated data with the entire request data
		$allData = array_merge($request->all(), $validatedData);

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

		// Update the resource
		$resource->update($allData);

		// Update any many to many relationships
		foreach ($this->model->loadableRelations() as $relation) {
			$belongsToManyRelations[] = $relation;
		}

		foreach ($belongsToManyRelations as $relationship) {
			if ($request->filled($relationship)) {
				$input = $request->input($relationship);

				// Check if input is an array of objects and extract IDs
				if (
					is_array($input) &&
					isset($input[0]) &&
					is_array($input[0])
				) {
					$ids = array_map(function ($item) {
						return $item["id"] ?? $item["value"]; // Assuming each item has either 'id' or 'value' key
					}, $input);
				} else {
					$ids = $input; // Assuming direct array of IDs
				}

				$resource->$relationship()->sync($ids);
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

		if ($this->folder == "users" && $request->has("role")) {
			$resource->syncRoles([$request->input("role")]);
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
