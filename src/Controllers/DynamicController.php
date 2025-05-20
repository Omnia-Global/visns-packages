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
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Visnsstudio\VisnsPackages\Exceptions\JsonValidationException;

use Carbon\Carbon;

class DynamicController extends \App\Http\Controllers\Controller
{
    protected $model;
    protected $folder;

    /**
     * Validate the request data and return validated data.
     * Throws JsonValidationException instead of ValidationException for API requests.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  array  $rules
     * @param  array  $messages
     * @param  array  $customAttributes
     * @return array
     *
     * @throws \Visnsstudio\VisnsPackages\Exceptions\JsonValidationException
     */
    protected function validateRequest(
        Request $request,
        array $rules,
        array $messages = [],
        array $customAttributes = []
    ) {
        try {
            return $request->validate($rules, $messages, $customAttributes);
        } catch (ValidationException $e) {
            throw new JsonValidationException(
                $e->validator,
                $e->response,
                $e->errorBag
            );
        }
    }

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

        $validated = $this->validateRequest($request, [
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
        $validated = $this->validateRequest($request, [
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

    /**
     * Check if a column exists in the model's table
     *
     * @param string $column The column name to check
     * @return bool Whether the column exists
     */
    protected function isValidColumn($column)
    {
        // If model is not set, we can't validate
        if (!$this->model) {
            return true;
        }

        // Skip validation for JSON path expressions
        if (strpos($column, '->') !== false) {
            return true;
        }

        // Skip validation for raw SQL expressions
        if ($column instanceof \Illuminate\Database\Query\Expression) {
            return true;
        }

        // Skip validation for special cases
        if (in_array($column, ['*', 'id'])) {
            return true;
        }

        try {
            // Check if the column exists in the table
            return Schema::hasColumn($this->model->getTable(), $column);
        } catch (\Exception $e) {
            // If there's an error checking the column, log it and return true to avoid breaking functionality
            Log::warning(
                "Error checking if column {$column} exists: " . $e->getMessage()
            );
            return true;
        }
    }

    protected function applyConditionBasedOnOperator($query, $condition, $value)
    {
        $operator = $condition['operator'] ?? '=';
        $id = $condition['id'] ?? null;
        $whereHas = $condition['whereHas'] ?? [];
        $type = $condition['type'] ?? null;
        $orKey = $condition['orKey'] ?? null;

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

        // Handle 'now' value for date type
        if ($type === 'date' && $value === 'now') {
            $value = Carbon::now()
                ->setTimezone(config('app.timezone'))
                ->format('Y-m-d');
        }

        $fieldParts = explode('.', $id);
        $jsonField = array_shift($fieldParts);
        $jsonPath = '$.' . implode('.', $fieldParts);

        // Skip column validation for whereHas conditions
        $skipColumnValidation = !empty($whereHas);

        // Validate column only if not skipping validation and not a relationship
        if (!$skipColumnValidation && !$this->isValidColumn($jsonField)) {
            // Column doesn't exist, so don't apply the condition
            Log::info(
                "Skipping where condition for invalid column: {$jsonField} in table: {$this->model->getTable()}"
            );
            return;
        }

        $applyCondition = function ($query) use (
            $operator,
            $jsonField,
            $jsonPath,
            $value,
            $type
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
                    if ($type === 'date') {
                        $query->whereDate($jsonField, '>', $value);
                    } else {
                        $query->where($jsonField, '>', $value);
                    }
                    break;
                case 'gte':
                    if ($type === 'date') {
                        $query->whereDate($jsonField, '>=', $value);
                    } else {
                        $query->where($jsonField, '>=', $value);
                    }
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
                    if ($type === 'date') {
                        $query->whereDate($jsonField, '<', $value);
                    } else {
                        $query->where($jsonField, '<', $value);
                    }
                    break;
                case 'lte':
                    if ($type === 'date') {
                        $query->whereDate($jsonField, '<=', $value);
                    } else {
                        $query->where($jsonField, '<=', $value);
                    }
                    break;
                default:
                    if ($type === 'date') {
                        $query->whereDate($jsonField, $operator, $value);
                    } else {
                        $query->where($jsonField, $value);
                    }
                    break;
            }
        };

        // Create a function to apply the condition with orKey if needed
        $applyConditionWithOrKey = function ($q) use (
            $orKey,
            $value,
            $applyCondition
        ) {
            if ($orKey) {
                // Validate orKey column if provided
                $isValidOrKey = $this->isValidColumn($orKey);

                if (!$isValidOrKey) {
                    Log::info(
                        "Skipping orWhere condition for invalid column: {$orKey} in table: {$this->model->getTable()}"
                    );
                }

                if ($isValidOrKey) {
                    // Create a nested where clause with OR condition
                    $q->where(function ($subQ) use (
                        $orKey,
                        $value,
                        $applyCondition
                    ) {
                        // Apply the original condition
                        $applyCondition($subQ);

                        // Apply the OR condition with the orKey field
                        $subQ->orWhere($orKey, $value);
                    });
                } else {
                    // If orKey is invalid, just apply the main condition
                    $applyCondition($q);
                }
            } else {
                // Apply the condition directly if no orKey
                $applyCondition($q);
            }
        };

        if (!empty($whereHas)) {
            // Apply conditions with whereHas if provided
            // Ensure whereHas is treated as an array, even if it's a single string
            $relations = is_string($whereHas) ? [$whereHas] : $whereHas;

            // Recursive function to process relationships
            $applyNestedWhereHas = function (
                $query,
                $relations,
                $conditionFunc
            ) use (&$applyNestedWhereHas) {
                $relation = array_shift($relations); // Get the first relation
                if (!is_string($relation)) {
                    throw new \InvalidArgumentException(
                        'Relation must be a string.'
                    );
                }

                $query->whereHas($relation, function ($subQuery) use (
                    $relations,
                    $conditionFunc,
                    $applyNestedWhereHas
                ) {
                    if (empty($relations)) {
                        // No more nested relations, apply the condition
                        // Note: We're not validating columns in related models
                        // as it would require complex logic to determine the related model's table
                        $conditionFunc($subQuery);
                    } else {
                        // Recursively process the remaining relations
                        $applyNestedWhereHas(
                            $subQuery,
                            $relations,
                            $conditionFunc
                        );
                    }
                });
            };

            // Apply the recursive function for the relationships with the orKey-aware condition function
            $applyNestedWhereHas($query, $relations, $applyConditionWithOrKey);
        } else {
            // Apply the condition directly if no whereHas
            $applyConditionWithOrKey($query);
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
        $validatedData = $this->validateRequest(
            $request,
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
                    // Handle various boolean representations (true, 1, "1", "true", "yes", "on")
                    $value = $allData[$field];
                    // Explicitly check for string "1" and other common true values
                    if (
                        is_string($value) &&
                        ($value === '1' ||
                            strtolower($value) === 'true' ||
                            strtolower($value) === 'yes' ||
                            strtolower($value) === 'on')
                    ) {
                        $allData[$field] = 1;
                    } else {
                        // Use filter_var as a fallback for other cases
                        $allData[$field] = filter_var(
                            $value,
                            FILTER_VALIDATE_BOOLEAN
                        )
                            ? 1
                            : 0;
                    }
                } else {
                    $allData[$field] = 0;
                }
            }
        }

        // Extract nested objects that might be relationships
        $nestedRelationships = [];
        foreach ($allData as $key => $value) {
            // Check if the value is an array (object) but not a Laravel collection or array of objects
            if (
                is_array($value) &&
                !isset($value[0]) &&
                !isset($value['value'])
            ) {
                // Check if this key corresponds to a relationship method in the model
                if (method_exists($this->model, $key)) {
                    // Get the relationship instance
                    $relation = $this->model->$key();

                    // Handle different relationship types
                    if (
                        $relation instanceof
                            \Illuminate\Database\Eloquent\Relations\HasOne ||
                        $relation instanceof
                            \Illuminate\Database\Eloquent\Relations\BelongsTo
                    ) {
                        // Store the relationship data for processing after model creation
                        $nestedRelationships[$key] = $value;

                        // For BelongsTo, we need to create the related model first
                        if (
                            $relation instanceof
                            \Illuminate\Database\Eloquent\Relations\BelongsTo
                        ) {
                            $relatedModel = $relation->getRelated();

                            // Update the related model with the nested object data
                            foreach ($value as $attr => $attrValue) {
                                $relatedModel->$attr = $attrValue;
                            }

                            // Save the related model
                            $relatedModel->save();

                            // Update the foreign key in the main data
                            $foreignKey = $relation->getForeignKeyName();
                            $allData[$foreignKey] = $relatedModel->getKey();
                        }

                        // Remove the nested object from the data array to prevent errors
                        unset($allData[$key]);
                    }
                }
            }
        }

        // Create a new resource
        $resource = $this->model::create($allData);

        // Process HasOne relationships after the main model is created
        foreach ($nestedRelationships as $key => $value) {
            $relation = $resource->$key();

            if (
                $relation instanceof
                \Illuminate\Database\Eloquent\Relations\HasOne
            ) {
                $relatedModel = $relation->getRelated();

                // Update the related model with the nested object data
                foreach ($value as $attr => $attrValue) {
                    $relatedModel->$attr = $attrValue;
                }

                // Save the related model through the relationship
                $resource->$key()->save($relatedModel);
            }
        }

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
                'fileable_field' => $request->filled('fileable_field')
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
                    isset(
                        $uploadedFile['key'],
                        $uploadedFile['file_relationship']
                    )
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
                            'fileable_field' => !empty(
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
        $validatedData = $this->validateRequest(
            $request,
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
                // Handle various boolean representations (true, 1, "1", "true", "yes", "on")
                $value = $allData[$field];
                // Explicitly check for string "1" and other common true values
                if (
                    is_string($value) &&
                    ($value === '1' ||
                        strtolower($value) === 'true' ||
                        strtolower($value) === 'yes' ||
                        strtolower($value) === 'on')
                ) {
                    $allData[$field] = 1;
                } else {
                    // Use filter_var as a fallback for other cases
                    $allData[$field] = filter_var(
                        $value,
                        FILTER_VALIDATE_BOOLEAN
                    )
                        ? 1
                        : 0;
                }
            }
        }

        // Process nested objects that might be relationships
        $this->processNestedRelationships($resource, $allData);

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
                'fileable_field' => $request->filled('fileable_field')
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
                                    'fileable_field' => !empty(
                                        $uploadedFile['fileable_field']
                                    )
                                        ? $uploadedFile['fileable_field']
                                        : $uploadedFile['file_relationship'],
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
     * Process nested objects in the input data that might be relationships
     *
     * @param Model $resource The model instance being updated
     * @param array &$allData The input data array (passed by reference to modify it)
     * @return void
     */
    private function processNestedRelationships($resource, array &$allData)
    {
        foreach ($allData as $key => $value) {
            // Check if the value is an array (object) but not a Laravel collection or array of objects
            if (
                is_array($value) &&
                !isset($value[0]) &&
                !isset($value['value'])
            ) {
                // Check if this key corresponds to a relationship method in the model
                if (method_exists($resource, $key)) {
                    // Get the relationship instance
                    $relation = $resource->$key();

                    // Handle different relationship types
                    if (
                        $relation instanceof
                            \Illuminate\Database\Eloquent\Relations\HasOne ||
                        $relation instanceof
                            \Illuminate\Database\Eloquent\Relations\BelongsTo
                    ) {
                        // Get the related model (or create a new one if it doesn't exist)
                        if (
                            $relation instanceof
                            \Illuminate\Database\Eloquent\Relations\BelongsTo
                        ) {
                            // For BelongsTo, we need to get the related model first
                            $relatedModel = $resource->$key;
                            if (!$relatedModel) {
                                // Create a new instance of the related model
                                $relatedModel = $relation->getRelated();
                            }
                        } else {
                            // For HasOne, we can use the relation directly
                            $relatedModel =
                                $resource->$key ?? $relation->getRelated();
                        }

                        // Update the related model with the nested object data
                        foreach ($value as $attr => $attrValue) {
                            $relatedModel->$attr = $attrValue;
                        }

                        // Save the related model
                        $relatedModel->save();

                        // For BelongsTo, we need to update the foreign key on the parent model
                        if (
                            $relation instanceof
                            \Illuminate\Database\Eloquent\Relations\BelongsTo
                        ) {
                            $foreignKey = $relation->getForeignKeyName();
                            $allData[$foreignKey] = $relatedModel->getKey();
                        }

                        // Remove the nested object from the data array to prevent errors
                        unset($allData[$key]);
                    }
                }
            }
        }
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

        $validated = $this->validateRequest($request, [
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

    /**
     * Merge two models by their IDs.
     *
     * This method finds two models by their IDs and merges the target model into the source model.
     * The source model is updated in the database, while the target model is soft deleted.
     * All relationships from the target model are moved to the source model.
     * The source model's attributes are prioritized, only missing data will be filled from the target.
     *
     * Required request parameters:
     * - target_id: The ID of the target model (will be merged INTO source and then soft deleted)
     * - source_id: The ID of the source model (will be kept and updated)
     *
     * Optional request parameters:
     * - relationships: Array of relationship names to merge
     * - attributes: Array of specific attributes to merge (if empty, all attributes are merged)
     * - exclude: Array of attributes to exclude from merging
     * - overwriteWithNull: Whether to overwrite non-null values with null values (default: false)
     * - mergeTimestamps: Whether to merge timestamp fields (default: false)
     *
     * @param \Illuminate\Http\Request $request The request object containing model IDs and merge options
     * @return \Illuminate\Http\JsonResponse
     */
    public function mergeModels(Request $request)
    {
        // Extract target_id and source_id, handling the case where target_id might be in target_id.value
        $targetId = $request->has('target_id.value')
            ? $request->input('target_id.value')
            : $request->input('target_id');
        $sourceId = $request->has('source_id.value')
            ? $request->input('source_id.value')
            : $request->input('source_id');

        // Validate the extracted IDs
        $validator = Validator::make(
            ['target_id' => $targetId, 'source_id' => $sourceId],
            [
                'target_id' =>
                    'required|exists:' . $this->model->getTable() . ',id',
                'source_id' =>
                    'required|exists:' . $this->model->getTable() . ',id',
            ]
        );

        if ($validator->fails()) {
            throw new JsonValidationException($validator);
        }

        // Find the target and source models
        $target = $this->model::findOrFail($targetId);
        $source = $this->model::findOrFail($sourceId);

        // Extract options from the request
        $options = [
            'relationships' => $request->input('relationships', []),
            'attributes' => $request->input('attributes', []),
            'exclude' => $request->input('exclude', [
                'id',
                'created_at',
                'updated_at',
                'deleted_at',
            ]),
            'overwriteWithNull' => $request->input('overwriteWithNull', false),
            'mergeTimestamps' => $request->input('mergeTimestamps', false),
            'prioritizeSource' => true, // Always prioritize source model attributes
        ];

        try {
            // Get all loadable relationships if not specified
            if (
                empty($options['relationships']) &&
                method_exists($this->model, 'loadableRelations')
            ) {
                $options['relationships'] = $this->model->loadableRelations();

                // Clean up relationship names (remove everything after ':' and skip relations with dots)
                $options['relationships'] = array_filter(
                    array_map(function ($relation) {
                        // Remove everything after ':' if it exists
                        $relation =
                            strpos($relation, ':') !== false
                                ? explode(':', $relation)[0]
                                : $relation;

                        // Skip relations with dots
                        return strpos($relation, '.') === false
                            ? $relation
                            : null;
                    }, $options['relationships'])
                );
            }

            // Merge the target model INTO the source model (note the reversed order)
            $mergedModel = $this->merge($source, $target, $options);

            // Save the merged model (source)
            $mergedModel->save();

            // Handle relationships that need to be saved after the main model
            if (!empty($options['relationships'])) {
                foreach ($options['relationships'] as $relationship) {
                    if (method_exists($mergedModel, $relationship)) {
                        $relation = $mergedModel->$relationship();
                        $relatedModel = $mergedModel->getRelation(
                            $relationship
                        );

                        if (
                            $relation instanceof
                            \Illuminate\Database\Eloquent\Relations\HasOne
                        ) {
                            if ($relatedModel) {
                                $relation->save($relatedModel);
                            }
                        } elseif (
                            $relation instanceof
                            \Illuminate\Database\Eloquent\Relations\BelongsTo
                        ) {
                            if ($relatedModel) {
                                $relatedModel->save();
                                $foreignKey = $relation->getForeignKeyName();
                                $mergedModel->$foreignKey = $relatedModel->getKey();
                                $mergedModel->save();
                            }
                        } elseif (
                            $relation instanceof
                            \Illuminate\Database\Eloquent\Relations\HasMany
                        ) {
                            if ($relatedModel && $relatedModel->count() > 0) {
                                foreach ($relatedModel as $model) {
                                    $relation->save($model);
                                }
                            }
                        } elseif (
                            $relation instanceof
                            \Illuminate\Database\Eloquent\Relations\BelongsToMany
                        ) {
                            if ($relatedModel && $relatedModel->count() > 0) {
                                try {
                                    // Create a sync array that includes pivot data
                                    $syncData = [];

                                    // Add relations with their pivot data
                                    foreach ($relatedModel as $model) {
                                        $id = $model->id;
                                        if (!is_null($id) && $id !== '') {
                                            // Check if the model has a pivot attribute
                                            if (isset($model->pivot)) {
                                                $pivotData = $model->pivot->getAttributes();
                                                // Remove keys that are automatically managed
                                                unset(
                                                    $pivotData[
                                                        $relation->getForeignPivotKeyName()
                                                    ]
                                                );
                                                unset(
                                                    $pivotData[
                                                        $relation->getRelatedPivotKeyName()
                                                    ]
                                                );
                                                unset($pivotData['created_at']);
                                                unset($pivotData['updated_at']);

                                                $syncData[$id] = $pivotData;
                                            } else {
                                                // No pivot data, just sync the ID
                                                $syncData[$id] = [];
                                            }
                                        }
                                    }

                                    // Only sync if we have valid IDs
                                    if (!empty($syncData)) {
                                        // Sync to the target with pivot data
                                        $relation->sync($syncData);
                                    }
                                } catch (\Exception $e) {
                                    // Log the error for debugging
                                    Log::error(
                                        'Error syncing BelongsToMany relationship in mergeModels: ' .
                                            $e->getMessage()
                                    );
                                    Log::error(
                                        'Relationship method: ' . $relationship
                                    );

                                    // Try a simpler approach as fallback
                                    try {
                                        // Get IDs only
                                        $ids = $relatedModel
                                            ->pluck('id')
                                            ->toArray();
                                        $ids = array_filter($ids, function (
                                            $id
                                        ) {
                                            return !is_null($id) && $id !== '';
                                        });

                                        if (!empty($ids)) {
                                            $relation->sync($ids);
                                        }
                                    } catch (\Exception $innerE) {
                                        Log::error(
                                            'Fallback sync also failed: ' .
                                                $innerE->getMessage()
                                        );
                                    }
                                }
                            }
                        }
                    }
                }
            }

            // Move all relationships from target to source
            $this->moveRelationships($target, $mergedModel);

            // Soft delete the target model if it uses SoftDeletes trait
            if (
                in_array(
                    'Illuminate\Database\Eloquent\SoftDeletes',
                    class_uses_recursive($target)
                )
            ) {
                $target->delete(); // This will soft delete
            } else {
                // If the model doesn't use SoftDeletes, we'll check if it has a 'deleted_at' column
                // and manually set it if it exists
                if (Schema::hasColumn($target->getTable(), 'deleted_at')) {
                    $target->deleted_at = now();
                    $target->save();
                }
            }

            // Reload the model with its relationships
            if (method_exists($this->model, 'loadableRelations')) {
                $mergedModel->load($this->model->loadableRelations());
            }

            return response()->json(
                [
                    'data' => $mergedModel,
                    'error' => '',
                ],
                200
            );
        } catch (\Exception $e) {
            return response()->json(
                [
                    'data' => null,
                    'error' => 'Error merging models: ' . $e->getMessage(),
                ],
                500
            );
        }
    }

    /**
     * Move all relationships from one model to another.
     *
     * @param \Illuminate\Database\Eloquent\Model $from The model to move relationships from
     * @param \Illuminate\Database\Eloquent\Model $to The model to move relationships to
     * @return void
     */
    private function moveRelationships($from, $to)
    {
        // Get all relationship methods from the model
        $relationMethods = [];

        // If the model has loadableRelations method, use it to get relationships
        if (method_exists($from, 'loadableRelations')) {
            $relationMethods = $from->loadableRelations();

            // Clean up relationship names (remove everything after ':' and skip relations with dots)
            $relationMethods = array_filter(
                array_map(function ($relation) {
                    // Remove everything after ':' if it exists
                    $relation =
                        strpos($relation, ':') !== false
                            ? explode(':', $relation)[0]
                            : $relation;

                    // Skip relations with dots
                    return strpos($relation, '.') === false ? $relation : null;
                }, $relationMethods)
            );
        }

        // Process each relationship
        foreach ($relationMethods as $method) {
            if (method_exists($from, $method) && method_exists($to, $method)) {
                $fromRelation = $from->$method();
                $toRelation = $to->$method();

                // Handle different relationship types
                if (
                    $fromRelation instanceof
                    \Illuminate\Database\Eloquent\Relations\HasOne
                ) {
                    // For HasOne, get the related model and update its foreign key
                    $relatedModel = $from->$method;
                    if ($relatedModel) {
                        $foreignKey = $fromRelation->getForeignKeyName();
                        $relatedModel->$foreignKey = $to->getKey();
                        $relatedModel->save();
                    }
                } elseif (
                    $fromRelation instanceof
                    \Illuminate\Database\Eloquent\Relations\HasMany
                ) {
                    // For HasMany, update foreign keys for all related models
                    $relatedModels = $from->$method;
                    if ($relatedModels && $relatedModels->count() > 0) {
                        $foreignKey = $fromRelation->getForeignKeyName();
                        foreach ($relatedModels as $model) {
                            $model->$foreignKey = $to->getKey();
                            $model->save();
                        }
                    }
                } elseif (
                    $fromRelation instanceof
                    \Illuminate\Database\Eloquent\Relations\BelongsToMany
                ) {
                    // For BelongsToMany, get all pivot records and sync them to the target
                    $relatedModels = $from
                        ->$method()
                        ->withPivot()
                        ->get();

                    if ($relatedModels && $relatedModels->count() > 0) {
                        try {
                            // Get existing relations on the target with their pivot data
                            $existingRelations = $to
                                ->$method()
                                ->withPivot()
                                ->get();

                            // Create a sync array that includes pivot data
                            $syncData = [];

                            // Add existing relations first
                            foreach ($existingRelations as $relation) {
                                $id = $relation->id;
                                if (!is_null($id) && $id !== '') {
                                    $pivotData = $relation->pivot->getAttributes();
                                    // Remove keys that are automatically managed
                                    unset(
                                        $pivotData[
                                            $fromRelation->getForeignPivotKeyName()
                                        ]
                                    );
                                    unset(
                                        $pivotData[
                                            $fromRelation->getRelatedPivotKeyName()
                                        ]
                                    );
                                    unset($pivotData['created_at']);
                                    unset($pivotData['updated_at']);

                                    $syncData[$id] = $pivotData;
                                }
                            }

                            // Add relations from the source model, overriding if they already exist
                            foreach ($relatedModels as $relation) {
                                $id = $relation->id;
                                if (!is_null($id) && $id !== '') {
                                    $pivotData = $relation->pivot->getAttributes();
                                    // Remove keys that are automatically managed
                                    unset(
                                        $pivotData[
                                            $fromRelation->getForeignPivotKeyName()
                                        ]
                                    );
                                    unset(
                                        $pivotData[
                                            $fromRelation->getRelatedPivotKeyName()
                                        ]
                                    );
                                    unset($pivotData['created_at']);
                                    unset($pivotData['updated_at']);

                                    $syncData[$id] = $pivotData;
                                }
                            }

                            // Only sync if we have valid IDs
                            if (!empty($syncData)) {
                                // Sync to the target with pivot data
                                $toRelation->sync($syncData);
                            }
                        } catch (\Exception $e) {
                            // Log the error for debugging
                            Log::error(
                                'Error syncing BelongsToMany relationship: ' .
                                    $e->getMessage()
                            );
                            Log::error('Relationship method: ' . $method);

                            // Try a simpler approach as fallback
                            try {
                                // Get IDs only
                                $newIds = $relatedModels
                                    ->pluck('id')
                                    ->toArray();
                                $newIds = array_filter($newIds, function ($id) {
                                    return !is_null($id) && $id !== '';
                                });

                                if (!empty($newIds)) {
                                    $toRelation->sync($newIds);
                                }
                            } catch (\Exception $innerE) {
                                Log::error(
                                    'Fallback sync also failed: ' .
                                        $innerE->getMessage()
                                );
                            }
                        }
                    }
                } elseif (
                    $fromRelation instanceof
                    \Illuminate\Database\Eloquent\Relations\MorphMany
                ) {
                    // For MorphMany, update morph type and ID for all related models
                    $relatedModels = $from->$method;
                    if ($relatedModels && $relatedModels->count() > 0) {
                        $morphType = $fromRelation->getMorphType();
                        $foreignKey = $fromRelation->getForeignKeyName();
                        foreach ($relatedModels as $model) {
                            $model->$morphType = get_class($to);
                            $model->$foreignKey = $to->getKey();
                            $model->save();
                        }
                    }
                } elseif (
                    $fromRelation instanceof
                    \Illuminate\Database\Eloquent\Relations\MorphOne
                ) {
                    // For MorphOne, update morph type and ID for the related model
                    $relatedModel = $from->$method;
                    if ($relatedModel) {
                        // Delete existing relation on target if it exists
                        $to->$method()->delete();

                        // Update the morph type and ID
                        $morphType = $fromRelation->getMorphType();
                        $foreignKey = $fromRelation->getForeignKeyName();
                        $relatedModel->$morphType = get_class($to);
                        $relatedModel->$foreignKey = $to->getKey();
                        $relatedModel->save();
                    }
                }
            }
        }
    }

    /**
     * Merge attributes and relationships from a source model into a target model.
     *
     * This method merges the attributes from the source model into the target model,
     * optionally merging specified relationships as well. It follows these rules:
     * - Primary keys are preserved in the target model
     * - Timestamps are preserved in the target model unless overridden
     * - Relationships can be optionally merged
     * - By default, source model attributes are prioritized, only missing data in source will be filled from target
     * - When prioritizeSource is false, target model attributes are prioritized (original behavior)
     *
     * @param \Illuminate\Database\Eloquent\Model $target The target model to merge into
     * @param \Illuminate\Database\Eloquent\Model $source The source model to merge from
     * @param array $options Additional options for the merge:
     *                      - 'relationships' => array of relationship names to merge
     *                      - 'attributes' => array of specific attributes to merge (if empty, all attributes are merged)
     *                      - 'exclude' => array of attributes to exclude from merging
     *                      - 'overwriteWithNull' => whether to overwrite non-null values with null values (default: false)
     *                      - 'mergeTimestamps' => whether to merge timestamp fields (default: false)
     *                      - 'prioritizeSource' => whether to prioritize source model attributes (default: false)
     * @return \Illuminate\Database\Eloquent\Model The merged target model (not saved)
     */
    public function merge($target, $source, array $options = [])
    {
        // Default options
        $defaultOptions = [
            'relationships' => [],
            'attributes' => [],
            'exclude' => ['id', 'created_at', 'updated_at', 'deleted_at'],
            'overwriteWithNull' => false,
            'mergeTimestamps' => false,
            'prioritizeSource' => false, // Default to original behavior
        ];

        // Merge provided options with defaults
        $options = array_merge($defaultOptions, $options);

        // If mergeTimestamps is true, remove timestamp fields from exclude list
        if ($options['mergeTimestamps']) {
            $options['exclude'] = array_diff($options['exclude'], [
                'created_at',
                'updated_at',
            ]);
        }

        // Get all attributes from both models
        $sourceAttributes = $source->getAttributes();
        $targetAttributes = $target->getAttributes();

        // Determine which attributes to merge
        $attributesToMerge = !empty($options['attributes'])
            ? array_intersect(
                array_keys($sourceAttributes),
                $options['attributes']
            )
            : array_keys($sourceAttributes);

        // Remove excluded attributes
        $attributesToMerge = array_diff(
            $attributesToMerge,
            $options['exclude']
        );

        // Merge attributes based on prioritization
        if ($options['prioritizeSource']) {
            // Prioritize source model - only fill missing data in source from target
            foreach ($attributesToMerge as $attribute) {
                $sourceValue = $sourceAttributes[$attribute];
                $targetValue = $targetAttributes[$attribute] ?? null;

                // If source value is null or empty and target has a value, use target value
                if (
                    (is_null($sourceValue) || $sourceValue === '') &&
                    !is_null($targetValue) &&
                    $targetValue !== ''
                ) {
                    $target->$attribute = $targetValue;
                }
                // Otherwise keep the source value
            }
        } else {
            // Original behavior - merge source into target
            foreach ($attributesToMerge as $attribute) {
                $sourceValue = $sourceAttributes[$attribute];

                // Skip if the source value is null and we're not overwriting with nulls
                if (
                    is_null($sourceValue) &&
                    !$options['overwriteWithNull'] &&
                    !is_null($target->$attribute)
                ) {
                    continue;
                }

                $target->$attribute = $sourceValue;
            }
        }

        // Merge relationships if specified
        foreach ($options['relationships'] as $relationship) {
            if (
                method_exists($source, $relationship) &&
                method_exists($target, $relationship)
            ) {
                $relation = $source->$relationship();

                // Handle different relationship types
                if (
                    $relation instanceof
                        \Illuminate\Database\Eloquent\Relations\HasOne ||
                    $relation instanceof
                        \Illuminate\Database\Eloquent\Relations\BelongsTo
                ) {
                    // For HasOne or BelongsTo, get the related model
                    $relatedModel = $source->$relationship;

                    if ($relatedModel) {
                        // Clone the related model to avoid modifying the original
                        $clonedModel = $relatedModel->replicate();

                        // Set the relationship on the target after saving
                        // Note: This will need to be handled after saving the target
                        $target->setRelation($relationship, $clonedModel);
                    }
                } elseif (
                    $relation instanceof
                    \Illuminate\Database\Eloquent\Relations\HasMany
                ) {
                    // For HasMany, get the collection of related models
                    $relatedModels = $source->$relationship;

                    if ($relatedModels && $relatedModels->count() > 0) {
                        // Clone each related model
                        $clonedModels = $relatedModels->map(function ($model) {
                            return $model->replicate();
                        });

                        // Set the relationship on the target
                        $target->setRelation($relationship, $clonedModels);
                    }
                } elseif (
                    $relation instanceof
                    \Illuminate\Database\Eloquent\Relations\BelongsToMany
                ) {
                    // For BelongsToMany, get the collection of related models with pivot data
                    $relatedModels = $source
                        ->$relationship()
                        ->withPivot()
                        ->get();

                    if ($relatedModels && $relatedModels->count() > 0) {
                        // Filter out models with null or empty IDs
                        $validModels = $relatedModels->filter(function (
                            $model
                        ) {
                            return !is_null($model->id) && $model->id !== '';
                        });

                        // Only set the relationship if we have valid models
                        if ($validModels->count() > 0) {
                            // We'll handle the actual sync after saving the model
                            $target->setRelation($relationship, $validModels);
                        }
                    }
                }
            }
        }

        return $target;
    }
}
