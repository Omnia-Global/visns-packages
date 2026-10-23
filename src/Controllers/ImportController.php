<?php

namespace Visnsstudio\VisnsPackages\Controllers;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Reader\Exception as ReaderException;
use Carbon\Carbon;

class ImportController extends \App\Http\Controllers\Controller
{
    /**
     * Parse uploaded file and extract headers and sample data
     */
    public function parseFile(Request $request)
    {
        try {
            $request->validate([
                'file' => 'required|file|mimes:csv,xlsx,xls|max:10240', // 10MB max
            ]);

            $file = $request->file('file');

            // Debug logging
            Log::info('File upload attempt', [
                'has_file' => $file !== null,
                'original_name' => $file ? $file->getClientOriginalName() : null,
                'mime_type' => $file ? $file->getMimeType() : null,
                'size' => $file ? $file->getSize() : null,
                'temp_path' => $file ? $file->getRealPath() : null,
            ]);

            $extension = $file->getClientOriginalExtension();

            // Ensure directory exists
            $importDir = storage_path('app/temp/imports');
            if (!is_dir($importDir)) {
                mkdir($importDir, 0775, true);
            }

            // RoadRunner compatibility: manually move the file instead of using storeAs
            $filename = uniqid() . '.' . $extension;
            $fullPath = $importDir . '/' . $filename;

            // Move uploaded file
            $file->move($importDir, $filename);

            Log::info('File stored', [
                'filename' => $filename,
                'full_path' => $fullPath,
                'file_exists' => file_exists($fullPath)
            ]);

            $data = $this->parseFileContent($fullPath, $extension);
            
            // Clean up temp file
            unlink($fullPath);

            return response()->json([
                'success' => true,
                'data' => $data
            ]);

        } catch (\Exception $e) {
            Log::error('File parsing error: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Error parsing file: ' . $e->getMessage()
            ], 400);
        }
    }

    /**
     * Preview data with column mapping applied
     */
    public function previewMapping(Request $request)
    {
        try {
            $request->validate([
                'file' => 'required|file',
                'mapping' => 'required',
                'model_config' => 'required'
            ]);

            $file = $request->file('file');
            $mapping = json_decode($request->input('mapping'), true);
            $modelConfig = json_decode($request->input('model_config'), true);
            
            // Parse file again
            $extension = $file->getClientOriginalExtension();

            // Ensure directory exists
            $importDir = storage_path('app/temp/imports');
            if (!is_dir($importDir)) {
                mkdir($importDir, 0775, true);
            }

            // RoadRunner compatibility: manually move the file instead of using storeAs
            $filename = uniqid() . '.' . $extension;
            $fullPath = $importDir . '/' . $filename;
            $file->move($importDir, $filename);

            $fileData = $this->parseFileContent($fullPath, $extension);
            unlink($fullPath);

            // Apply mapping and validate
            $preview = $this->applyMappingToData($fileData['data'], $mapping, $modelConfig);
            
            return response()->json([
                'success' => true,
                'preview' => $preview['data'],
                'validation_summary' => $preview['validation'],
                'total_rows' => count($fileData['data'])
            ]);

        } catch (\Exception $e) {
            Log::error('Mapping preview error: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Error previewing mapping: ' . $e->getMessage()
            ], 400);
        }
    }

    /**
     * Process the actual import
     */
    public function processImport(Request $request)
    {
        try {
            $request->validate([
                'file' => 'required|file',
                'mapping' => 'required',
                'model_config' => 'required',
                'target_model' => 'required|string',
                'parent_id' => 'nullable|integer',
                'relation_key' => 'nullable|string'
            ]);

            $file = $request->file('file');
            $mapping = json_decode($request->input('mapping'), true);
            // Validation hints only (required / email). Never decides which
            // table or which columns are written.
            $modelConfig = json_decode($request->input('model_config'), true);
            $targetModel = $request->input('target_model');
            $parentId = $request->input('parent_id');
            $relationKey = $request->input('relation_key');

            // The table and its writable columns come from the server: a
            // configured (or dynamic-entity) model and its $fillable.
            $model = $this->resolveImportModel($targetModel);

            if (! $model) {
                Log::info('Refusing an import to a target with no configured model', [
                    'target' => is_scalar($targetModel) ? (string) $targetModel : gettype($targetModel),
                ]);

                return response()->json([
                    'success' => false,
                    'message' => 'This import target is not configured.',
                ], 422);
            }

            if (! is_array($mapping)) {
                $mapping = [];
            }
            if (! is_array($modelConfig)) {
                $modelConfig = [];
            }

            // Parse file
            $extension = $file->getClientOriginalExtension();

            // Ensure directory exists
            $importDir = storage_path('app/temp/imports');
            if (!is_dir($importDir)) {
                mkdir($importDir, 0775, true);
            }

            // RoadRunner compatibility: manually move the file instead of using storeAs
            $filename = uniqid() . '.' . $extension;
            $fullPath = $importDir . '/' . $filename;
            $file->move($importDir, $filename);

            $fileData = $this->parseFileContent($fullPath, $extension);
            unlink($fullPath);

            // Process import in transaction
            DB::beginTransaction();
            
            $result = $this->importData(
                $fileData['data'], 
                $mapping, 
                $modelConfig, 
                $model,
                $parentId,
                $relationKey
            );

            DB::commit();

            return response()->json([
                'success' => true,
                'imported' => $result['success_count'],
                'errors' => $result['errors'],
                'skipped' => $result['skipped_count'],
                'total_processed' => $result['total_count']
            ]);

        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Import processing error: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Error processing import: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Parse file content based on extension
     */
    protected function parseFileContent($filePath, $extension)
    {
        if ($extension === 'csv') {
            return $this->parseCsvFile($filePath);
        } else {
            return $this->parseExcelFile($filePath);
        }
    }

    /**
     * Data rows read from a file, after its header row (a safety limit).
     */
    private const MAX_DATA_ROWS = 1000;

    /**
     * How many rows below a title row are searched for the real header row.
     */
    private const HEADER_SCAN_ROWS = 5;

    /**
     * Parse CSV file
     */
    private function parseCsvFile($filePath)
    {
        $rows = [];

        if (($handle = fopen($filePath, "r")) !== FALSE) {
            // Enough rows for a title row above the header plus the data limit.
            $maxRows = self::MAX_DATA_ROWS + self::HEADER_SCAN_ROWS + 1;

            while (($row = fgetcsv($handle, 0, ',', '"', '\\')) !== FALSE) {
                // fgetcsv() returns [null] for an empty line.
                $rows[] = array_map(fn ($cell) => (string) ($cell ?? ''), $row);

                if (count($rows) >= $maxRows) break; // Limit for safety
            }

            fclose($handle);
        }

        // Excel's "CSV UTF-8" export starts the file with a byte order mark,
        // which would otherwise stick to the first header.
        if (isset($rows[0][0])) {
            $rows[0][0] = $this->stripBom($rows[0][0]);
        }

        return $this->buildTable($rows);
    }

    /**
     * Parse Excel file
     */
    private function parseExcelFile($filePath)
    {
        try {
            $reader = IOFactory::createReaderForFile($filePath);
            $spreadsheet = $reader->load($filePath);
            $worksheet = $spreadsheet->getActiveSheet();

            // Column letters compared as strings break past "Z" ("AA" < "Z"),
            // so iterate by numeric column index.
            $highestColumnIndex = Coordinate::columnIndexFromString($worksheet->getHighestDataColumn());
            $highestRow = min(
                $worksheet->getHighestDataRow(),
                self::MAX_DATA_ROWS + self::HEADER_SCAN_ROWS + 1 // Limit for safety
            );

            $rows = [];
            for ($row = 1; $row <= $highestRow; $row++) {
                $rowData = [];
                for ($col = 1; $col <= $highestColumnIndex; $col++) {
                    $coordinate = Coordinate::stringFromColumnIndex($col) . $row;
                    $rowData[] = $worksheet->cellExists($coordinate)
                        ? $this->cellToString($worksheet->getCell($coordinate)->getCalculatedValue())
                        : '';
                }
                $rows[] = $rowData;
            }

            return $this->buildTable($rows);

        } catch (ReaderException $e) {
            throw new \Exception('Unable to read Excel file: ' . $e->getMessage());
        }
    }

    /**
     * Turn raw rows (lists of cell strings) into headers and keyed data rows:
     * finds the header row, trims headers, fits every row to the header count
     * and drops rows with nothing in them.
     */
    private function buildTable(array $rows): array
    {
        $headers = [];
        $data = [];

        $headerIndex = $this->detectHeaderRowIndex($rows);

        if ($headerIndex !== null) {
            $headers = array_map(fn ($cell) => trim((string) $cell), $rows[$headerIndex]);
            $columnCount = count($headers);

            foreach (array_slice($rows, $headerIndex + 1) as $row) {
                $cells = array_map(fn ($cell) => trim((string) $cell), $row);

                // A row of blank cells (trailing formatted rows, empty lines)
                // is not data.
                if ($this->countNonBlank($cells) === 0) {
                    continue;
                }

                // Fit the row to the header: pad short rows, drop extra cells.
                $cells = array_slice(array_pad($cells, $columnCount, ''), 0, $columnCount);
                $data[] = array_combine($headers, $cells);

                if (count($data) >= self::MAX_DATA_ROWS) break; // Limit for safety
            }
        }

        return [
            'headers' => $headers,
            'data' => $data,
            'sample_data' => array_slice($data, 0, 5),
            'total_rows' => count($data)
        ];
    }

    /**
     * The index of the header row. Normally the first row, but a sheet with a
     * title in A1 (a first row with at most one filled cell) has its header in
     * the first of the next few rows that has at least two filled cells.
     */
    private function detectHeaderRowIndex(array $rows): ?int
    {
        if ($rows === []) {
            return null;
        }

        $first = array_key_first($rows);

        if ($this->countNonBlank($rows[$first]) <= 1) {
            $candidates = array_slice($rows, 1, self::HEADER_SCAN_ROWS, true);
            foreach ($candidates as $index => $row) {
                if ($this->countNonBlank($row) >= 2) {
                    return $index;
                }
            }
        }

        return $first;
    }

    /**
     * Number of cells in a row that hold something other than whitespace.
     */
    private function countNonBlank(array $row): int
    {
        return count(array_filter($row, fn ($cell) => trim((string) $cell) !== ''));
    }

    /**
     * A UTF-8 byte order mark removed from the start of a string.
     */
    private function stripBom(string $value): string
    {
        return str_starts_with($value, "\xEF\xBB\xBF") ? substr($value, 3) : $value;
    }

    /**
     * A spreadsheet cell's calculated value as a trimmed string. Whole numbers
     * render without a float artefact (400111222.0 is "400111222", never
     * "4.0E+8").
     */
    private function cellToString($value): string
    {
        if ($value === null) {
            return '';
        }

        if (is_bool($value)) {
            return $value ? 'TRUE' : 'FALSE';
        }

        if (is_float($value)) {
            if (is_finite($value) && floor($value) === $value && abs($value) < 1e15) {
                return sprintf('%.0f', $value);
            }

            // Shortest representation that round-trips, without exponent
            // notation for ordinary decimals.
            $string = var_export($value, true);
            return str_contains($string, 'E') ? (string) $value : $string;
        }

        if (is_object($value) && method_exists($value, '__toString')) {
            return trim((string) $value);
        }

        return is_scalar($value) ? trim((string) $value) : '';
    }

    /**
     * Apply column mapping to data and validate
     */
    private function applyMappingToData($data, $mapping, $modelConfig)
    {
        $mappedData = [];
        $validationSummary = [
            'valid_rows' => 0,
            'invalid_rows' => 0,
            'errors' => []
        ];

        $mapping = is_array($mapping) ? $mapping : [];

        // The summary covers every row; only the returned preview is limited.
        foreach ($data as $index => $row) {
            $mappedRow = [];

            // Apply mapping. A blank cell sets nothing, so an optional column
            // left empty is simply absent rather than an invalid value.
            foreach ($mapping as $fileColumn => $modelField) {
                if (! $modelField || ! is_string($modelField) || ! array_key_exists($fileColumn, $row)) {
                    continue;
                }

                $value = $row[$fileColumn];
                if ($value === null || trim((string) $value) === '') {
                    continue;
                }

                $mappedRow[$modelField] = $this->sanitizeValue($value, $modelField);
            }

            // Validate row
            $validation = $this->validateMappedRow($mappedRow, $modelConfig);

            if ($validation['valid']) {
                $validationSummary['valid_rows']++;
            } else {
                $validationSummary['invalid_rows']++;
                $validationSummary['errors'][] = [
                    'row' => $index + 1,
                    'errors' => $validation['errors']
                ];
            }

            // Limit preview to 10 rows
            if (count($mappedData) < 10) {
                $mappedData[] = [
                    'original' => $row,
                    'mapped' => $mappedRow,
                    'valid' => $validation['valid'],
                    'errors' => $validation['errors']
                ];
            }
        }

        return [
            'data' => $mappedData,
            'validation' => $validationSummary
        ];
    }

    /**
     * Sanitize and format values based on field type
     */
    protected function sanitizeValue($value, $fieldName)
    {
        $value = trim((string) $value);

        // Email validation: a valid address is stored lowercased, anything
        // else (blank included) becomes null.
        if (Str::contains($fieldName, 'email')) {
            return filter_var($value, FILTER_VALIDATE_EMAIL) ? Str::lower($value) : null;
        }

        // Phone number cleanup
        if (Str::contains($fieldName, 'phone')) {
            return preg_replace('/[^0-9+\-\s()]/', '', $value);
        }

        // Date handling
        if (Str::contains($fieldName, ['date', 'created_at', 'updated_at'])) {
            try {
                return Carbon::parse($value)->format('Y-m-d H:i:s');
            } catch (\Exception $e) {
                return null;
            }
        }

        return $value;
    }

    /**
     * Validate a mapped row against model config
     */
    protected function validateMappedRow($row, $modelConfig)
    {
        $rules = [];
        $attributes = [];

        // Build validation rules from model config
        foreach ((array) $modelConfig as $field) {
            if (! is_array($field) || ! isset($field['id']) || ! is_string($field['id'])) {
                continue;
            }

            $id = $field['id'];
            $fieldRules = [];

            if (! empty($field['required'])) {
                $fieldRules[] = 'required';
            }

            if (($field['type'] ?? null) === 'email') {
                // An optional email may be absent or null (a blank or
                // unparseable cell); when present it must be an address.
                if ($fieldRules === []) {
                    $fieldRules[] = 'nullable';
                }
                $fieldRules[] = 'email';
            }

            if ($fieldRules !== []) {
                $rules[$id] = implode('|', $fieldRules);
            }

            // Messages read "The First Name field is required."
            if (isset($field['label']) && is_string($field['label']) && trim($field['label']) !== '') {
                $attributes[$id] = trim($field['label']);
            }
        }

        $validator = Validator::make(is_array($row) ? $row : [], $rules, [], $attributes);

        return [
            'valid' => !$validator->fails(),
            'errors' => $validator->errors()->all()
        ];
    }

    /**
     * Import data to database
     */
    private function importData($data, $mapping, $modelConfig, $targetModel, $parentId = null, $relationKey = null)
    {
        $successCount = 0;
        $errorCount = 0;
        $skippedCount = 0;
        $errors = [];
        
        foreach ($data as $index => $row) {
            try {
                $mappedRow = [];
                
                // Apply mapping
                foreach ($mapping as $fileColumn => $modelField) {
                    if ($modelField && isset($row[$fileColumn])) {
                        $mappedRow[$modelField] = $this->sanitizeValue($row[$fileColumn], $modelField);
                    }
                }
                
                // Add parent relationship if specified
                if ($parentId && $relationKey) {
                    $mappedRow[$relationKey] = $parentId;
                }

                // Only the model's fillable columns are ever written; the
                // model stamps its own timestamps on save.
                $fillable = $targetModel->getFillable();
                $dropped = array_diff(array_keys($mappedRow), $fillable);
                if ($dropped !== []) {
                    Log::info('Import dropping columns that are not fillable', [
                        'model' => get_class($targetModel),
                        'columns' => array_values($dropped),
                    ]);
                }
                $mappedRow = array_intersect_key($mappedRow, array_flip($fillable));
                
                // Validate before insert
                $validation = $this->validateMappedRow($mappedRow, $modelConfig);
                
                if (!$validation['valid']) {
                    $errors[] = [
                        'row' => $index + 1,
                        'data' => $row,
                        'errors' => $validation['errors']
                    ];
                    $errorCount++;
                    continue;
                }
                
                // Insert through the model, so $fillable and its casts apply.
                $record = $targetModel->newInstance();
                $record->fill($mappedRow);
                $record->save();
                $successCount++;
                
            } catch (\Exception $e) {
                $errors[] = [
                    'row' => $index + 1,
                    'data' => $row,
                    'errors' => ['Database error: ' . $e->getMessage()]
                ];
                $errorCount++;
            }
        }
        
        return [
            'success_count' => $successCount,
            'error_count' => $errorCount,
            'skipped_count' => $skippedCount,
            'errors' => $errors,
            'total_count' => count($data)
        ];
    }

    /**
     * The model an import target writes to, from server-side configuration:
     * `visns-packages.import.models` (target => model class), or a configured
     * dynamic entity whose table is the target. Null when neither applies.
     */
    protected function resolveImportModel($target): ?Model
    {
        if (! is_string($target) || $target === '') {
            return null;
        }

        $configured = (array) config('visns-packages.import.models', []);
        $class = $configured[$target] ?? null;

        if (is_string($class) && class_exists($class) && is_subclass_of($class, Model::class)) {
            return new $class();
        }

        foreach ((array) config('visns-packages.dynamic_entities', []) as $entity) {
            if (! is_string($entity) || $entity === '') {
                continue;
            }

            $name = Str::studly(Str::singular($entity));

            foreach (["App\\Models\\{$name}", "Visnsstudio\\VisnsPackages\\Models\\{$name}"] as $candidate) {
                try {
                    if (class_exists($candidate) && is_subclass_of($candidate, Model::class)) {
                        $instance = new $candidate();
                        if ($instance->getTable() === $target) {
                            return $instance;
                        }
                        break;
                    }
                } catch (\Throwable $e) {
                    // A model that cannot even be loaded is not a target.
                    break;
                }
            }
        }

        return null;
    }

    /**
     * Header synonyms per canonical field, in normalised form (see
     * normaliseHeader()). A model field matches a canonical field when its id
     * is that field or one of the id aliases below.
     */
    private const HEADER_SYNONYMS = [
        'prefix' => ['title', 'salutation', 'prefix', 'honorific'],
        'firstname' => ['first name', 'given name', 'forename', 'first', 'name', 'full name', 'contact name', 'attendee name', 'fname'],
        'surname' => ['surname', 'last name', 'family name', 'last', 'lname'],
        'company' => ['company', 'company name', 'organisation', 'organization', 'org', 'employer', 'business', 'business name'],
        'position' => ['position', 'job title', 'role', 'occupation', 'job', 'designation'],
        'nationality' => ['nationality', 'country', 'citizenship'],
        'email' => ['email', 'e mail', 'email address'],
        'phone' => ['phone', 'phone number', 'mobile', 'mobile number', 'contact number', 'telephone', 'tel', 'cell'],
    ];

    /**
     * Synonyms too generic to count when they only appear inside a longer
     * header ("Event Name" is not a first name, "Event Title" not a prefix).
     */
    private const PARTIAL_EXCLUDED_SYNONYMS = ['name', 'first', 'last', 'title', 'job', 'org', 'tel', 'cell', 'role'];

    /**
     * Model field ids that stand for a canonical field (compared compacted,
     * so "first_name" is "firstname").
     */
    private const FIELD_ID_ALIASES = [
        'surname' => ['lastname'],
        'firstname' => ['givenname'],
        'phone' => ['mobile', 'phonenumber'],
        'company' => ['organisation', 'organization'],
    ];

    /**
     * Get smart column suggestions based on file headers
     */
    public function getColumnSuggestions(Request $request)
    {
        $request->validate([
            'headers' => 'required|array',
            'model_fields' => 'required|array'
        ]);

        $headers = $request->input('headers');
        $modelFields = $request->input('model_fields');

        return response()->json([
            'success' => true,
            'suggestions' => $this->suggestMapping($headers, $modelFields)
        ]);
    }

    /**
     * Map file headers to model field ids. Matching runs in passes across ALL
     * headers - exact field id/label, exact synonym, fallback synonym, then
     * partial - so a precise match elsewhere in the sheet is never pre-empted
     * by a loose one. A field is suggested for one header only (first wins),
     * blank headers are skipped and only fields in $modelFields are offered.
     */
    private function suggestMapping($headers, $modelFields): array
    {
        // The model's fields: id => [compact id, compact label, canonical key].
        $fields = [];
        foreach ((array) $modelFields as $field) {
            if (! is_array($field) || ! isset($field['id']) || ! is_string($field['id']) || $field['id'] === '') {
                continue;
            }

            $id = $field['id'];
            $label = isset($field['label']) && is_string($field['label']) ? $field['label'] : '';

            $fields[$id] = [
                'id' => $this->compactHeader($id),
                'label' => $this->compactHeader($label),
                'canonical' => $this->canonicalFieldFor($id),
            ];
        }

        // The file's headers: original => normalised, blank ones skipped.
        $normalised = [];
        foreach ((array) $headers as $header) {
            if (! is_scalar($header)) {
                continue;
            }
            $header = (string) $header;
            $norm = $this->normaliseHeader($header);
            if ($norm !== '' && ! array_key_exists($header, $normalised)) {
                $normalised[$header] = $norm;
            }
        }

        $canonicalPresent = array_filter(array_column($fields, 'canonical'));
        $hasPrefix = in_array('prefix', $canonicalPresent, true);

        $suggestions = [];
        $taken = [];

        $passes = [
            // 1. The header is the field's id or label.
            function (string $norm, string $id, array $meta) {
                $compact = str_replace(' ', '', $norm);
                return $compact === $meta['id'] || ($meta['label'] !== '' && $compact === $meta['label']);
            },
            // 2. The header is a synonym of the field.
            function (string $norm, string $id, array $meta) {
                return $meta['canonical'] !== null
                    && $this->matchesSynonym($norm, self::HEADER_SYNONYMS[$meta['canonical']]);
            },
            // 3. "Title" means the job title only on a model with no prefix.
            function (string $norm, string $id, array $meta) use ($hasPrefix) {
                return ! $hasPrefix && $meta['canonical'] === 'position'
                    && str_replace(' ', '', $norm) === 'title';
            },
            // 4. Partial: the header contains a synonym as whole words, or
            //    the compact header and field id contain one another.
            function (string $norm, string $id, array $meta) {
                if ($meta['canonical'] !== null) {
                    foreach (self::HEADER_SYNONYMS[$meta['canonical']] as $synonym) {
                        if (in_array($synonym, self::PARTIAL_EXCLUDED_SYNONYMS, true)) {
                            continue;
                        }
                        if (preg_match('/(^| )' . preg_quote($synonym, '/') . '( |$)/', $norm)) {
                            return true;
                        }
                    }
                }

                $compact = str_replace(' ', '', $norm);
                if (strlen($meta['id']) < 3 || strlen($compact) < 3) {
                    return false;
                }
                return Str::contains($compact, $meta['id']) || Str::contains($meta['id'], $compact);
            },
        ];

        foreach ($passes as $matches) {
            foreach ($normalised as $header => $norm) {
                if (isset($suggestions[$header])) {
                    continue;
                }

                foreach ($fields as $id => $meta) {
                    if (isset($taken[$id])) {
                        continue;
                    }

                    if ($matches($norm, (string) $id, $meta)) {
                        $suggestions[$header] = (string) $id;
                        $taken[$id] = true;
                        break;
                    }
                }
            }
        }

        // Keep the file's column order.
        $ordered = [];
        foreach ($normalised as $header => $norm) {
            if (isset($suggestions[$header])) {
                $ordered[$header] = $suggestions[$header];
            }
        }

        return $ordered;
    }

    /**
     * A header in comparable form: lowercased, trimmed, "_ - / ." read as
     * spaces and whitespace collapsed ("First_Name " is "first name").
     */
    private function normaliseHeader(string $header): string
    {
        $header = Str::lower($this->stripBom($header));
        $header = preg_replace('/[_\-\/.]+/', ' ', $header);
        $header = preg_replace('/\s+/u', ' ', $header);

        return trim($header);
    }

    /**
     * A header with its separators removed, so "e-mail", "E Mail" and
     * "email" compare equal.
     */
    private function compactHeader(string $header): string
    {
        return str_replace(' ', '', $this->normaliseHeader($header));
    }

    /**
     * Whether a normalised header is exactly one of the synonyms.
     */
    private function matchesSynonym(string $norm, array $synonyms): bool
    {
        $compact = str_replace(' ', '', $norm);
        foreach ($synonyms as $synonym) {
            if ($compact === str_replace(' ', '', $synonym)) {
                return true;
            }
        }

        return false;
    }

    /**
     * The canonical field (a HEADER_SYNONYMS key) a model field id stands
     * for, or null.
     */
    private function canonicalFieldFor(string $id): ?string
    {
        $compact = $this->compactHeader($id);

        foreach (array_keys(self::HEADER_SYNONYMS) as $canonical) {
            if ($compact === $canonical || in_array($compact, self::FIELD_ID_ALIASES[$canonical] ?? [], true)) {
                return $canonical;
            }
        }

        return null;
    }
}
