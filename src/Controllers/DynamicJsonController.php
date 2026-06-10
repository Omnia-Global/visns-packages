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

    /**
     * Fields added by S3/Vapor file uploads that should not be stored as data fields.
     */
    private static $fileMetadataFields = [
        'uuid', 'bucket', 'filename', 'filesize',
        'extension', 'file_relationship', 'fileable_field',
        'uploadedFiles', 'existingFiles', '_method',
    ];

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
            // Skip initialization instead of aborting
            $this->model = null;
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

        return response()->json($this->resolveFileUrlsForItem($data), 200);
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
                $data = $this->resolveFileUrls($item->{$dataKey});
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
        $jsonKey = $this->resolveJsonKey($request);

        $validator = Validator::make([
            'dataId' => $request->input('dataId'),
            'key' => $jsonKey,
        ], [
            "dataId" => "required|exists:{$this->model->getTable()},id",
            "key" => "required|string",
        ]);

        if ($validator->fails()) {
            return response()->json(["error" => $validator->errors()], 422);
        }

        $item = $this->model->find($request->input("dataId"));

        // Fetch the data field dynamically based on the provided key
        $data = $item->{$jsonKey} ?? [];

        // Create a new data object
        $dataObj = $this->createOrUpdateDataObj($request, $data);

        // Handle file uploads
        $this->processAllFileUploads($request, $dataObj);

        // Append the new data object to the existing data
        $item->{$jsonKey} = array_merge($data, [$dataObj]);

        // Save the updated item
        $item->save();

        return response()->json(["error" => ""], 200);
    }

    public function jsonUpdate(Request $request)
    {
        $jsonKey = $this->resolveJsonKey($request);

        $validator = Validator::make([
            'dataId' => $request->input('dataId'),
            'key' => $jsonKey,
            'id' => $request->input('id'),
        ], [
            "dataId" => "required|exists:{$this->model->getTable()},id",
            "key" => "required|string",
            "id" => "required|integer", // Required for updating the specific entry
        ]);

        if ($validator->fails()) {
            return response()->json(["error" => $validator->errors()], 422);
        }

        $item = $this->model->find($request->input("dataId"));

        // Fetch the data field dynamically based on the provided key
        $data = $item->{$jsonKey} ?? [];

        // Find the index of the existing item to update using 'id'
        $index = collect($data)->search(function ($dataItem) use ($request) {
            return $dataItem["id"] == $request->input("id");
        });

        if ($index === false) {
            return response()->json(["error" => "Item not found"], 404);
        }

        // Update the data object at the found index
        $data[$index] = $this->createOrUpdateDataObj($request, $data, $index);

        // Handle file uploads
        $this->processAllFileUploads($request, $data[$index]);

        // Save the updated data array back to the model's key
        $item->{$jsonKey} = $data;
        $item->save();

        return response()->json(["error" => ""], 200);
    }

    /**
     * Resolve the JSON column key from the request.
     * When a file upload is present, the S3 'key' field overwrites the form's
     * hidden 'key' field. Fall back to '_jsonKey' in that case.
     */
    private function resolveJsonKey(Request $request)
    {
        if ($request->filled('_jsonKey')) {
            return $request->input('_jsonKey');
        }

        return $request->input('key');
    }

    /**
     * Process all file uploads (single and multi-file) from S3/Vapor.
     * Handles both `uploadedFiles` array and single uuid/key uploads.
     * Supports `existingFiles` to preserve/remove files from frontend.
     */
    private function processAllFileUploads(Request $request, &$dataObj)
    {
        $fileRelationship = $request->input('file_relationship') ?? 'files';
        $folder = Str::snake(class_basename($this->model));

        // Field-scoped uploads: a form field whose value is a Vapor upload
        // descriptor ({_vaporUpload: true, key: 'tmp/...', ...}). Copy the
        // tmp file to permanent storage and store a single file object on
        // the field — the shape edit prefill, image columns and PDF
        // rendering consume. Supports multiple file fields in one save
        // (e.g. a bio's photo + document).
        $descriptorFields = [];
        foreach ($dataObj as $field => $value) {
            if (!is_array($value) || !($value['_vaporUpload'] ?? false)) {
                continue;
            }

            $descriptorFields[] = $field;
            $s3Key = $value['key'] ?? '';
            $uuid = $value['uuid'] ?? uniqid();
            $filename = $value['filename'] ?? 'unknown';
            $permanentPath = "{$folder}/{$uuid}/{$filename}";

            try {
                if (
                    is_string($s3Key) &&
                    str_starts_with($s3Key, 'tmp/') &&
                    Storage::exists($s3Key)
                ) {
                    Storage::copy($s3Key, $permanentPath);
                    $dataObj[$field] = [
                        'file_path' => $permanentPath,
                        'file_name' => $filename,
                        'file_extension' => $value['extension'] ?? '',
                        'file_size' => $value['filesize'] ?? 0,
                    ];
                } else {
                    // Never persist a raw descriptor
                    unset($dataObj[$field]);
                }
            } catch (\Exception $e) {
                \Log::warning("DynamicJsonController file copy failed: {$e->getMessage()}");
                unset($dataObj[$field]);
            }
        }

        // The array-based machinery below (existingFiles, cleanup, append)
        // only applies to multi-file relationships. Skip it entirely when a
        // field-scoped descriptor already stored a single file object on
        // this field — the cleanup would otherwise strip that object.
        $relationshipHandledByDescriptor = in_array(
            $fileRelationship,
            $descriptorFields,
            true
        );

        if (!$relationshipHandledByDescriptor) {
            // Determine base files: use existingFiles from frontend (handles deletion),
            // otherwise preserve existing files in the data object
            if ($request->has('existingFiles') && is_array($request->input('existingFiles'))) {
                $baseFiles = array_values(array_filter($request->input('existingFiles'), function ($f) {
                    return is_array($f) && !empty($f) && isset($f['file_name']);
                }));
                $dataObj[$fileRelationship] = $baseFiles;
            } else {
                // Clean up existing files array (remove empty/invalid entries like [[]])
                if (isset($dataObj[$fileRelationship]) && is_array($dataObj[$fileRelationship])) {
                    $dataObj[$fileRelationship] = array_values(array_filter($dataObj[$fileRelationship], function ($f) {
                        return is_array($f) && !empty($f) && isset($f['file_name']);
                    }));
                }
            }

            // Ensure files field is an array
            if (!isset($dataObj[$fileRelationship]) || !is_array($dataObj[$fileRelationship])) {
                $dataObj[$fileRelationship] = [];
            }
        }

        // Handle multi-file uploads (uploadedFiles array from Vapor)
        if ($request->filled('uploadedFiles') && is_array($request->input('uploadedFiles'))) {
            foreach ($request->input('uploadedFiles') as $file) {
                $uuid = $file['uuid'] ?? uniqid();
                $filename = $file['filename'] ?? 'unknown';
                $s3Key = $file['key'] ?? '';
                $permanentPath = "{$folder}/{$uuid}/{$filename}";

                try {
                    if ($s3Key && Storage::exists($s3Key)) {
                        Storage::copy($s3Key, $permanentPath);
                    }
                } catch (\Exception $e) {
                    \Log::warning("DynamicJsonController file copy failed: {$e->getMessage()}");
                    continue;
                }

                $dataObj[$fileRelationship][] = [
                    'file_path' => $permanentPath,
                    'file_name' => $filename,
                    'file_extension' => $file['extension'] ?? '',
                    'file_size' => $file['filesize'] ?? 0,
                ];
            }
        }
        // Handle single file upload (uuid/key at request level) — legacy
        // path; skipped when a field-scoped descriptor already stored the
        // file against its own field above.
        elseif (
            $request->has('uuid') &&
            $request->has('file_relationship') &&
            !$relationshipHandledByDescriptor
        ) {
            $s3Key = $request->input('key');
            if (is_string($s3Key) && str_starts_with($s3Key, 'tmp/')) {
                $uuid = $request->input('uuid');
                $filename = $request->input('filename');
                $permanentPath = "{$folder}/{$uuid}/{$filename}";

                try {
                    if (Storage::exists($s3Key)) {
                        Storage::copy($s3Key, $permanentPath);
                    }
                } catch (\Exception $e) {
                    \Log::warning("DynamicJsonController file copy failed: {$e->getMessage()}");
                    return;
                }

                $dataObj[$fileRelationship][] = [
                    'file_path' => $permanentPath,
                    'file_name' => $filename,
                    'file_extension' => $request->input('extension'),
                    'file_size' => $request->input('filesize'),
                ];
            }
        }
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

        // Exclude internal fields and file upload metadata from the data object
        $excludeFields = array_merge(
            ["dataId", "key", "id", "_jsonKey"],
            self::$fileMetadataFields
        );

        // Add/update fields dynamically based on request data
        $fields = $request->except($excludeFields);
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

    /**
     * Generate temporary URLs for file references in an array of data items.
     */
    private function resolveFileUrls($data)
    {
        return array_map(function ($item) {
            return $this->resolveFileUrlsForItem($item);
        }, $data);
    }

    /**
     * Generate temporary URLs for any nested file reference in a single data item.
     * Looks for objects with 'file_path' (or legacy 'key') and adds a 'file_url' with a presigned URL.
     * Also handles base64 data URIs (passes through as file_url for image display).
     * Handles both single file objects and arrays of file objects.
     */
    private function resolveFileUrlsForItem($item)
    {
        foreach ($item as $key => $value) {
            // Handle base64 data URIs (legacy: photo stored as base64 string)
            if (is_string($value) && str_starts_with($value, 'data:image')) {
                $item[$key] = ['file_url' => $value];
            }
            elseif (is_array($value)) {
                // Normalize legacy 'key' field to 'file_path'
                if (!isset($value['file_path']) && isset($value['key'])) {
                    $value['file_path'] = $value['key'];
                    if (isset($value['originalFilename'])) $value['file_name'] = $value['originalFilename'];
                    if (isset($value['extension'])) $value['file_extension'] = $value['extension'];
                    if (isset($value['size'])) $value['file_size'] = $value['size'];
                    $item[$key] = $value;
                }

                // Single file object: {file_path: '...', file_name: '...'}
                if (isset($value['file_path']) && Storage::exists($value['file_path'])) {
                    $item[$key]['file_url'] = Storage::temporaryUrl(
                        $value['file_path'],
                        now()->addMinutes(60)
                    );
                }
                // Array of file objects: [{file_path: '...', ...}, ...]
                elseif (isset($value[0]) && is_array($value[0])) {
                    foreach ($value as $fileIdx => $fileObj) {
                        // Normalize legacy 'key' field
                        if (!isset($fileObj['file_path']) && isset($fileObj['key'])) {
                            $fileObj['file_path'] = $fileObj['key'];
                            if (isset($fileObj['originalFilename'])) $fileObj['file_name'] = $fileObj['originalFilename'];
                            if (isset($fileObj['extension'])) $fileObj['file_extension'] = $fileObj['extension'];
                            if (isset($fileObj['size'])) $fileObj['file_size'] = $fileObj['size'];
                            $item[$key][$fileIdx] = $fileObj;
                        }
                        if (isset($fileObj['file_path']) && Storage::exists($fileObj['file_path'])) {
                            $item[$key][$fileIdx]['file_url'] = Storage::temporaryUrl(
                                $fileObj['file_path'],
                                now()->addMinutes(60)
                            );
                        }
                    }
                }
            }
        }
        return $item;
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
