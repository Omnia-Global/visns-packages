<?php

namespace Visnsstudio\VisnsPackages\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
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
            $modelConfig = json_decode($request->input('model_config'), true);
            $targetModel = $request->input('target_model');
            $parentId = $request->input('parent_id');
            $relationKey = $request->input('relation_key');

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
                $targetModel,
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
     * Parse CSV file
     */
    private function parseCsvFile($filePath)
    {
        $headers = [];
        $data = [];
        
        if (($handle = fopen($filePath, "r")) !== FALSE) {
            $rowIndex = 0;

            while (($row = fgetcsv($handle, 0, ',', '"', '\\')) !== FALSE) {
                if ($rowIndex === 0) {
                    $headers = array_map('trim', $row);
                } else {
                    $rowData = array_combine($headers, array_pad($row, count($headers), ''));
                    $data[] = array_map('trim', $rowData);
                }
                
                $rowIndex++;
                if ($rowIndex > 1000) break; // Limit for safety
            }
            
            fclose($handle);
        }

        return [
            'headers' => $headers,
            'data' => $data,
            'sample_data' => array_slice($data, 0, 5),
            'total_rows' => count($data)
        ];
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
            
            $headers = [];
            $data = [];
            
            $highestColumn = $worksheet->getHighestColumn();
            $highestRow = min($worksheet->getHighestRow(), 1001); // Limit for safety
            
            // Get headers from first row
            for ($col = 'A'; $col <= $highestColumn; $col++) {
                $headers[] = trim($worksheet->getCell($col . '1')->getCalculatedValue() ?? '');
            }
            
            // Get data rows
            for ($row = 2; $row <= $highestRow; $row++) {
                $rowData = [];
                for ($col = 'A'; $col <= $highestColumn; $col++) {
                    $cellValue = $worksheet->getCell($col . $row)->getCalculatedValue();
                    $rowData[] = trim($cellValue ?? '');
                }
                
                $data[] = array_combine($headers, $rowData);
            }
            
            return [
                'headers' => $headers,
                'data' => $data,
                'sample_data' => array_slice($data, 0, 5),
                'total_rows' => count($data)
            ];
            
        } catch (ReaderException $e) {
            throw new \Exception('Unable to read Excel file: ' . $e->getMessage());
        }
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

        foreach ($data as $index => $row) {
            $mappedRow = [];
            $rowErrors = [];
            
            // Apply mapping
            foreach ($mapping as $fileColumn => $modelField) {
                if ($modelField && isset($row[$fileColumn])) {
                    $mappedRow[$modelField] = $this->sanitizeValue($row[$fileColumn], $modelField);
                }
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
            
            $mappedData[] = [
                'original' => $row,
                'mapped' => $mappedRow,
                'valid' => $validation['valid'],
                'errors' => $validation['errors']
            ];
            
            // Limit preview to 10 rows
            if (count($mappedData) >= 10) break;
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
        $value = trim($value);
        
        // Email validation
        if (Str::contains($fieldName, 'email')) {
            return filter_var($value, FILTER_VALIDATE_EMAIL) ? $value : null;
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
        $errors = [];
        
        // Build validation rules from model config
        foreach ($modelConfig as $field) {
            if ($field['required'] && isset($field['id'])) {
                $rules[$field['id']] = 'required';
            }
            
            if ($field['type'] === 'email') {
                $rules[$field['id']] = ($rules[$field['id']] ?? '') . '|email';
            }
        }
        
        $validator = Validator::make($row, $rules);
        
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
                
                // Add timestamps
                $mappedRow['created_at'] = now();
                $mappedRow['updated_at'] = now();
                
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
                
                // Insert to database
                DB::table($targetModel)->insert($mappedRow);
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
        
        $suggestions = [];
        
        foreach ($headers as $header) {
            $suggestion = $this->findBestMatch($header, $modelFields);
            if ($suggestion) {
                $suggestions[$header] = $suggestion;
            }
        }
        
        return response()->json([
            'success' => true,
            'suggestions' => $suggestions
        ]);
    }

    /**
     * Find best matching field for a header
     */
    private function findBestMatch($header, $modelFields)
    {
        $header = strtolower(trim($header));
        
        // Common mappings
        $commonMappings = [
            'first name' => 'firstname',
            'first_name' => 'firstname',
            'fname' => 'firstname',
            'last name' => 'surname',
            'last_name' => 'surname',
            'surname' => 'surname',
            'lname' => 'surname',
            'email address' => 'email',
            'email' => 'email',
            'phone number' => 'phone',
            'phone' => 'phone',
            'mobile' => 'phone',
            'company' => 'company',
            'organization' => 'company',
            'organisation' => 'company',
            'position' => 'position',
            'job title' => 'position',
            'title' => 'position'
        ];
        
        // Direct match
        if (isset($commonMappings[$header])) {
            foreach ($modelFields as $field) {
                if ($field['id'] === $commonMappings[$header]) {
                    return $field['id'];
                }
            }
        }
        
        // Partial match
        foreach ($modelFields as $field) {
            $fieldId = strtolower($field['id']);
            if (Str::contains($header, $fieldId) || Str::contains($fieldId, $header)) {
                return $field['id'];
            }
        }
        
        return null;
    }
}