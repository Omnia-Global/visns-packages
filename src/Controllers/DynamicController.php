<?php

namespace Visnsstudio\VisnsPackages\Controllers;

use App\Models\File;

use Illuminate\Http\Request;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Schema;

use Carbon\Carbon;

class DynamicController extends \App\Http\Controllers\Controller
{
    protected $model;
    protected $folder;

    public function __construct(Request $request)
    {
        // Get the current path from the request
        $path = $request->path(); // e.g., "ajax/companies/client:id,firstname,surname"

        // Split the path into segments
        $segments = explode('/', $path);

        // Find the index of 'ajax' segment to determine where the model name should be
        $ajaxIndex = array_search('ajax', $segments);

        // Assuming the segment after 'ajax' is the model name
        $modelNameSegment = $segments[$ajaxIndex + 1] ?? null;

        // Handle cases where the model name contains a colon (e.g., "client:id,firstname,surname")
        if ($modelNameSegment && strpos($modelNameSegment, ':') !== false) {
            // Extract only the part before the colon
            $modelNameSegment = explode(':', $modelNameSegment)[0];
        }

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

    public function sort_list()
    {
        $data = [];

        foreach ($this->model::orderBy('sort_order')->get() as $item) {
            $label = '';

            if (isset($item->name)) {
                $label = $item->name;
            } elseif (isset($item->label)) {
                $label = $item->label;
            } elseif (isset($item->firstname) && isset($item->surname)) {
                $label = $item->firstname . ' ' . $item->surname;
            } elseif (isset($item->company)) {
                $label = $item->company;
            }

            array_push($data, [
                'id' => $item->id,
                'label' => $label,
            ]);
        }

        return response()->json($data);
    }

    public function sort_update(Request $request)
    {
        $error = '';

        $validated = $request->validate([
            'list' => ['required'],
        ]);

        foreach ($request->input('list') as $key => $item) {
            $object = $this->model::find($item['id']);
            $object->sort_order = $key;
            $object->save();
        }

        return response()->json(['error' => $error]);
    }

    public function templateSort(Request $request, $id)
    {
        $validated = $request->validate([
            'detail' => ['required'],
        ]);

        $resource = $this->model::findOrFail($id);

        if ($request->has('detail')) {
            $resource->detail = $request->input('detail');
        }

        $resource->save();

        return response()->json([
            'error' => '',
        ]);
    }

    private function getSortParams(Request $request, $fields)
    {
        $sortField =
            $request->input('orderBy') ??
            ($request->input('sortBy') ?? ($fields[1] ?? null));
        $sort = $request->input('order') ?? ($request->input('sort') ?? 'asc');

        // Default to "label" if no sortField is specified in the request or fields
        if (is_null($sortField)) {
            $sortField = 'label';
        }

        return [$sortField, $sort];
    }

    public function dropdown(Request $request)
    {
        $data = [];

        // Determine the sorting field
        $sortField = 'label'; // default sorting field
        $sort = 'asc';
        $fields = $request->input('fields', ['id', 'label']);

        // Get sorting parameters
        [$sortField, $sort] = $this->getSortParams($request, $fields);

        // Assuming a default ordering method if customOrder is not available
        $query = method_exists($this->model, 'scopeCustomOrder')
            ? $this->model::customOrder($sortField, $sort)
            : $this->model::orderBy($sortField, $sort);

        if (Schema::hasColumn($this->model->getTable(), 'hide')) {
            $query->where('hide', 0);
        }

        if ($request->has('where') && $request->filled('where')) {
            foreach ($request->input('where') as $condition) {
                switch ($condition['id']) {
                    case 'role':
                        if ($this->folder == 'User') {
                            $query->role($condition['value']);
                        }
                        break;
                    case 'async':
                        if (method_exists($this->model, 'scopeCustomSearch')) {
                            $query->customSearch($condition['value']);
                        }
                        break;
                    case 'whereHas':
                        $query->whereHas($condition['value']);
                        break;
                    default:
                        $this->applyConditionBasedOnOperator(
                            $query,
                            $condition,
                            $condition['value']
                        );
                        break;
                }
            }
        }

        foreach ($query->get() as $item) {
            $itemData = [];

            // First key-value pair
            $firstKey = $fields[0];
            $itemData[$firstKey] = $item->{$firstKey};

            // Check if fields include 'firstname' and 'surname' to combine them
            if (
                in_array('firstname', $fields) &&
                in_array('surname', $fields)
            ) {
                $firstname = $item->firstname ?? '';
                $surname = $item->surname ?? '';
                $itemData['label'] = trim($firstname . ' ' . $surname);
            } elseif (in_array('label', $fields)) {
                // Use 'label' field if it exists
                $itemData['label'] = $item->label;

                // Check if 'label' field is empty and use 'name' field as fallback
                if (
                    empty($itemData['label']) &&
                    isset($fields[2]) &&
                    isset($item->{$fields[2]})
                ) {
                    $itemData['label'] = $item->{$fields[2]};
                }
            } elseif (in_array('name', $fields)) {
                // Use 'name' field as 'label' if 'label' is not present
                $itemData['label'] = $item->name;
            } else {
                // Default behavior if no 'label' or 'name' field exists
                $secondKey = $fields[1] ?? 'label'; // Default to 'label' if there is no second key
                $itemData['label'] = $item->{$secondKey};
            }

            // Add remaining fields
            foreach ($fields as $key => $field) {
                if (
                    $key > 1 &&
                    $field !== 'firstname' &&
                    $field !== 'surname'
                ) {
                    $itemData[$field] = $item->{$field};
                }
            }

            array_push($data, $itemData);
        }

        return response()->json(['data' => $data], 200);
    }

    public function dropdownWithGroups(Request $request)
    {
        $data = [];

        // Determine the sorting field
        $sortField = 'label'; // default sorting field
        $sort = 'asc';
        $fields = $request->input('fields', ['id', 'label']);

        // Get sorting parameters
        [$sortField, $sort] = $this->getSortParams($request, $fields);

        // Assuming a default ordering method if customOrder is not available
        $query = method_exists($this->model, 'scopeCustomOrder')
            ? $this->model::customOrder($sortField, $sort)
            : $this->model::orderBy($sortField, $sort);

        if ($request->has('where') && $request->filled('where')) {
            foreach ($request->input('where') as $condition) {
                switch ($condition['id']) {
                    case 'role':
                        if ($this->folder == 'User') {
                            $query->role($condition['value']);
                        }
                        break;
                    case 'async':
                        if (method_exists($this->model, 'scopeCustomSearch')) {
                            $query->customSearch($condition['value']);
                        }
                        break;
                    case 'whereHas':
                        $query->whereHas($condition['value']);
                        break;
                    default:
                        $this->applyConditionBasedOnOperator(
                            $query,
                            $condition,
                            $condition['value']
                        );
                        break;
                }
            }
        }

        // Fetch and group data by parent_id
        $items = $query->get();
        $groupedData = [];
        $parentLabels = []; // Track parent labels to avoid duplicates

        foreach ($items as $item) {
            $itemData = [];

            // First key-value pair
            $firstKey = $fields[0];
            $itemData[$firstKey] = $item->{$firstKey};

            // Set 'label' to be the value of the second key in $fields
            $secondKey = $fields[1] ?? 'label';
            $itemData['label'] = $item->{$secondKey};

            // Add remaining fields
            foreach ($fields as $key => $field) {
                if ($key > 1) {
                    $itemData[$field] = $item->{$field};
                }
            }

            if (isset($item->parent_id) && $item->parent_id > 0) {
                // If item has a parent, add it under its parent group
                if (!isset($groupedData[$item->parent_id])) {
                    $groupedData[$item->parent_id] = [
                        'label' => '', // Placeholder label for parent, will be filled later
                        'options' => [],
                    ];
                }
                $groupedData[$item->parent_id]['options'][] = $itemData;
            } else {
                // If item has no parent, check for duplicate parent labels
                if (!isset($groupedData[$item->{$firstKey}])) {
                    $groupedData[$item->{$firstKey}] = [
                        'id' => $item->{$firstKey},
                        'label' => $item->{$secondKey},
                        'options' => [],
                    ];
                    $parentLabels[$item->{$firstKey}] = $item->{$secondKey}; // Add to parent labels set with unique ID
                }
            }
        }

        // Assign labels for parent groups and filter out parents with empty labels
        foreach ($groupedData as $parentId => &$group) {
            if (isset($group['options']) && count($group['options']) > 0) {
                $parentItem = $items->firstWhere($fields[0], $parentId);
                if ($parentItem) {
                    $group['label'] = $parentItem->{$secondKey};
                }
            }
        }

        // Filter out parents with empty labels
        $groupedData = array_filter($groupedData, function ($group) {
            return !empty($group['label']);
        });

        // Sort parent groups alphabetically by label
        uasort($groupedData, function ($a, $b) {
            return strcasecmp($a['label'], $b['label']);
        });

        // Flatten grouped data for the response and filter out empty labels
        $flattenedData = [];
        foreach ($groupedData as $parentKey => $group) {
            if (!empty($group['options'])) {
                $flattenedData[] = [
                    'id' => $parentKey,
                    'label' => $group['label'],
                    'options' => $group['options'],
                ];
            } elseif (isset($group['id'])) {
                $flattenedData[] = [
                    'id' => $group['id'],
                    'label' => $group['label'],
                    'options' => $group['options'], // Ensure options is set, even if empty
                ];
            }
        }

        return response()->json(['data' => $flattenedData], 200);
    }

    public function show($id)
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
        $query = $this->initializeQuery();

        $this->applyRelationships($query);
        $this->applyCustomOrderAndSearch($query, $request);
        $this->applyFilters($query, $request);

        return $this->paginateAndRespond($query, $request->input('take', 10));
    }

