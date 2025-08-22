<?php

namespace Visnsstudio\VisnsPackages\Controllers;

use Visnsstudio\VisnsPackages\Models\File;

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
use Illuminate\Support\Facades\Cache;

class DynamicController extends \App\Http\Controllers\Controller
{
    protected $model;
    protected $folder;
    protected $original;

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
        // Check App\Models first, then package models as fallback
        $appModelClass = $modelName ? "App\\Models\\{$modelName}" : null;
        $packageModelClass = $modelName ? "Visnsstudio\\VisnsPackages\\Models\\{$modelName}" : null;

        // Check if the model class exists and instantiate it if it does
        if ($appModelClass && class_exists($appModelClass)) {
            $this->model = new $appModelClass();
            $this->folder = $modelName;
            $this->original = $modelNameSegment;
        } elseif ($packageModelClass && class_exists($packageModelClass)) {
            $this->model = new $packageModelClass();
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

    /**
     * Intelligently detect available fields for dropdown functionality
     *
     * @return array
     */
    private function detectAvailableFields()
    {
        $cacheKey = 'dropdown_fields_' . get_class($this->model);

        return Cache::remember($cacheKey, 3600, function () {
            $tableName = $this->model->getTable();
            $columns = Schema::getColumnListing($tableName);

            $config = config('visns-packages.dropdown_fields', []);

            $detectedFields = [
                'id' => $this->detectIdField(
                    $columns,
                    $config['id_fields'] ?? ['id', 'uuid', 'slug', 'code']
                ),
                'label' => $this->detectLabelField($columns, $config),
                'sort' => $this->detectSortField(
                    $columns,
                    $config['sort_fields'] ?? [
                        'label',
                        'name',
                        'title',
                        'firstname',
                        'created_at',
                    ]
                ),
                'name_combination' => $this->detectNameCombination(
                    $columns,
                    $config['name_combinations'] ?? []
                ),
                'available_columns' => $columns,
            ];

            return $detectedFields;
        });
    }

    /**
     * Detect the best ID field for the model
     *
     * @param array $columns
     * @param array $idFields
     * @return string
     */
    private function detectIdField($columns, $idFields)
    {
        foreach ($idFields as $field) {
            if (in_array($field, $columns)) {
                return $field;
            }
        }

        return $columns[0] ?? 'id'; // fallback to first column or 'id'
    }

    /**
     * Detect the best single label field for the model
     *
     * @param array $columns
     * @param array $config
     * @return string|null
     */
    private function detectLabelField($columns, $config)
    {
        $labelFields = $config['label_fields'] ?? [
            'label',
            'name',
            'title',
            'full_name',
            'display_name',
        ];

        foreach ($labelFields as $field) {
            if (in_array($field, $columns)) {
                return $field;
            }
        }

        return null;
    }

    /**
     * Detect the best name field combination for concatenation
     *
     * @param array $columns
     * @param array $combinations
     * @return array|null
     */
    private function detectNameCombination($columns, $combinations)
    {
        foreach ($combinations as $combination) {
            $allFieldsExist = true;
            foreach ($combination as $field) {
                if (!in_array($field, $columns)) {
                    $allFieldsExist = false;
                    break;
                }
            }

            if ($allFieldsExist) {
                return $combination;
            }
        }

        return null;
    }

    /**
     * Detect the best field for sorting
     *
     * @param array $columns
     * @param array $sortFields
     * @return string
     */
    private function detectSortField($columns, $sortFields)
    {
        foreach ($sortFields as $field) {
            if (in_array($field, $columns)) {
                return $field;
            }
        }

        return $columns[0] ?? 'id'; // fallback to first column or 'id'
    }

    /**
     * Build a smart label for a model instance
     *
     * @param mixed $item
     * @param array $detectedFields
     * @return string
     */
    private function buildSmartLabel($item, $detectedFields)
    {
        // Try single label field first
        if (
            $detectedFields['label'] &&
            !empty($item->{$detectedFields['label']})
        ) {
            return trim($item->{$detectedFields['label']});
        }

        // Try name combination
        if ($detectedFields['name_combination']) {
            $nameParts = [];
            foreach ($detectedFields['name_combination'] as $field) {
                $value = $item->{$field} ?? '';
                if (!empty($value)) {
                    $nameParts[] = trim($value);
                }
            }

            if (!empty($nameParts)) {
                return implode(' ', $nameParts);
            }
        }

        // Fallback to any text-like field
        $textFields = ['description', 'title', 'subject', 'code', 'reference'];
        foreach ($textFields as $field) {
            if (
                in_array($field, $detectedFields['available_columns']) &&
                !empty($item->{$field})
            ) {
                return trim($item->{$field});
            }
        }

        // Final fallback - use ID or first available field
        $idField = $detectedFields['id'];
        return $item->{$idField} ?? 'N/A';
    }

    private function getSortParams(Request $request, $fields)
    {
        $sortField =
            $request->input('orderBy') ??
            ($request->input('sortBy') ?? ($fields[1] ?? null));
        $sort = $request->input('order') ?? ($request->input('sort') ?? 'asc');

        // Use intelligent sort field detection if no sort field specified
        if (is_null($sortField)) {
            $detectedFields = $this->detectAvailableFields();
            $sortField = $detectedFields['sort'];
        }

        // Verify the sort field exists in the table
        if ($sortField && !Schema::hasColumn($this->model->getTable(), $sortField)) {
            $detectedFields = $this->detectAvailableFields();
            $sortField = $detectedFields['sort'];
        }

        return [$sortField, $sort];
    }

    public function dropdown(Request $request)
    {
        $data = [];

        // Detect available fields intelligently
        $detectedFields = $this->detectAvailableFields();

        // Handle fields parameter - respect provided fields if they exist in database
        $providedFields = $request->input('fields');
        if ($providedFields && is_array($providedFields) && count($providedFields) >= 2) {
            $tableName = $this->model->getTable();
            $providedIdField = $providedFields[0];
            $providedLabelField = $providedFields[1];
            
            // Check if all provided fields exist in the database or as appended fields
            $allFieldsExist = true;
            $appendedFields = $this->model->getAppends();
            
            foreach ($providedFields as $field) {
                $fieldExists = Schema::hasColumn($tableName, $field) || in_array($field, $appendedFields);
                if (!$fieldExists) {
                    $allFieldsExist = false;
                    break;
                }
            }
            
            if ($allFieldsExist) {
                // Override detected fields with provided fields
                $detectedFields['id'] = $providedIdField;
                $detectedFields['label'] = $providedLabelField;
                $fields = $providedFields;
            } else {
                // Fall back to intelligent field detection
                $fields = [$detectedFields['id'], $detectedFields['label'] ?? $detectedFields['id']];
            }
        } else {
            // Use intelligent field detection as default
            $fields = [$detectedFields['id'], $detectedFields['label'] ?? $detectedFields['id']];
        }

        // Get intelligent sorting parameters
        [$sortField, $sort] = $this->getSortParams($request, $fields);
        
        // If fields are provided but no explicit orderBy/sortBy, default to the second field (label field)
        if ($providedFields && $allFieldsExist && isset($providedFields[1]) && 
            !$request->has('orderBy') && !$request->has('sortBy')) {
            $sortField = $providedFields[1];
        }

        // Build query with intelligent ordering
        $query = method_exists($this->model, 'scopeCustomOrder')
            ? $this->model::customOrder($sortField, $sort)
            : $this->model::orderBy($sortField, $sort);

        // Apply standard filters
        if (Schema::hasColumn($this->model->getTable(), 'hide')) {
            $query->where('hide', 0);
        }

        // Apply search functionality similar to table function
        $searchTerm = $request->input('search');
        if ($searchTerm) {
            if ($this->shouldUseMeilisearch($this->model)) {
                try {
                    $this->applyMeilisearchFilter($query, $searchTerm);
                } catch (\Exception $e) {
                    Log::warning('Meilisearch search failed in dropdown, falling back to custom search: ' . $e->getMessage());
                    if (method_exists($this->model, 'scopeCustomSearch')) {
                        $query->customSearch($searchTerm);
                    }
                }
            } elseif (method_exists($this->model, 'scopeCustomSearch')) {
                $query->customSearch($searchTerm);
            }
        }

        // Apply exclude_id parameter to filter out specific records
        $excludeId = $request->input('exclude_id');
        if ($excludeId) {
            $query->where('id', '!=', $excludeId);
        }

        // Apply where conditions
        if ($request->has('where') && $request->filled('where')) {
            foreach ($request->input('where') as $condition) {
                switch ($condition['id']) {
                    case 'role':
                        if ($this->folder == 'User') {
                            $query->role($condition['value']);
                        }
                        break;
                    case 'async':
                        if ($this->shouldUseMeilisearch($this->model)) {
                            try {
                                $this->applyMeilisearchFilter(
                                    $query,
                                    $condition['value']
                                );
                            } catch (\Exception $e) {
                                Log::warning(
                                    'Meilisearch search failed in dropdown, falling back to custom search: ' .
                                        $e->getMessage()
                                );
                                if (
                                    method_exists(
                                        $this->model,
                                        'scopeCustomSearch'
                                    )
                                ) {
                                    $query->customSearch($condition['value']);
                                }
                            }
                        } elseif (
                            method_exists($this->model, 'scopeCustomSearch')
                        ) {
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
                            $condition['value'] ?? ''
                        );
                        break;
                }
            }
        }

        // Process each item with intelligent label building
        foreach ($query->get() as $item) {
            $itemData = [];

            // Set ID field intelligently
            $idField = $detectedFields['id'];
            $itemData[$idField] = $item->{$idField};

            // Build label - use provided label field if specified, otherwise use smart label
            if ($providedFields && isset($providedFields[1]) && Schema::hasColumn($this->model->getTable(), $providedFields[1])) {
                // Use the provided label field directly
                $itemData['label'] = $item->{$providedFields[1]} ?? '';
            } else {
                // Use smart label building
                $itemData['label'] = $this->buildSmartLabel($item, $detectedFields);
            }

            // Add all requested fields to ensure they are returned in the response
            foreach ($fields as $field) {
                if ($field !== $idField && $field !== 'label' && !isset($itemData[$field])) {
                    $itemData[$field] = $item->{$field} ?? null;
                }
            }

            $data[] = $itemData;
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
                        if ($this->shouldUseMeilisearch($this->model)) {
                            try {
                                $this->applyMeilisearchFilter(
                                    $query,
                                    $condition['value']
                                );
                            } catch (\Exception $e) {
                                Log::warning(
                                    'Meilisearch search failed in dropdown, falling back to custom search: ' .
                                        $e->getMessage()
                                );
                                if (
                                    method_exists(
                                        $this->model,
                                        'scopeCustomSearch'
                                    )
                                ) {
                                    $query->customSearch($condition['value']);
                                }
                            }
                        } elseif (
                            method_exists($this->model, 'scopeCustomSearch')
                        ) {
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
        try {
            \Log::info('DynamicController::list() started', [
                'model' => $this->model,
                'request_data' => $request->all(),
                'method' => $request->method(),
                'url' => $request->url()
            ]);

            $query = $this->initializeQuery();
            \Log::info('DynamicController::list() - Query initialized', [
                'model' => $this->model,
                'query_sql' => $query->toSql()
            ]);

            $this->applyRelationships($query);
            \Log::info('DynamicController::list() - Relationships applied', [
                'model' => $this->model,
                'query_sql' => $query->toSql()
            ]);

            $this->applyCustomOrderAndSearch($query, $request);
            \Log::info('DynamicController::list() - Custom order and search applied', [
                'model' => $this->model,
                'sortBy' => $request->input('sortBy'),
                'sort' => $request->input('sort'),
                'search' => $request->input('search'),
                'query_sql' => $query->toSql()
            ]);

            $this->applyFilters($query, $request);
            \Log::info('DynamicController::list() - Filters applied', [
                'model' => $this->model,
                'where_conditions' => $request->input('where'),
                'query_sql' => $query->toSql()
            ]);

            // Check if pagination is requested via 'take' parameter
            if ($request->has('take')) {
                $result = $this->paginateAndRespond($query, $request->input('take'));
                \Log::info('DynamicController::list() - Query executed with pagination', [
                    'model' => $this->model,
                    'result_type' => get_class($result),
                    'result_status' => $result instanceof \Illuminate\Http\JsonResponse ? $result->getStatusCode() : 'unknown',
                    'pagination_size' => $request->input('take')
                ]);
            } else {
                // Backward compatibility: return all records when no 'take' parameter
                $result = $this->respondWithAll($query);
                \Log::info('DynamicController::list() - Query executed without pagination (all records)', [
                    'model' => $this->model,
                    'result_type' => get_class($result),
                    'result_status' => $result instanceof \Illuminate\Http\JsonResponse ? $result->getStatusCode() : 'unknown'
                ]);
            }

            return $result;

        } catch (\Exception $e) {
            \Log::error('DynamicController::list() - Exception occurred', [
                'model' => $this->model,
                'error_message' => $e->getMessage(),
                'error_code' => $e->getCode(),
                'error_file' => $e->getFile(),
                'error_line' => $e->getLine(),
                'stack_trace' => $e->getTraceAsString(),
                'request_data' => $request->all()
            ]);

            throw $e;
        }
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
        try {
            \Log::info('DynamicController::applyCustomOrderAndSearch() started', [
                'model' => $this->model,
                'sortBy' => $request->input('sortBy'),
                'sort' => $request->input('sort'),
                'search' => $request->input('search'),
                'has_custom_order' => method_exists($this->model, 'scopeCustomOrder'),
                'has_custom_search' => method_exists($this->model, 'scopeCustomSearch')
            ]);

            if (method_exists($this->model, 'scopeCustomOrder')) {
                \Log::info('DynamicController::applyCustomOrderAndSearch() - Applying custom order', [
                    'model' => $this->model,
                    'sortBy' => $request->input('sortBy'),
                    'sort' => $request->input('sort')
                ]);

                $query->customOrder(
                    $request->input('sortBy'),
                    $request->input('sort')
                );
            }

            // Enhanced search logic with Meilisearch support
            $searchTerm = $request->input('search');
            if ($searchTerm) {
                \Log::info('DynamicController::applyCustomOrderAndSearch() - Applying search', [
                    'model' => $this->model,
                    'search_term' => $searchTerm,
                    'should_use_meilisearch' => $this->shouldUseMeilisearch($this->model)
                ]);

                if ($this->shouldUseMeilisearch($this->model)) {
                    try {
                        \Log::info('DynamicController::applyCustomOrderAndSearch() - Using Meilisearch');
                        $this->applyMeilisearchFilter($query, $searchTerm);
                    } catch (\Exception $e) {
                        // Log the error and fallback to custom search
                        \Log::warning('Meilisearch search failed, falling back to custom search', [
                            'model' => $this->model,
                            'error' => $e->getMessage(),
                            'search_term' => $searchTerm
                        ]);
                        
                        if (method_exists($this->model, 'scopeCustomSearch')) {
                            \Log::info('DynamicController::applyCustomOrderAndSearch() - Using custom search fallback');
                            $query->customSearch($searchTerm);
                        }
                    }
                } elseif (method_exists($this->model, 'scopeCustomSearch')) {
                    \Log::info('DynamicController::applyCustomOrderAndSearch() - Using custom search');
                    $query->customSearch($searchTerm);
                }
            }

            \Log::info('DynamicController::applyCustomOrderAndSearch() completed', [
                'model' => $this->model,
                'final_query_sql' => $query->toSql()
            ]);

        } catch (\Exception $e) {
            \Log::error('DynamicController::applyCustomOrderAndSearch() - Exception occurred', [
                'model' => $this->model,
                'error_message' => $e->getMessage(),
                'error_code' => $e->getCode(),
                'error_file' => $e->getFile(),
                'error_line' => $e->getLine(),
                'search_term' => $request->input('search'),
                'sortBy' => $request->input('sortBy'),
                'sort' => $request->input('sort')
            ]);

            throw $e;
        }
    }

    protected function applyFilters($query, Request $request)
    {
        try {
            \Log::info('DynamicController::applyFilters() started', [
                'model' => $this->model,
                'has_where' => $request->has('where'),
                'where_filled' => $request->filled('where'),
                'where_conditions' => $request->input('where', [])
            ]);

            if ($request->has('where') && $request->filled('where')) {
                $conditions = $request->input('where');
                \Log::info('DynamicController::applyFilters() - Processing conditions', [
                    'model' => $this->model,
                    'condition_count' => count($conditions),
                    'conditions' => $conditions
                ]);

                foreach ($conditions as $index => $condition) {
                    \Log::info('DynamicController::applyFilters() - Applying condition', [
                        'model' => $this->model,
                        'condition_index' => $index,
                        'condition' => $condition
                    ]);

                    $this->applyFilterCondition($query, $condition);
                }
            }

            \Log::info('DynamicController::applyFilters() completed', [
                'model' => $this->model,
                'final_query_sql' => $query->toSql()
            ]);

        } catch (\Exception $e) {
            \Log::error('DynamicController::applyFilters() - Exception occurred', [
                'model' => $this->model,
                'error_message' => $e->getMessage(),
                'error_code' => $e->getCode(),
                'error_file' => $e->getFile(),
                'error_line' => $e->getLine(),
                'where_conditions' => $request->input('where', [])
            ]);

            throw $e;
        }
    }

    protected function applyFilterCondition($query, $condition)
    {
        // NEW: Check for group conditions first
        if (isset($condition['group']) && $condition['group']) {
            $this->applyGroupConditions($query, $condition);
            return;
        }

        // EXISTING: All current logic preserved exactly
        $value = $condition['value'] ?? null;
        $casts = $this->model->getCasts();

        if (isset($condition['id']) && isset($casts[$condition['id']])) {
            $value = $this->castValue($value, $casts[$condition['id']]);
        }

        $this->applyConditionBasedOnOperator($query, $condition, $value);
    }

    protected function applyGroupConditions($query, $groupCondition)
    {
        $operator = strtoupper($groupCondition['operator'] ?? 'AND');
        $conditions = $groupCondition['conditions'] ?? [];
        
        \Log::info('DynamicController::applyGroupConditions() - Processing group', [
            'operator' => $operator,
            'condition_count' => count($conditions),
            'conditions' => $conditions
        ]);
        
        if (empty($conditions)) {
            return;
        }
        
        // Apply grouped conditions with OR/AND logic
        $query->where(function ($subQuery) use ($conditions, $operator) {
            foreach ($conditions as $index => $condition) {
                \Log::info('DynamicController::applyGroupConditions() - Processing condition', [
                    'index' => $index,
                    'operator' => $operator,
                    'condition' => $condition
                ]);
                
                if ($index === 0) {
                    // First condition - always use 'where'
                    $this->applySingleCondition($subQuery, $condition);
                } else {
                    // Subsequent conditions - use OR/AND based on operator
                    if ($operator === 'OR') {
                        $subQuery->orWhere(function ($orQuery) use ($condition) {
                            $this->applySingleCondition($orQuery, $condition);
                        });
                    } else {
                        // Default to AND
                        $this->applySingleCondition($subQuery, $condition);
                    }
                }
            }
        });
        
        \Log::info('DynamicController::applyGroupConditions() - Completed group processing', [
            'query_sql' => $query->toSql()
        ]);
    }

    protected function applySingleCondition($query, $condition)
    {
        // Handle nested groups
        if (isset($condition['group']) && $condition['group']) {
            $this->applyGroupConditions($query, $condition);
            return;
        }
        
        // Handle whereDoesntHave case
        if (isset($condition['whereDoesntHave'])) {
            $relation = $condition['whereDoesntHave'];
            if (is_string($relation)) {
                $query->whereDoesntHave($relation);
            } elseif (is_array($relation)) {
                foreach ($relation as $rel) {
                    $query->whereDoesntHave($rel);
                }
            }
            return;
        }
        
        // Handle regular conditions using existing logic
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
        // Handle null, empty, or invalid values early
        if ($this->isInvalidDateValue($value)) {
            return null;
        }

        // 1. If $value is an array with 'start' and 'end' keys
        if (is_array($value) && isset($value['start'], $value['end'])) {
            if (
                $value['start'] !== '' &&
                $value['end'] !== '' &&
                !$this->isInvalidDateValue($value['start']) &&
                !$this->isInvalidDateValue($value['end'])
            ) {
                try {
                    $start = Carbon::parse(
                        $value['start'],
                        config('app.timezone')
                    )
                        ->startOfDay()
                        ->setTimezone('UTC');
                    $end = Carbon::parse($value['end'], config('app.timezone'))
                        ->endOfDay()
                        ->setTimezone('UTC');

                    return [$start, $end];
                } catch (\Exception $e) {
                    \Log::warning('Failed to parse date range', [
                        'start' => $value['start'],
                        'end' => $value['end'],
                        'error' => $e->getMessage(),
                    ]);
                    return null;
                }
            }
            return null;
        }

        // 2. If the value is the string 'now'
        if ($value === 'now') {
            return Carbon::now('UTC');
        }

        // 3. If $value is a scalar (not an array or object)
        if (!is_array($value) && !is_object($value)) {
            try {
                return Carbon::parse(
                    $value,
                    config('app.timezone')
                )->setTimezone('UTC');
            } catch (\Exception $e) {
                \Log::warning('Failed to parse date value', [
                    'value' => $value,
                    'error' => $e->getMessage(),
                ]);
                return null;
            }
        }

        // 4. If $value is an array or object (without 'start'/'end'), loop through each element
        $result = [];
        foreach ((array) $value as $key => $item) {
            if (!$this->isInvalidDateValue($item)) {
                try {
                    $result[$key] = Carbon::parse(
                        $item,
                        config('app.timezone')
                    )->setTimezone('UTC');
                } catch (\Exception $e) {
                    \Log::warning('Failed to parse date array item', [
                        'key' => $key,
                        'value' => $item,
                        'error' => $e->getMessage(),
                    ]);
                    // Skip invalid items rather than including null
                }
            }
        }
        return empty($result) ? null : $result;
    }

    /**
     * Check if a value is invalid for date parsing
     *
     * @param mixed $value
     * @return bool
     */
    protected function isInvalidDateValue($value)
    {
        // Handle null values
        if (is_null($value)) {
            return true;
        }

        // Handle empty strings
        if ($value === '') {
            return true;
        }

        // Handle boolean false
        if ($value === false) {
            return true;
        }

        // Handle very short strings that are likely not dates (but allow 'now')
        // Note: We removed the check for '0' and 0 since they could be valid timestamps or date representations
        if (
            is_string($value) &&
            strlen(trim($value)) < 4 &&
            $value !== 'now' &&
            $value !== '0'
        ) {
            return true;
        }

        return false;
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

        // NEW: Special case for whereDoesntHave (no id/value needed)
        $whereDoesntHave = $condition['whereDoesntHave'] ?? [];
        if (empty($id) && !empty($whereDoesntHave)) {
            if (is_string($whereDoesntHave)) {
                $query->whereDoesntHave($whereDoesntHave);
            } elseif (is_array($whereDoesntHave)) {
                foreach ($whereDoesntHave as $relation) {
                    $query->whereDoesntHave($relation);
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
                case 'not_null':
                    $query->whereNotNull($jsonField);
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

        // Convert paginator to array and add column metadata
        $response = $paginator->toArray();
        $response['columns_metadata'] = $this->getColumnsMetadata();

        // Return the enhanced response as JSON
        return response()->json($response, 200);
    }

    protected function respondWithAll($query)
    {
        try {
            \Log::info('DynamicController::respondWithAll() started', [
                'model' => $this->model,
                'query_sql' => $query->toSql(),
                'query_bindings' => $query->getBindings()
            ]);

            $data = $query->get();
            \Log::info('DynamicController::respondWithAll() - Data retrieved', [
                'model' => $this->model,
                'data_count' => $data->count(),
                'memory_usage' => memory_get_usage(true)
            ]);

            // Check if the model has excludedFields method
            if (method_exists($this->model, 'excludedFields')) {
                $excludedFields = $this->model->excludedFields();
                \Log::info('DynamicController::respondWithAll() - Applying excluded fields', [
                    'model' => $this->model,
                    'excluded_fields' => $excludedFields
                ]);

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

            \Log::info('DynamicController::respondWithAll() - Response prepared successfully', [
                'model' => $this->model,
                'final_data_count' => is_countable($data) ? count($data) : 'unknown'
            ]);

            return response()->json($data, 200);

        } catch (\Exception $e) {
            \Log::error('DynamicController::respondWithAll() - Exception occurred', [
                'model' => $this->model,
                'error_message' => $e->getMessage(),
                'error_code' => $e->getCode(),
                'error_file' => $e->getFile(),
                'error_line' => $e->getLine(),
                'stack_trace' => $e->getTraceAsString(),
                'query_sql' => $query->toSql() ?? 'N/A',
                'query_bindings' => $query->getBindings() ?? []
            ]);

            throw $e;
        }
    }

    /**
     * Get column metadata including sortability information
     *
     * @return array
     */
    protected function getColumnsMetadata()
    {
        $metadata = [];
        
        // Get a sample instance to analyze columns
        $sampleInstance = new $this->model;
        
        // Get all possible columns from the model
        $columns = $this->getAllModelColumns($sampleInstance);
        
        foreach ($columns as $column) {
            $metadata[$column] = [
                'sortable' => $this->isColumnSortable($sampleInstance, $column),
                'virtual' => $this->isVirtualColumn($sampleInstance, $column)
            ];
        }
        
        return $metadata;
    }

    /**
     * Get all columns from the model including database fields and virtual fields
     *
     * @param object $modelInstance
     * @return array
     */
    protected function getAllModelColumns($modelInstance)
    {
        $columns = [];
        
        // Get database columns
        try {
            $table = $modelInstance->getTable();
            $databaseColumns = \DB::getSchemaBuilder()->getColumnListing($table);
            $columns = array_merge($columns, $databaseColumns);
        } catch (\Exception $e) {
            // Fallback if schema inspection fails
            $columns = array_keys($modelInstance->getAttributes());
        }
        
        // Add appended attributes (virtual columns)
        $appendedAttributes = $modelInstance->getAppends();
        $columns = array_merge($columns, $appendedAttributes);
        
        // Add any visible virtual columns that might be in a sample data set
        if (method_exists($modelInstance, 'toArray')) {
            try {
                // Create a temporary instance with some data to get all possible keys
                $tempInstance = new $this->model;
                if (method_exists($tempInstance, 'newCollection')) {
                    $sampleData = $this->model::limit(1)->get()->first();
                    if ($sampleData) {
                        $arrayKeys = array_keys($sampleData->toArray());
                        $columns = array_merge($columns, $arrayKeys);
                    }
                }
            } catch (\Exception $e) {
                // Continue with existing columns if sample data fails
            }
        }
        
        // Remove duplicates and sort
        $columns = array_unique($columns);
        sort($columns);
        
        return $columns;
    }

    /**
     * Check if a column is sortable (not virtual)
     *
     * @param object $modelInstance
     * @param string $column
     * @return bool
     */
    protected function isColumnSortable($modelInstance, $column)
    {
        // Virtual columns are not sortable
        if ($this->isVirtualColumn($modelInstance, $column)) {
            return false;
        }
        
        // Check if the model has custom sorting logic that might handle this column
        if (method_exists($modelInstance, 'scopeCustomOrder')) {
            // If the model uses HasRelationshipSorting trait, 
            // it can handle relationship-based sorting
            if (method_exists($modelInstance, 'isVirtualColumn')) {
                return !$modelInstance->isVirtualColumn($column);
            }
        }
        
        return true;
    }

    /**
     * Check if a column is virtual (computed/appended)
     *
     * @param object $modelInstance
     * @param string $column
     * @return bool
     */
    protected function isVirtualColumn($modelInstance, $column)
    {
        // If the model has the HasRelationshipSorting trait, use its method
        if (method_exists($modelInstance, 'isVirtualColumn')) {
            return $modelInstance->isVirtualColumn($column);
        }
        
        // Fallback logic if trait is not available
        // Check if the field is in the model's appends array
        if (in_array($column, $modelInstance->getAppends())) {
            return true;
        }
        
        // Check if the field has a getXAttribute method (accessor)
        $accessorMethod = 'get' . \Str::studly($column) . 'Attribute';
        if (method_exists($modelInstance, $accessorMethod)) {
            return true;
        }
        
        // Check against a list of common virtual field patterns
        $commonVirtualFields = [
            'full_name', 'display_name', 'customer_names', 'description',
            'computed_', 'calculated_', 'virtual_', 'formatted_'
        ];
        
        foreach ($commonVirtualFields as $pattern) {
            if (str_contains($column, $pattern)) {
                return true;
            }
        }
        
        return false;
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
                // Handle date and datetime fields properly
                if ($type === 'date') {
                    // For date fields, preserve as date without timezone conversion
                    try {
                        $allData[$field] = Carbon::parse($allData[$field])->format('Y-m-d');
                    } catch (\Exception $e) {
                        // Keep original value if parsing fails
                    }
                } else {
                    // For datetime fields, use proper timezone handling
                    $dateValue = $this->handleDateValue($allData[$field]);
                    if (is_array($dateValue)) {
                        // If it's a date range, use the start date
                        $allData[$field] = $dateValue[0];
                    } else {
                        $allData[$field] = $dateValue;
                    }
                }
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
            if ($request->has($relationship)) {
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

                // Always sync the relationships (even with empty array to clear all relationships)
                $resource->$relationship()->sync($syncData);
            }
        }

        // Handle file upload if 'key' is present in the request
        if ($request->has('key') && $request->has('file_relationship')) {
            $relationshipMethod = $request->input('file_relationship');
            $unique_name =
                $request->input('uuid') . '.' . $request->input('extension');
            $path = $this->folder . '/' . $unique_name;

            // Use the project's configured disk (respecting host project configuration)
            $disk = config('filesystems.default', 's3');
            
            Storage::disk($disk)->copy(
                $request->input('key'),
                $path
            );

            $file = new File([
                'file_path' => $path,
                'file_name' => $request->input('filename'),
                'file_extension' => $request->input('extension'),
                'file_size' => $request->input('filesize'),
                'file_description' => $request->input('file_description') ?? null,
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

                    $disk = config('filesystems.default', 's3');
                    if (Storage::disk($disk)->exists($uploadedFile['key'])) {
                        Storage::disk($disk)->copy(
                            $uploadedFile['key'],
                            $path
                        );

                        $file = new File([
                            'file_path' => $path,
                            'file_name' => $uploadedFile['filename'],
                            'file_extension' => $uploadedFile['extension'],
                            'file_size' => $uploadedFile['filesize'],
                            'file_description' => $uploadedFile['file_description'] ?? null,
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

                    // Upload file to configured disk
                    $disk = config('filesystems.default', 's3');
                    Storage::disk($disk)->put(
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
                // Handle date and datetime fields properly
                if ($type === 'date') {
                    // For date fields, preserve as date without timezone conversion
                    try {
                        $allData[$field] = Carbon::parse($allData[$field])->format('Y-m-d');
                    } catch (\Exception $e) {
                        // Keep original value if parsing fails
                    }
                } else {
                    // For datetime fields, use proper timezone handling
                    $dateValue = $this->handleDateValue($allData[$field]);
                    if (is_array($dateValue)) {
                        // If it's a date range, use the start date
                        $allData[$field] = $dateValue[0];
                    } else {
                        $allData[$field] = $dateValue;
                    }
                }
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
            if ($request->has($relationship)) {
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
                }

                // Always sync the relationship (even with empty array to clear all relationships)
                $resource->$relationship()->sync($ids);
            }
        }

        // Handle file upload if 'key' is present in the request
        if ($request->has('key') && $request->has('file_relationship')) {
            $relationshipMethod = $request->input('file_relationship');
            $unique_name =
                $request->input('uuid') . '.' . $request->input('extension');
            $path = $this->folder . '/' . $unique_name;

            // Use the project's configured disk (respecting host project configuration)
            $disk = config('filesystems.default', 's3');
            
            Storage::disk($disk)->copy(
                $request->input('key'),
                $path
            );

            $file = new File([
                'file_path' => $path,
                'file_name' => $request->input('filename'),
                'file_extension' => $request->input('extension'),
                'file_size' => $request->input('filesize'),
                'file_description' => $request->input('file_description') ?? null,
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
                        $disk = config('filesystems.default', 's3');
                        Storage::disk($disk)->delete($file->file_path); // Remove the physical file
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
                            $disk = config('filesystems.default', 's3');
                            if (Storage::disk($disk)->exists($uploadedFile['key'])) {
                                Storage::disk($disk)->copy(
                                    $uploadedFile['key'],
                                    $path
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
                                    'file_description' => $uploadedFile['file_description'] ?? null,
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
                        $disk = config('filesystems.default', 's3');
                        Storage::disk($disk)->delete($file->file_path); // Remove the physical file
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

                    // Upload file to configured disk
                    $disk = config('filesystems.default', 's3');
                    Storage::disk($disk)->put(
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

        // Refresh the model to clear any cached relationships and reload fresh data from database
        $resource->refresh();

        // Check if the model has defined loadable relations and load them with fresh data
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

            $disk = config('filesystems.default', 's3');
            Storage::disk($disk)->copy($request->input('key'), $destinationPath);

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
            'field_overrides' => $request->input('field_overrides', []), // Explicit field value overrides
        ];

        // Wrap everything in a try-catch block
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

            // Load relationships on both models before merging
            if (!empty($options['relationships'])) {
                $source->load($options['relationships']);
                $target->load($options['relationships']);
                \Log::info("Loaded relationships before merge", [
                    'relationships' => $options['relationships'],
                    'source_id' => $source->id,
                    'target_id' => $target->id
                ]);
            }

            // Merge the target model INTO the source model (note the reversed order)
            $mergedModel = $this->merge($source, $target, $options);

            // Save the merged model (source)
            $mergedModel->save();

            // Handle relationships that need to be saved after the main model
            if (!empty($options['relationships'])) {
                foreach ($options['relationships'] as $relationship) {
                    // Skip if the relationship method doesn't exist
                    if (!method_exists($mergedModel, $relationship)) {
                        Log::warning(
                            "Relationship method '$relationship' does not exist on the model. Skipping."
                        );
                        continue;
                    }

                    try {
                        // Special handling for contact_types relationship
                        if ($relationship === 'contact_types') {
                            Log::info(
                                'Special handling for contact_types relationship in the loop'
                            );
                            continue; // Skip normal processing for contact_types
                        }

                        // Get the relationship instance
                        $relation = $mergedModel->$relationship();

                        // Check if the relationship is loaded
                        if (!$mergedModel->relationLoaded($relationship)) {
                            Log::warning(
                                "Relationship '$relationship' is not loaded. Skipping."
                            );
                            continue;
                        }

                        // Get the related model(s)
                        $relatedModel = $mergedModel->getRelation(
                            $relationship
                        );
                    } catch (\Exception $e) {
                        Log::warning(
                            "Error processing relationship '$relationship': " .
                                $e->getMessage()
                        );
                        continue;
                    }

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
                                // For BelongsToMany, we want to sync all merged relationships
                                // This will replace existing relationships with the merged set
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

                                // Log what we're about to sync
                                \Log::info("Syncing BelongsToMany relationship '{$relationship}'", [
                                    'entity_id' => $mergedModel->id,
                                    'sync_data_count' => count($syncData),
                                    'sync_ids' => array_keys($syncData)
                                ]);

                                // Only sync if we have valid IDs
                                if (!empty($syncData)) {
                                    // Sync to the target with pivot data (this replaces existing)
                                    $relation->sync($syncData);
                                    \Log::info("Successfully synced {$relationship} with " . count($syncData) . " relationships");
                                } else {
                                    \Log::warning("No valid sync data for {$relationship}");
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
                                    $ids = array_filter($ids, function ($id) {
                                        return !is_null($id) && $id !== '';
                                    });

                                    if (!empty($ids)) {
                                        $relation->sync($ids);
                                        \Log::info("Fallback sync successful for {$relationship} with IDs: " . implode(', ', $ids));
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

            // Duplicate relationships from target to source instead of moving them
            try {
                $this->duplicateRelationships($target, $mergedModel);
            } catch (\Exception $e) {
                Log::error(
                    'Error in duplicateRelationships: ' . $e->getMessage()
                );
                // Continue execution even if duplicateRelationships fails
            }

            // Note: contact_types relationship is now handled in the duplicateRelationships method

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
     * Detect potential duplicate entities based on similarity matching
     *
     * Request parameters:
     * - entity_id: The ID of the entity to find duplicates for
     * - fields: Array of field names to use for similarity matching (default: ['name', 'email'])
     * - threshold: Minimum similarity percentage (default: 70)
     * - limit: Maximum number of results to return (default: 10)
     *
     * @param \Illuminate\Http\Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function detectDuplicates(Request $request)
    {
        try {
            $validator = Validator::make($request->all(), [
                'entity_id' => 'required|exists:' . $this->model->getTable() . ',id',
                'fields' => 'array',
                'threshold' => 'integer|min:0|max:100',
                'limit' => 'integer|min:1|max:100'
            ]);

            if ($validator->fails()) {
                throw new JsonValidationException($validator);
            }

            $entityId = $request->input('entity_id');
            $fields = $request->input('fields', ['name', 'email']);
            $threshold = $request->input('threshold', 70);
            $limit = $request->input('limit', 10);

            // Get the source entity
            $sourceEntity = $this->model::findOrFail($entityId);
            
            // Get all other entities (excluding the source)
            $candidates = $this->model::where('id', '!=', $entityId)->get();
            
            $duplicates = [];

            foreach ($candidates as $candidate) {
                $totalSimilarity = 0;
                $validFields = 0;

                foreach ($fields as $field) {
                    $sourceValue = $sourceEntity->$field ?? '';
                    $candidateValue = $candidate->$field ?? '';

                    // Skip empty values
                    if (empty($sourceValue) || empty($candidateValue)) {
                        continue;
                    }

                    $similarity = $this->calculateStringSimilarity($sourceValue, $candidateValue);
                    $totalSimilarity += $similarity;
                    $validFields++;
                }

                // Calculate average similarity if we have valid fields
                if ($validFields > 0) {
                    $averageSimilarity = $totalSimilarity / $validFields;
                    
                    if ($averageSimilarity >= $threshold) {
                        $duplicates[] = [
                            'entity' => $candidate,
                            'similarity' => round($averageSimilarity, 2),
                            'matched_fields' => $validFields
                        ];
                    }
                }
            }

            // Sort by similarity score (highest first)
            usort($duplicates, function($a, $b) {
                return $b['similarity'] <=> $a['similarity'];
            });

            // Limit results
            $duplicates = array_slice($duplicates, 0, $limit);

            // Extract just the entities for the response
            $entities = array_map(function($duplicate) {
                return $duplicate['entity'];
            }, $duplicates);

            return response()->json([
                'data' => $entities,
                'meta' => [
                    'total_found' => count($duplicates),
                    'threshold_used' => $threshold,
                    'fields_checked' => $fields
                ],
                'error' => ''
            ], 200);

        } catch (\Exception $e) {
            return response()->json([
                'data' => [],
                'error' => 'Error detecting duplicates: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Calculate string similarity using Levenshtein distance
     *
     * @param string $str1
     * @param string $str2
     * @return float Similarity percentage (0-100)
     */
    private function calculateStringSimilarity($str1, $str2)
    {
        // Normalize strings for comparison
        $str1 = strtolower(trim($str1));
        $str2 = strtolower(trim($str2));

        // Handle exact matches
        if ($str1 === $str2) {
            return 100.0;
        }

        // Handle empty strings
        if (empty($str1) || empty($str2)) {
            return 0.0;
        }

        // Use similar_text for percentage similarity
        similar_text($str1, $str2, $percent);
        
        // Also calculate Levenshtein distance for additional accuracy
        $maxLength = max(strlen($str1), strlen($str2));
        if ($maxLength > 0) {
            $levenshteinPercent = (1 - (levenshtein($str1, $str2) / $maxLength)) * 100;
            
            // Return the average of both methods for better accuracy
            return ($percent + $levenshteinPercent) / 2;
        }

        return $percent;
    }

    /**
     * Duplicate relationships from one model to another.
     * This method focuses on duplicating relationships rather than moving them,
     * which is simpler and less error-prone.
     *
     * @param \Illuminate\Database\Eloquent\Model $from The model to duplicate relationships from
     * @param \Illuminate\Database\Eloquent\Model $to The model to duplicate relationships to
     * @return void
     */
    private function duplicateRelationships($from, $to)
    {
        try {
            // Log the models we're working with for debugging
            Log::info(
                'Duplicating relationships from model ID: ' .
                    $from->getKey() .
                    ' to model ID: ' .
                    $to->getKey()
            );
            Log::info('From model class: ' . get_class($from));
            Log::info('To model class: ' . get_class($to));

            // Get all relationship methods from the model
            $relationMethods = [];

            // If the model has loadableRelations method, use it to get relationships
            if (method_exists($from, 'loadableRelations')) {
                $relationMethods = $from->loadableRelations();
                Log::info(
                    'Found loadableRelations: ' .
                        implode(', ', $relationMethods)
                );

                // Clean up relationship names (remove everything after ':' and skip relations with dots)
                $relationMethods = array_filter(
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
                    }, $relationMethods)
                );

                Log::info(
                    'After cleaning, relationships to process: ' .
                        implode(', ', $relationMethods)
                );
            } else {
                Log::warning(
                    'Model does not have loadableRelations method. No relationships will be duplicated.'
                );
            }

            // Special handling for contact_types relationship
            if (
                method_exists($from, 'contact_types') &&
                method_exists($to, 'contact_types')
            ) {
                try {
                    Log::info(
                        'Special handling for contact_types relationship in duplicateRelationships'
                    );

                    // Use direct DB query to get the contact_type_id values from the pivot table
                    $contactTypeIds = DB::table('contact_contact_type')
                        ->where('contact_id', $from->id)
                        ->pluck('contact_type_id')
                        ->filter()
                        ->toArray();

                    Log::info(
                        'Found contact_type_ids: ' .
                            implode(', ', $contactTypeIds ?: [])
                    );

                    // If we have IDs, attach them to the target model
                    if (!empty($contactTypeIds)) {
                        foreach ($contactTypeIds as $contactTypeId) {
                            // Check if the relationship already exists
                            $exists = DB::table('contact_contact_type')
                                ->where('contact_id', $to->id)
                                ->where('contact_type_id', $contactTypeId)
                                ->exists();

                            if (!$exists) {
                                DB::table('contact_contact_type')->insert([
                                    'contact_id' => $to->id,
                                    'contact_type_id' => $contactTypeId,
                                    'created_at' => now(),
                                    'updated_at' => now(),
                                ]);

                                Log::info(
                                    "Added contact_type_id: $contactTypeId to target model"
                                );
                            }
                        }
                    }
                } catch (\Exception $e) {
                    Log::error(
                        'Error handling contact_types relationship: ' .
                            $e->getMessage()
                    );
                }

                // Remove contact_types from the methods to process
                $relationMethods = array_diff($relationMethods, [
                    'contact_types',
                ]);
            }

            // Process each relationship
            foreach ($relationMethods as $method) {
                // Skip if the method doesn't exist on either model
                if (
                    !method_exists($from, $method) ||
                    !method_exists($to, $method)
                ) {
                    Log::warning(
                        "Relationship method '$method' does not exist on both models. Skipping."
                    );
                    continue;
                }

                try {
                    // Get the relationship instance
                    $fromRelation = $from->$method();

                    // Only handle BelongsToMany relationships
                    if (
                        $fromRelation instanceof
                        \Illuminate\Database\Eloquent\Relations\BelongsToMany
                    ) {
                        // Get the pivot table and key names
                        $pivotTable = $fromRelation->getTable();
                        $foreignPivotKey = $fromRelation->getForeignPivotKeyName();
                        $relatedPivotKey = $fromRelation->getRelatedPivotKeyName();

                        // Log the relationship details for debugging
                        Log::info(
                            "Processing BelongsToMany relationship: $method"
                        );
                        Log::info("Pivot table: $pivotTable");
                        Log::info("Foreign pivot key: $foreignPivotKey");
                        Log::info("Related pivot key: $relatedPivotKey");

                        // Get existing relationships directly from the pivot table
                        $existingPivots = DB::table($pivotTable)
                            ->where($foreignPivotKey, $from->getKey())
                            ->get();

                        Log::info(
                            'Found ' .
                                $existingPivots->count() .
                                ' existing relationships'
                        );

                        if ($existingPivots->count() > 0) {
                            // For each existing relationship, create a new one for the target model
                            foreach ($existingPivots as $pivot) {
                                try {
                                    // Convert to array
                                    $pivotData = (array) $pivot;

                                    // Get the related ID
                                    $relatedId = $pivotData[$relatedPivotKey];

                                    // Check if the relationship already exists
                                    $exists = DB::table($pivotTable)
                                        ->where($foreignPivotKey, $to->getKey())
                                        ->where($relatedPivotKey, $relatedId)
                                        ->exists();

                                    if (!$exists) {
                                        // Create a new pivot record
                                        $newPivotData = [
                                            $foreignPivotKey => $to->getKey(),
                                            $relatedPivotKey => $relatedId,
                                        ];

                                        // Add timestamps if the pivot table has them
                                        if (
                                            Schema::hasColumn(
                                                $pivotTable,
                                                'created_at'
                                            )
                                        ) {
                                            $newPivotData['created_at'] = now();
                                        }
                                        if (
                                            Schema::hasColumn(
                                                $pivotTable,
                                                'updated_at'
                                            )
                                        ) {
                                            $newPivotData['updated_at'] = now();
                                        }

                                        // Copy any additional pivot columns
                                        $pivotColumns = Schema::getColumnListing(
                                            $pivotTable
                                        );
                                        foreach ($pivotColumns as $column) {
                                            // Skip the keys we've already handled
                                            if (
                                                $column !== $foreignPivotKey &&
                                                $column !== $relatedPivotKey &&
                                                $column !== 'created_at' &&
                                                $column !== 'updated_at' &&
                                                $column !== 'id'
                                            ) {
                                                $newPivotData[$column] =
                                                    $pivotData[$column] ?? null;
                                            }
                                        }

                                        // Insert the new relationship
                                        DB::table($pivotTable)->insert(
                                            $newPivotData
                                        );

                                        Log::info(
                                            "Duplicated relationship: $method with $relatedPivotKey = $relatedId"
                                        );
                                    }
                                } catch (\Exception $e) {
                                    Log::warning(
                                        "Error duplicating pivot for $method: " .
                                            $e->getMessage()
                                    );
                                }
                            }
                        }
                    }
                } catch (\Exception $e) {
                    Log::warning(
                        "Error processing relationship $method: " .
                            $e->getMessage()
                    );
                }
            }
        } catch (\Exception $e) {
            Log::error('Error in duplicateRelationships: ' . $e->getMessage());
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
            'field_overrides' => [], // Explicit field value overrides for conflict resolution
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

        // First apply any explicit field overrides from conflict resolution
        if (!empty($options['field_overrides']) && is_array($options['field_overrides'])) {
            \Log::info("Processing field overrides", [
                'field_overrides' => $options['field_overrides'],
                'attributesToMerge' => $attributesToMerge,
                'exclude' => $options['exclude']
            ]);
            
            foreach ($options['field_overrides'] as $field => $value) {
                // Apply field override if field is in attributesToMerge OR if it's a valid model attribute
                if (in_array($field, $attributesToMerge) || array_key_exists($field, $sourceAttributes) || array_key_exists($field, $targetAttributes)) {
                    $target->$field = $value;
                    \Log::info("Applied field override for '{$field}': '{$value}'");
                } else {
                    \Log::warning("Skipped field override for '{$field}' - not found in attributes to merge or model attributes");
                }
            }
        }

        // Merge attributes based on prioritization
        if ($options['prioritizeSource']) {
            // Prioritize source model - only fill missing data in source from target
            foreach ($attributesToMerge as $attribute) {
                // Skip if this field has an explicit override (already handled above)
                if (!empty($options['field_overrides']) && array_key_exists($attribute, $options['field_overrides'])) {
                    continue;
                }
                
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
                // Skip if this field has an explicit override (already handled above)
                if (!empty($options['field_overrides']) && array_key_exists($attribute, $options['field_overrides'])) {
                    continue;
                }
                
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
                    // For HasOne or BelongsTo, prioritize source but fallback to target
                    $sourceModel = $source->$relationship;
                    $targetModel = $target->$relationship;
                    
                    // Prefer source model, but use target if source is empty
                    $selectedModel = $sourceModel ?: $targetModel;
                    
                    \Log::info("Merging HasOne/BelongsTo relationship '{$relationship}'", [
                        'has_source' => !is_null($sourceModel),
                        'has_target' => !is_null($targetModel),
                        'selected' => $selectedModel ? ($sourceModel ? 'source' : 'target') : 'none'
                    ]);

                    if ($selectedModel) {
                        // Clone the selected model to avoid modifying the original
                        $clonedModel = $selectedModel->replicate();

                        // Set the relationship on the target after saving
                        // Note: This will need to be handled after saving the target
                        $target->setRelation($relationship, $clonedModel);
                    }
                } elseif (
                    $relation instanceof
                    \Illuminate\Database\Eloquent\Relations\HasMany
                ) {
                    // For HasMany, merge related models from both source and target
                    $sourceModels = $source->$relationship;
                    $targetModels = $target->$relationship;
                    
                    // Combine models from both entities
                    $allModels = collect();
                    
                    if ($sourceModels && $sourceModels->count() > 0) {
                        // Clone source models and add to collection
                        $clonedSourceModels = $sourceModels->map(function ($model) {
                            return $model->replicate();
                        });
                        $allModels = $allModels->merge($clonedSourceModels);
                    }
                    
                    if ($targetModels && $targetModels->count() > 0) {
                        // Clone target models and add to collection
                        $clonedTargetModels = $targetModels->map(function ($model) {
                            return $model->replicate();
                        });
                        $allModels = $allModels->merge($clonedTargetModels);
                    }
                    
                    \Log::info("Merging HasMany relationship '{$relationship}'", [
                        'source_count' => $sourceModels ? $sourceModels->count() : 0,
                        'target_count' => $targetModels ? $targetModels->count() : 0,
                        'merged_count' => $allModels->count()
                    ]);

                    if ($allModels->count() > 0) {
                        // Set the merged relationship on the target
                        $target->setRelation($relationship, $allModels);
                    }
                } elseif (
                    $relation instanceof
                    \Illuminate\Database\Eloquent\Relations\BelongsToMany
                ) {
                    // For BelongsToMany, we need to merge relationships from both source and target
                    $sourceRelation = $source->$relationship();
                    $targetRelation = $target->$relationship();
                    
                    // Get relationships from both entities
                    $sourceModels = $sourceRelation->get();
                    $targetModels = $targetRelation->get();
                    
                    // Combine both collections and remove duplicates
                    $allModels = $sourceModels->merge($targetModels)->unique('id');
                    
                    \Log::info("Merging BelongsToMany relationship '{$relationship}'", [
                        'source_count' => $sourceModels->count(),
                        'target_count' => $targetModels->count(), 
                        'merged_count' => $allModels->count()
                    ]);

                    if ($allModels->count() > 0) {
                        // Filter out models with null or empty IDs
                        $validModels = $allModels->filter(function ($model) {
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

    /**
     * Check if Meilisearch should be used for searching
     *
     * @param Model $model The model to check
     * @return bool
     */
    protected function shouldUseMeilisearch($model)
    {
        // Check if force disabled via configuration
        if (config('visns-packages.search.force_disable_meilisearch', false)) {
            return false;
        }

        // Check if Scout is installed
        if (!class_exists('Laravel\Scout\Scout')) {
            return false;
        }

        // Check if Scout driver is 'meilisearch'
        if (config('scout.driver') !== 'meilisearch') {
            return false;
        }

        // Check if model uses Searchable trait
        if (
            !in_array('Laravel\Scout\Searchable', class_uses_recursive($model))
        ) {
            return false;
        }

        // Check Meilisearch server health (with caching)
        if (!$this->isMeilisearchHealthy()) {
            return false;
        }

        return true;
    }

    /**
     * Check if Meilisearch server is healthy
     *
     * @return bool
     */
    protected function isMeilisearchHealthy()
    {
        return Cache::remember('meilisearch_health', 10, function () {
            try {
                // Check if MeiliSearch client is available
                if (!class_exists('\MeiliSearch\Client')) {
                    return false;
                }

                // Create client with fast timeout for health check
                $client = new \MeiliSearch\Client(
                    config('scout.meilisearch.host', 'http://localhost:7700'),
                    config('scout.meilisearch.key'),
                    ['timeout' => 2] // 2 second timeout
                );
                
                $client->health();
                return true;
            } catch (\Exception $e) {
                Log::warning(
                    'Meilisearch health check failed: ' . $e->getMessage()
                );
                return false;
            }
        });
    }

    /**
     * Apply Meilisearch filter to the query
     *
     * @param \Illuminate\Database\Eloquent\Builder $query
     * @param string $searchTerm
     * @return void
     */
    protected function applyMeilisearchFilter($query, $searchTerm)
    {
        // Get the model class
        $modelClass = get_class($this->model);

        // Perform search using Scout with timeout protection
        try {
            // Set a short timeout for the search operation
            $searchResults = $modelClass::search($searchTerm)
                ->options(['timeout' => 3]) // 3 second timeout for search
                ->keys();

            if ($searchResults->isEmpty()) {
                // No results, apply impossible condition
                $query->whereRaw('1 = 0');
            } else {
                // Filter by found IDs while preserving other query conditions
                $query->whereIn(
                    $this->model->getKeyName(),
                    $searchResults->toArray()
                );
            }
        } catch (\Exception $e) {
            // If search fails, rethrow to trigger fallback in calling code
            throw $e;
        }
    }
}