    public function list(Request $request)
    {
        $query = $this->initializeQuery();

        $this->applyRelationships($query);
        $this->applyCustomOrderAndSearch($query, $request);
        $this->applyFilters($query, $request);

        return $this->respondWithAll($query);
    }

    protected function initializeQuery()
    {
        return $this->model::query();
    }

    protected function applyRelationships($query)
    {
        if (method_exists($this->model, 'loadableRelations')) {
            $query->with($this->model->loadableRelations());
        }
    }

    protected function applyCustomOrderAndSearch($query, Request $request)
    {
        if (method_exists($this->model, 'scopeCustomOrder')) {
            $query->customOrder(
                $request->input('sortBy'),
                $request->input('sort')
            );
        }
        if (method_exists($this->model, 'scopeCustomSearch')) {
            $query->customSearch($request->input('search'));
        }
    }

    protected function applyFilters($query, Request $request)
    {
        if ($request->has('where') && $request->filled('where')) {
            foreach ($request->input('where') as $condition) {
                $this->applyFilterCondition($query, $condition);
            }
        }
    }

    protected function applyFilterCondition($query, $condition)
    {
        $value = $condition['value'] ?? null;
        $casts = $this->model->getCasts();

        if (isset($condition['id']) && isset($casts[$condition['id']])) {
            $value = $this->castValue($value, $casts[$condition['id']]);
        }

        $this->applyConditionBasedOnOperator($query, $condition, $value);
    }

    protected function castValue($value, $type)
    {
        switch ($type) {
            case 'datetime':
            case 'date':
                return $this->handleDateValue($value);
            default:
                return $value;
        }
    }

    protected function handleDateValue($value)
    {
        // 1. If $value is an array with 'start' and 'end' keys
        if (is_array($value) && isset($value['start'], $value['end'])) {
            if ($value['start'] !== '' && $value['end'] !== '') {
                $start = Carbon::parse($value['start'], config('app.timezone'))
                    ->startOfDay()
                    ->setTimezone('UTC');
                $end = Carbon::parse($value['end'], config('app.timezone'))
                    ->endOfDay()
                    ->setTimezone('UTC');

                return [$start, $end];
            }
        }

        // 2. If the value is the string 'now'
        if ($value === 'now') {
            return Carbon::now('UTC');
        }

        // 3. If $value is a scalar (not an array or object)
        if (!is_array($value) && !is_object($value)) {
            return Carbon::parse($value, config('app.timezone'))->setTimezone(
                'UTC'
            );
        }

        // 4. If $value is an array or object (without 'start'/'end'), loop through each element
        $result = [];
        foreach ((array) $value as $key => $item) {
            $result[$key] = Carbon::parse(
                $item,
                config('app.timezone')
            )->setTimezone('UTC');
        }
        return $result;
    }

    protected function applyConditionBasedOnOperator($query, $condition, $value)
    {
        $operator = $condition['operator'] ?? '=';
        $id = $condition['id'] ?? null;
        $whereHas = $condition['whereHas'] ?? [];

        // Special case: if only whereHas is provided (no id/value needed)
        if (empty($id) && !empty($whereHas)) {
            if (is_string($whereHas)) {
                $query->whereHas($whereHas);
            } elseif (is_array($whereHas)) {
                foreach ($whereHas as $relation) {
                    $query->whereHas($relation);
                }
            }
            return;
        }

        if (!$id) {
            return;
        }

        // Extract JSON field and key from $id (e.g., 'event_coordinator.company' becomes 'event_coordinator' and 'company')
        $fieldParts = explode('.', $id);
        $jsonField = array_shift($fieldParts); // The main JSON column (e.g., 'event_coordinator')
        $jsonPath = '$.' . implode('.', $fieldParts); // The path inside the JSON (e.g., '$.company')

        $applyCondition = function ($query) use (
            $operator,
            $jsonField,
            $jsonPath,
            $value
        ) {
            switch ($operator) {
                case 'contain_json':
                    $query->where(
                        DB::raw(
                            "JSON_UNQUOTE(JSON_EXTRACT($jsonField, '$jsonPath'))"
                        ),
                        'like',
                        '%' . $value . '%'
                    );
                    break;
                case 'contains':
                    $query->where($jsonField, 'like', '%' . $value . '%');
                    break;
                case 'not_contains':
                    $query->where($jsonField, 'not like', '%' . $value . '%');
                    break;
                case 'gt':
                    $query->where($jsonField, '>', $value);
                    break;
                case 'gte':
                    $query->where($jsonField, '>=', $value);
                    break;
                case 'inlist':
                    $query->whereIn($jsonField, $value);
                    break;
                case 'notinlist':
                    $query->whereNotIn($jsonField, $value);
                    break;
                case 'inrange':
                    if (is_array($value)) {
                        if (count($value) === 2) {
                            if (
                                isset($value['start']) &&
                                isset($value['end'])
                            ) {
                                $dateRange = $this->handleDateValue($value);
                                $query->whereBetween($jsonField, $dateRange);
                            } else {
                                $query->whereBetween($jsonField, $value);
                            }
                        }
                    }
                    break;
                case 'is_null':
                    $query->whereNull($jsonField);
                    break;
                case 'lt':
                    $query->where($jsonField, '<', $value);
                    break;
                case 'lte':
                    $query->where($jsonField, '<=', $value);
                    break;
                default:
                    // Check if value is a date and apply whereDate
                    $dateFormats = ['Y-m-d', 'd-m-Y'];
                    $date = null;

                    foreach ($dateFormats as $format) {
                        try {
                            $date = Carbon::createFromFormat($format, $value);
                            break; // Valid date, exit loop
                        } catch (\Exception $e) {
                            // Continue to try other formats
                        }
                    }

                    // If $date is not null, it's a valid date, use whereDate
                    if ($date) {
                        $query->whereDate($jsonField, $date->format('Y-m-d')); // Store date in Y-m-d format
                    } else {
                        $query->where($jsonField, $value); // Fallback to default where clause
                    }
                    break;
            }
        };

        // Apply conditions with whereHas if provided
        if (!empty($whereHas)) {
            // Ensure whereHas is treated as an array, even if it's a single string
            $relations = is_string($whereHas) ? [$whereHas] : $whereHas;

            // Recursive function to process relationships
            $applyNestedWhereHas = function (
                $query,
                $relations,
                $applyCondition
            ) use (&$applyNestedWhereHas) {
                $relation = array_shift($relations); // Get the first relation
                if (!is_string($relation)) {
                    throw new \InvalidArgumentException(
                        'Relation must be a string.'
                    );
                }

                $query->whereHas($relation, function ($subQuery) use (
                    $relations,
                    $applyCondition,
                    $applyNestedWhereHas
                ) {
                    if (empty($relations)) {
                        // No more nested relations, apply the condition
                        $applyCondition($subQuery);
                    } else {
                        // Recursively process the remaining relations
                        $applyNestedWhereHas(
                            $subQuery,
                            $relations,
                            $applyCondition
                        );
                    }
                });
            };

            // Apply the recursive function for the relationships
            $applyNestedWhereHas($query, $relations, $applyCondition);
        } else {
            // Apply the condition directly if no whereHas
            $applyCondition($query);
        }
    }

    protected function paginateAndRespond($query, $perPage)
    {
        // Perform pagination
        $paginator = $query->paginate($perPage);

        // Get the data from the paginator
        $data = $paginator->getCollection();

        // Check if the model has excludedFields method
        if (method_exists($this->model, 'excludedFields')) {
            $excludedFields = $this->model->excludedFields();

            // Loop through the data and remove the excluded fields
            $data = $data->map(function ($item) use ($excludedFields) {
                $itemArray = $item->toArray();

                // Remove the excluded fields
                foreach ($excludedFields as $field) {
                    unset($itemArray[$field]);
                }

                return $itemArray;
            });
        }

        // Replace the data in the paginator
        $paginator->setCollection($data);

        // Return the paginated response as JSON
        return response()->json($paginator, 200);
    }

    protected function respondWithAll($query)
    {
        $data = $query->get();

        // Check if the model has excludedFields method
        if (method_exists($this->model, 'excludedFields')) {
            $excludedFields = $this->model->excludedFields();

            // Loop through the data and remove the excluded fields
            $data = $data->map(function ($item) use ($excludedFields) {
                $itemArray = $item->toArray();

                // Remove the excluded fields
                foreach ($excludedFields as $field) {
                    unset($itemArray[$field]);
                }

                return $itemArray;
            });
        }

        return response()->json($data, 200);
    }

    public function clone($id)
    {
        // Find the resource by ID or fail
        $resource = $this->model::findOrFail($id);

        // Replicate the resource
        $newResource = $resource->replicate();

        // Check if 'label' or 'title' key exists and append '(Clone)' to it
        if (isset($newResource->label)) {
            $newResource->label .= ' (Clone)';
        } elseif (isset($newResource->title)) {
            $newResource->title .= ' (Clone)';
        }

        // Save the cloned resource
        $newResource->save();

        // Return the new resource as a JSON response
        return response()->json($newResource, 200);
    }

    public function store(Request $request)
    {
        $error = '';

        // Validate the request based on the model's rules
        $validatedData = $request->validate(
            $this->model->validationRules('store', $request->all())
        );

        // Merge validated data with the entire request data
        $allData = $this->deepMerge($request->all(), $validatedData);

        // Overwrite fields with their "value" if exists
        foreach ($allData as $field => $value) {
            // Check if the value is an instance of UploadedFile
            if ($value instanceof \Illuminate\Http\UploadedFile) {
                // Handle file upload separately or skip this processing
                // You might want to process file uploads differently here
                continue;
            }

            // Check if value is an array and has "value" key
            if (is_array($value) && isset($value['value'])) {
                $allData[$field] = $value['value'];
            }
        }

        // Check if the model is 'User' and the password needs hashing
        if ($this->folder == 'User' && $request->has('password')) {
            $allData['password'] = Hash::make($request->input('password'));
        }

        // Process array fields like 'integration_detail'
        foreach ($this->model->getCasts() as $field => $type) {
            if ($type === 'array') {
                foreach ($allData as $key => $value) {
                    if (strpos($key, $field . '.') === 0) {
                        // Extract the sub-key and set the value in the array
                        $subKey = substr($key, strlen($field) + 1);
                        if (
                            !isset($allData[$field]) ||
                            !is_array($allData[$field])
                        ) {
                            $allData[$field] = [];
                        }
                        $allData[$field][$subKey] = $value;

                        // Remove the original key-value pair from $allData
                        unset($allData[$key]);
                    }
                }
            } elseif (
                ($type === 'datetime' || $type === 'date') &&
                isset($allData[$field])
            ) {
                // Convert the field using Carbon
                $allData[$field] = Carbon::createFromTimestamp(
                    strtotime($allData[$field])
                );
            } elseif ($type === 'boolean') {
                if (isset($allData[$field])) {
                    $allData[$field] = $allData[$field] === true ? 1 : 0;
                } else {
                    $allData[$field] = 0;
                }
            }
        }

        // Create a new resource
        $resource = $this->model::create($allData);

        // Initialize an array to hold many-to-many relationships
        $manyToManyRelations = [];

        // Load necessary relations to avoid N+1 problems
        $this->model->loadMissing($this->model->loadableRelations());

        foreach ($this->model->loadableRelations() as $relation) {
            // Remove everything after ':' if it exists
            $relation =
                strpos($relation, ':') !== false
                    ? explode(':', $relation)[0]
                    : $relation;

            // Skip the relation if it contains a dot ('.')
            if (strpos($relation, '.') !== false) {
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
                            return $item['id'] ?? $item['value'];
                        }, $input);
                    } elseif (is_array($input)) {
                        // Assuming direct array of IDs
                        $ids = $input;
                    } else {
                        $ids = [$input];
                    }

                    // Now we need to sync the relationships, but also check for sort_order
                    $syncData = [];
                    $sortOrder = 1; // Start sort_order at 1

                    // Check if the pivot table has a 'sort_order' field
                    $pivotTable = $resource->$relationship()->getTable(); // Get the pivot table name
                    $hasSortOrder = Schema::hasColumn(
                        $pivotTable,
                        'sort_order'
                    ); // Check if 'sort_order' column exists

                    foreach ($ids as $id) {
                        // Only add 'sort_order' if the field exists
                        if ($hasSortOrder) {
                            $syncData[$id] = ['sort_order' => $sortOrder++];
                        } else {
                            $syncData[$id] = []; // Sync without 'sort_order' if it doesn't exist
                        }
                    }

                    // Sync the relationships with or without pivot data (depending on the existence of sort_order)
                    $resource->$relationship()->sync($syncData);
                }
            }
        }

        // Handle file upload if 'key' is present in the request
        if ($request->has('key') && $request->has('file_relationship')) {
            $relationshipMethod = $request->input('file_relationship');
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
                'fileable_field' =>
                    $request->filled('fileable_field') &&
                    $request->has('fileable_field')
                        ? $request->input('fileable_field')
                        : $request->input('file_relationship'),
            ]);

            // Dynamically attach the file to the resource
            $resource->$relationshipMethod()->save($file);
        }

        if ($request->has('uploadedFiles')) {
            $uploadedFiles = $request->input('uploadedFiles');

            if (!isset($uploadedFiles[0])) {
                $uploadedFiles = [$uploadedFiles];
            }

            foreach ($uploadedFiles as $uploadedFile) {
                if (
                    $uploadedFile['key'] &&
                    $uploadedFile['file_relationship']
                ) {
                    $relationshipMethod = $uploadedFile['file_relationship'];
                    $unique_name =
                        $uploadedFile['uuid'] .
                        '.' .
                        $uploadedFile['extension'];
                    $path = $this->folder . '/' . $unique_name;

                    if (Storage::exists($uploadedFile['key'])) {
                        Storage::copy(
                            $uploadedFile['key'],
                            str_replace(
                                'tmp/',
                                $this->folder . '/',
                                $uploadedFile['key']
                            ) .
                                '.' .
                                $uploadedFile['extension']
                        );

                        $file = new File([
                            'file_path' => $path,
                            'file_name' => $uploadedFile['filename'],
                            'file_extension' => $uploadedFile['extension'],
                            'file_size' => $uploadedFile['filesize'],
                            'fileable_field' => isset(
                                $uploadedFile['fileable_field']
                            )
                                ? $uploadedFile['fileable_field']
                                : $uploadedFile['file_relationship'],
                        ]);

                        // Dynamically attach the file to the resource
                        $resource->$relationshipMethod()->save($file);
                    }
                }
            }
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
                    $uniqueName = Str::uuid() . '.' . $extension; // You can also use \Str::random(40) for a random string
                    $filePath = $this->folder . '/' . $uniqueName;

                    // Upload file to S3
                    Storage::put(
                        $this->folder . '/' . $uniqueName,
                        file_get_contents($fileUpload)
                    );

                    // Create a record in the files table
                    $file = new File([
                        'file_path' => $filePath, // Assuming 'file_path' is the full path in the bucket
                        'file_name' => $fileName,
                        'file_extension' => $extension,
                        'file_size' => $fileSize,
                        'fileable_field' => $fileKey, // Assuming this field denotes the purpose or type of the file
                    ]);

                    $resource->$fileKey()->save($file);
                }
            }
        }

        if ($this->folder == 'User' && $request->has('role')) {
            $resource->assignRole([$request->input('role')]);
        }

        // Check if the model has defined loadable relations
        if (method_exists($this->model, 'loadableRelations')) {
            $resource->load($this->model->loadableRelations());
        }

        return response()->json(
            ['data' => $resource ?? '', 'error' => $error ?? ''],
            $error == '' ? 200 : 400
        );
    }

    public function update(Request $request, $id)
    {
        $error = '';

        // Find the resource
        $resource = $this->model::findOrFail($id);

        // Add the $id to the request data
        $requestData = $request->all() + ['id' => $id];

        // Validate the request based on the model's rules
        $validatedData = $request->validate(
            $this->model->validationRules('update', $requestData)
        );
        // Deep merge validated data with the entire request data to preserve nested unvalidated data
        $allData = $this->deepMerge($request->all(), $validatedData);

        // Overwrite fields with their "value" if exists
        foreach ($allData as $field => $value) {
            // Check if the value is an instance of UploadedFile
            if ($value instanceof \Illuminate\Http\UploadedFile) {
                // Handle file upload separately or skip this processing
                // You might want to process file uploads differently here
                continue;
            }

            // Check if value is an array and has "value" key
            if (is_array($value) && isset($value['value'])) {
                $allData[$field] = $value['value'];
            }
        }

        // Check if the model is 'User' and the password needs hashing
        if ($this->folder == 'User' && $request->has('password')) {
            $allData['password'] = Hash::make($request->input('password'));
        }

        // Process array fields like 'integration_detail'
        foreach ($this->model->getCasts() as $field => $type) {
            if ($type === 'array') {
                foreach ($allData as $key => $value) {
                    if (strpos($key, $field . '.') === 0) {
                        // Extract the sub-key and set the value in the array
                        $subKey = substr($key, strlen($field) + 1);
                        if (
                            !isset($allData[$field]) ||
                            !is_array($allData[$field])
                        ) {
                            $allData[$field] = [];
                        }
                        $allData[$field][$subKey] = $value;

                        // Remove the original key-value pair from $allData
                        unset($allData[$key]);
                    }
                }
            } elseif (
                ($type === 'datetime' || $type === 'date') &&
                isset($allData[$field])
            ) {
                // Convert the field using Carbon
                $allData[$field] = Carbon::createFromTimestamp(
                    strtotime($allData[$field])
                );
            } elseif ($type === 'boolean' && isset($allData[$field])) {
                $allData[$field] = $allData[$field] === true ? 1 : 0;
            }
        }

        // Update the resource
        $resource->update($allData);

        // Initialize an array to hold many-to-many relationships
        $manyToManyRelations = [];

        // Load necessary relations to avoid N+1 problems
        $this->model->loadMissing($this->model->loadableRelations());

        foreach ($this->model->loadableRelations() as $relation) {
            // Remove everything after ':' if it exists
            $relation =
                strpos($relation, ':') !== false
                    ? explode(':', $relation)[0]
                    : $relation;

            // Skip the relation if it contains a dot ('.')
            if (strpos($relation, '.') !== false) {
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
                            return $item['id'] ?? $item['value'];
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
        if ($request->has('key') && $request->has('file_relationship')) {
            $relationshipMethod = $request->input('file_relationship');
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
                'fileable_field' =>
                    $request->filled('fileable_field') &&
                    $request->has('fileable_field')
                        ? $request->input('fileable_field')
                        : $request->input('file_relationship'),
            ]);

            // Dynamically attach the file to the resource
            if (
                $resource->$relationshipMethod() instanceof
                \Illuminate\Database\Eloquent\Relations\MorphOne
            ) {
                $resource->$relationshipMethod()->delete();
            }
            $resource->$relationshipMethod()->save($file);
        }

        if (
            $request->has('uploadedFiles') &&
            count($request->input('uploadedFiles')) > 0
        ) {
            $uploadedFiles = $request->input('uploadedFiles');

            if (!isset($uploadedFiles[0])) {
                $uploadedFiles = [$uploadedFiles];
            }

            $relationshipMethod = $uploadedFiles[0]['fileable_field'] ?? null;

            if ($relationshipMethod) {
                // Get current files associated with the resource
                $existingFiles = $resource->$relationshipMethod()->get();

                // Track filenames from the uploadedFiles request
                $uploadedFilenames = array_map(function ($file) {
                    return $file['filename'] ?? ($file['file_name'] ?? null);
                }, $uploadedFiles);

                // Filter out null values in case neither key exists
                $uploadedFilenames = array_filter($uploadedFilenames);

                // Delete files not in uploadedFiles
                foreach ($existingFiles as $file) {
                    if (!in_array($file->file_name, $uploadedFilenames)) {
                        $file->delete(); // Remove the file record
                        Storage::delete($file->file_path); // Remove the physical file
                    }
                }

                // Process the uploaded files
                foreach ($uploadedFiles as $uploadedFile) {
                    if (
                        isset(
                            $uploadedFile['key'],
                            $uploadedFile['file_relationship']
                        ) &&
                        $uploadedFile['key'] &&
                        $uploadedFile['file_relationship']
                    ) {
                        $relationshipMethod =
                            $uploadedFile['file_relationship'];
                        $unique_name =
                            $uploadedFile['uuid'] .
                            '.' .
                            $uploadedFile['extension'];
                        $path = $this->folder . '/' . $unique_name;

                        // Check if the file already exists in the resource
                        if (
                            !$existingFiles->contains(
                                'file_name',
                                $uploadedFile['filename'] ??
                                    $uploadedFile['file_name']
                            )
                        ) {
                            // Copy the file if it exists in the storage
                            if (Storage::exists($uploadedFile['key'])) {
                                Storage::copy(
                                    $uploadedFile['key'],
                                    str_replace(
                                        'tmp/',
                                        $this->folder . '/',
                                        $uploadedFile['key']
                                    ) .
                                        '.' .
                                        $uploadedFile['extension']
                                );

                                $file = new File([
                                    'file_path' => $path,
                                    'file_name' =>
                                        $uploadedFile['filename'] ??
                                        $uploadedFile['file_name'],
                                    'file_extension' =>
                                        $uploadedFile['extension'],
                                    'file_size' =>
                                        $uploadedFile['filesize'] ??
                                        $uploadedFile['file_size'],
                                    'fileable_field' =>
                                        $uploadedFile['fileable_field'] ??
                                        $uploadedFile['file_relationship'],
                                ]);

                                // Attach the file to the resource
                                $resource->$relationshipMethod()->save($file);
                            }
                        }
                    }
                }
            }
        } else {
            if (method_exists($resource, 'files') && $request->has('files')) {
                $uploadedFiles = $request->input('files');

                // Get current files associated with the resource
                $existingFiles = $resource->files()->get();

                // Track filenames from the uploadedFiles request
                $uploadedFilenames = array_map(function ($file) {
                    return $file['filename'] ?? ($file['file_name'] ?? null);
                }, $uploadedFiles);

                // Filter out null values in case neither key exists
                $uploadedFilenames = array_filter($uploadedFilenames);

                // Delete files not in uploadedFiles
                foreach ($existingFiles as $file) {
                    if (!in_array($file->file_name, $uploadedFilenames)) {
                        $file->delete(); // Remove the file record
                        Storage::delete($file->file_path); // Remove the physical file
                    }
                }
            }
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
                    $uniqueName = Str::uuid() . '.' . $extension; // You can also use \Str::random(40) for a random string
                    $filePath = $this->folder . '/' . $uniqueName;

                    // Upload file to S3
                    Storage::put(
                        $this->folder . '/' . $uniqueName,
                        file_get_contents($fileUpload)
                    );

                    // Create a record in the files table
                    $file = new File([
                        'file_path' => $filePath, // Assuming 'file_path' is the full path in the bucket
                        'file_name' => $fileName,
                        'file_extension' => $extension,
                        'file_size' => $fileSize,
                        'fileable_field' => $fileKey, // Assuming this field denotes the purpose or type of the file
                    ]);

                    if (
                        $resource->$fileKey() instanceof
                        \Illuminate\Database\Eloquent\Relations\MorphOne
                    ) {
                        $resource->$fileKey()->delete();
                    }
                    $resource->$fileKey()->save($file);
                }
            }
        }

        if ($this->folder == 'User' && $request->has('role')) {
            $resource->syncRoles([$request->input('role')]);
        }

        // Check if the model has defined loadable relations
        if (method_exists($this->model, 'loadableRelations')) {
            $resource->load($this->model->loadableRelations());
        }

        return response()->json(
            ['data' => $resource ?? '', 'error' => $error ?? ''],
            $error == '' ? 200 : 400
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
                // Recursively merge arrays
                $original[$key] = $this->deepMerge($original[$key], $value);
            } elseif (is_null($value)) {
                // Handle null values specifically if needed
                unset($original[$key]);
            } else {
                // Override original value
                $original[$key] = $value;
            }
        }
        return $original;
    }

    public function updateGallery(Request $request, $id)
    {
        $error = '';

        // Find the resource
        $resource = $this->model::findOrFail($id);

        $validated = $request->validate([
            'key' => ['required'],
            'uuid' => ['required'],
            'extension' => ['required'],
            'filename' => ['required'],
            'fileable_field' => ['required'],
            'fileable_type' => ['required'],
        ]);

        if ($request->filled('key')) {
            $filePath =
                str_replace('tmp/', '', $request->input('key')) .
                '.' .
                $request->input('extension');
            $destinationPath =
                $this->original .
                '/' .
                $request->input('uuid') .
                '.' .
                $request->input('extension');

            Storage::copy($request->input('key'), $destinationPath);

            $nextOrder = File::where('fileable_id', $resource->id)
                ->where('fileable_field', $request->input('fileable_field'))
                ->where('fileable_type', $request->input('fileable_type'))
                ->max('sort_order');

            $file = new File([
                'fileable_field' => $request->input('fileable_field'),
                'file_path' => $filePath,
                'file_name' => $request->input('filename'),
                'file_extension' => $request->input('extension'),
                'file_size' => $request->input('filename'),
                'sort_order' => $nextOrder + 1,
            ]);

            $resource->{$request->input('fileable_field')}()->save($file);
        }

        return response()->json(
            ['data' => $resource ?? '', 'error' => $error ?? ''],
            $error == '' ? 200 : 400
        );
    }

    public function destroy($id)
    {
        $item = $this->model::findOrFail($id);
        $item->delete();

        return response()->json(['error' => ''], 200);
    }
}
