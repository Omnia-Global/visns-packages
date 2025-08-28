<?php

namespace Visnsstudio\VisnsPackages\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Auth;
use Visnsstudio\VisnsPackages\Models\ReportBuilder;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Barryvdh\DomPDF\Facade\Pdf;

class ReportBuilderController extends \App\Http\Controllers\Controller
{
    /**
     * Get all tables in the database
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function getTables()
    {
        try {

            // Get all tables from the database
            $tables = $this->getAllDatabaseTables();


            // Filter out Laravel system tables if needed
            $excludedTables = [
                'migrations',
                'password_reset_tokens',
                'personal_access_tokens',
                'failed_jobs',
            ];
            $tables = array_filter($tables, function ($table) use (
                $excludedTables
            ) {
                return !in_array($table, $excludedTables);
            });

            // Re-index the array after filtering
            $tables = array_values($tables);

            // Format the response
            $formattedTables = [];
            foreach ($tables as $table) {
                $formattedTables[] = [
                    'name' => $table,
                    'label' => $this->formatTableName($table),
                ];
            }


            return response()->json([
                'success' => true,
                'data' => $formattedTables,
            ]);
        } catch (\Exception $e) {
            Log::error('Error fetching database tables: ' . $e->getMessage());
            Log::error('Stack trace: ' . $e->getTraceAsString());
            return response()->json(
                [
                    'success' => false,
                    'message' => 'Error fetching database tables',
                    'error' => $e->getMessage(),
                ],
                500
            );
        }
    }

    /**
     * Get columns for a specific table
     *
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function getTableColumns(Request $request)
    {
        try {
            $tableName = $request->input('table');

            if (!$tableName) {
                return response()->json(
                    [
                        'success' => false,
                        'message' => 'Table name is required',
                    ],
                    400
                );
            }

            // Check if table exists
            if (!Schema::hasTable($tableName)) {
                return response()->json(
                    [
                        'success' => false,
                        'message' => "Table '{$tableName}' does not exist",
                    ],
                    404
                );
            }

            // Get columns for the table
            $columns = Schema::getColumnListing($tableName);

            // Get column types and additional information
            $columnDetails = [];
            foreach ($columns as $column) {
                // Get column type using the appropriate method
                $columnType = $this->getColumnType($tableName, $column);

                $columnDetails[] = [
                    'name' => $column,
                    'label' => $this->formatColumnName($column),
                    'type' => $columnType,
                    'is_primary' => $column === 'id', // Simplified assumption
                ];
            }

            return response()->json([
                'success' => true,
                'data' => [
                    'table' => $tableName,
                    'columns' => $columnDetails,
                ],
            ]);
        } catch (\Exception $e) {
            Log::error('Error fetching table columns: ' . $e->getMessage());
            return response()->json(
                [
                    'success' => false,
                    'message' => 'Error fetching table columns',
                    'error' => $e->getMessage(),
                ],
                500
            );
        }
    }

    /**
     * Get all tables and their columns
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function getAllTablesAndColumns()
    {
        try {
            // Get all tables from the database
            $tables = $this->getAllDatabaseTables();

            // Filter out Laravel system tables if needed
            $excludedTables = [
                'migrations',
                'password_reset_tokens',
                'personal_access_tokens',
                'failed_jobs',
            ];
            $tables = array_filter($tables, function ($table) use (
                $excludedTables
            ) {
                return !in_array($table, $excludedTables);
            });

            // Format the response with tables and their columns
            $result = [];
            foreach ($tables as $table) {
                $columns = Schema::getColumnListing($table);

                $columnDetails = [];
                foreach ($columns as $column) {
                    try {
                        // Get column type using the appropriate method
                        $columnType = $this->getColumnType($table, $column);

                        $columnDetails[] = [
                            'name' => $column,
                            'label' => $this->formatColumnName($column),
                            'type' => $columnType,
                            'is_primary' => $column === 'id', // Simplified assumption
                        ];
                    } catch (\Exception $e) {
                        // Skip columns that cause errors
                        Log::warning(
                            "Error getting details for column {$column} in table {$table}: " .
                                $e->getMessage()
                        );
                    }
                }

                $result[] = [
                    'name' => $table,
                    'label' => $this->formatTableName($table),
                    'columns' => $columnDetails,
                ];
            }

            return response()->json([
                'success' => true,
                'data' => $result,
            ]);
        } catch (\Exception $e) {
            Log::error(
                'Error fetching database tables and columns: ' .
                    $e->getMessage()
            );
            return response()->json(
                [
                    'success' => false,
                    'message' => 'Error fetching database tables and columns',
                    'error' => $e->getMessage(),
                ],
                500
            );
        }
    }

    /**
     * Format table name for display
     *
     * @param string $tableName
     * @return string
     */
    private function formatTableName($tableName)
    {
        // Convert snake_case to Title Case
        return ucwords(str_replace('_', ' ', $tableName));
    }

    /**
     * Get table relationships
     *
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function getTableRelationships(Request $request)
    {
        try {
            $tableName = $request->input('table');

            if (!$tableName) {
                return response()->json(
                    [
                        'success' => false,
                        'message' => 'Table name is required',
                    ],
                    400
                );
            }

            // Check if table exists
            if (!Schema::hasTable($tableName)) {
                return response()->json(
                    [
                        'success' => false,
                        'message' => "Table '{$tableName}' does not exist",
                    ],
                    404
                );
            }

            // Get foreign key columns (potential relationships)
            $columns = Schema::getColumnListing($tableName);
            $relationships = [];

            // Look for columns that might be foreign keys (ending with _id)
            foreach ($columns as $column) {
                if (preg_match('/_id$/', $column)) {
                    // Extract the related table name (remove _id suffix)
                    $relatedTableBase = preg_replace('/_id$/', '', $column);

                    // Try different pluralization patterns
                    $possibleTables = [
                        $relatedTableBase . 's', // Simple pluralization (user -> users)
                        $relatedTableBase . 'es', // For words ending in 's', 'x', 'z', 'ch', 'sh' (box -> boxes)
                        $relatedTableBase . 'ies', // For words ending in 'y' (category -> categories)
                        $relatedTableBase, // No pluralization (staff -> staff)
                    ];

                    // Special cases for irregular plurals
                    if ($relatedTableBase === 'person') {
                        $possibleTables[] = 'people';
                    }
                    if ($relatedTableBase === 'child') {
                        $possibleTables[] = 'children';
                    }
                    if ($relatedTableBase === 'foot') {
                        $possibleTables[] = 'feet';
                    }
                    if ($relatedTableBase === 'tooth') {
                        $possibleTables[] = 'teeth';
                    }
                    if ($relatedTableBase === 'goose') {
                        $possibleTables[] = 'geese';
                    }
                    if ($relatedTableBase === 'man') {
                        $possibleTables[] = 'men';
                    }
                    if ($relatedTableBase === 'woman') {
                        $possibleTables[] = 'women';
                    }
                    if ($relatedTableBase === 'mouse') {
                        $possibleTables[] = 'mice';
                    }

                    // Check if any of the possible tables exist
                    foreach ($possibleTables as $possibleTable) {
                        if (Schema::hasTable($possibleTable)) {
                            // Check if the related table has an 'id' column
                            if (Schema::hasColumn($possibleTable, 'id')) {
                                $relationships[] = [
                                    'source_table' => $tableName,
                                    'source_column' => $column,
                                    'target_table' => $possibleTable,
                                    'target_column' => 'id',
                                    'type' => 'belongs_to',
                                    'join_type' => 'INNER JOIN',
                                    'description' => "$tableName.$column references $possibleTable.id",
                                    'confidence' => 'high',
                                ];
                                break; // Found a match, no need to check other possibilities
                            }
                        }
                    }
                }
            }

            // Look for tables that might have foreign keys to this table
            $allTables = $this->getAllDatabaseTables();

            // Try different singularization patterns for the current table
            $singularTableName = rtrim($tableName, 's'); // Simple singularization
            $potentialForeignKeys = [
                $singularTableName . '_id',
                $tableName . '_id',
            ];

            // Special cases for irregular plurals
            if ($tableName === 'people') {
                $potentialForeignKeys[] = 'person_id';
            }
            if ($tableName === 'children') {
                $potentialForeignKeys[] = 'child_id';
            }
            if ($tableName === 'men') {
                $potentialForeignKeys[] = 'man_id';
            }
            if ($tableName === 'women') {
                $potentialForeignKeys[] = 'woman_id';
            }
            if ($tableName === 'feet') {
                $potentialForeignKeys[] = 'foot_id';
            }
            if ($tableName === 'teeth') {
                $potentialForeignKeys[] = 'tooth_id';
            }
            if ($tableName === 'geese') {
                $potentialForeignKeys[] = 'goose_id';
            }
            if ($tableName === 'mice') {
                $potentialForeignKeys[] = 'mouse_id';
            }

            foreach ($allTables as $otherTable) {
                if ($otherTable !== $tableName) {
                    $otherTableColumns = Schema::getColumnListing($otherTable);

                    foreach ($potentialForeignKeys as $potentialForeignKey) {
                        if (
                            in_array($potentialForeignKey, $otherTableColumns)
                        ) {
                            $relationships[] = [
                                'source_table' => $otherTable,
                                'source_column' => $potentialForeignKey,
                                'target_table' => $tableName,
                                'target_column' => 'id',
                                'type' => 'has_many',
                                'join_type' => 'LEFT JOIN',
                                'description' => "$otherTable.$potentialForeignKey references $tableName.id",
                                'confidence' => 'high',
                            ];
                        }
                    }

                    // Look for pivot tables (many-to-many relationships)
                    // Format: table1_table2 or table2_table1
                    $pivotPattern1 = $tableName . '_' . $otherTable;
                    $pivotPattern2 = $otherTable . '_' . $tableName;

                    if (in_array($pivotPattern1, $allTables)) {
                        // This is likely a pivot table
                        $pivotTable = $pivotPattern1;
                        $pivotColumns = Schema::getColumnListing($pivotTable);

                        $fk1 = $singularTableName . '_id';
                        $fk2 = rtrim($otherTable, 's') . '_id';

                        if (
                            in_array($fk1, $pivotColumns) &&
                            in_array($fk2, $pivotColumns)
                        ) {
                            $relationships[] = [
                                'source_table' => $tableName,
                                'source_column' => 'id',
                                'pivot_table' => $pivotTable,
                                'pivot_source_column' => $fk1,
                                'pivot_target_column' => $fk2,
                                'target_table' => $otherTable,
                                'target_column' => 'id',
                                'type' => 'many_to_many',
                                'join_type' => 'LEFT JOIN',
                                'description' => "$tableName has many $otherTable through $pivotTable",
                                'confidence' => 'medium',
                            ];
                        }
                    } elseif (in_array($pivotPattern2, $allTables)) {
                        // This is likely a pivot table
                        $pivotTable = $pivotPattern2;
                        $pivotColumns = Schema::getColumnListing($pivotTable);

                        $fk1 = $singularTableName . '_id';
                        $fk2 = rtrim($otherTable, 's') . '_id';

                        if (
                            in_array($fk1, $pivotColumns) &&
                            in_array($fk2, $pivotColumns)
                        ) {
                            $relationships[] = [
                                'source_table' => $tableName,
                                'source_column' => 'id',
                                'pivot_table' => $pivotTable,
                                'pivot_source_column' => $fk1,
                                'pivot_target_column' => $fk2,
                                'target_table' => $otherTable,
                                'target_column' => 'id',
                                'type' => 'many_to_many',
                                'join_type' => 'LEFT JOIN',
                                'description' => "$tableName has many $otherTable through $pivotTable",
                                'confidence' => 'medium',
                            ];
                        }
                    }
                }
            }

            return response()->json([
                'success' => true,
                'data' => [
                    'table' => $tableName,
                    'relationships' => $relationships,
                ],
            ]);
        } catch (\Exception $e) {
            Log::error(
                'Error detecting table relationships: ' . $e->getMessage()
            );
            return response()->json(
                [
                    'success' => false,
                    'message' => 'Error detecting table relationships',
                    'error' => $e->getMessage(),
                ],
                500
            );
        }
    }

    /**
     * Get suggested joins for a table
     *
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function getSuggestedJoins(Request $request)
    {
        try {
            $tableName = $request->input('table');

            if (!$tableName) {
                return response()->json(
                    [
                        'success' => false,
                        'message' => 'Table name is required',
                    ],
                    400
                );
            }

            // Check if table exists
            if (!Schema::hasTable($tableName)) {
                return response()->json(
                    [
                        'success' => false,
                        'message' => "Table '{$tableName}' does not exist",
                    ],
                    404
                );
            }

            // Get relationships for the table
            $relationships = $this->getRelationshipsForTable($tableName);

            // Convert relationships to suggested joins
            $suggestedJoins = [];

            foreach ($relationships as $relationship) {
                if ($relationship['type'] === 'belongs_to') {
                    // This table belongs to another table
                    $suggestedJoins[] = [
                        'sourceTable' => $relationship['source_table'],
                        'sourceColumn' => $relationship['source_column'],
                        'targetTable' => $relationship['target_table'],
                        'targetColumn' => $relationship['target_column'],
                        'joinType' => $relationship['join_type'],
                        'description' => $relationship['description'],
                        'confidence' => $relationship['confidence'],
                    ];
                } elseif ($relationship['type'] === 'has_many') {
                    // Another table belongs to this table
                    $suggestedJoins[] = [
                        'sourceTable' => $relationship['target_table'],
                        'sourceColumn' => $relationship['target_column'],
                        'targetTable' => $relationship['source_table'],
                        'targetColumn' => $relationship['source_column'],
                        'joinType' => $relationship['join_type'],
                        'description' => $relationship['description'],
                        'confidence' => $relationship['confidence'],
                    ];
                } elseif ($relationship['type'] === 'many_to_many') {
                    // Many-to-many relationship through a pivot table
                    // Automatically include both the pivot table join AND the end table join
                    
                    // First join from main table to pivot table
                    $suggestedJoins[] = [
                        'sourceTable' => $relationship['source_table'],
                        'sourceColumn' => $relationship['source_column'],
                        'targetTable' => $relationship['pivot_table'],
                        'targetColumn' => $relationship['pivot_source_column'],
                        'joinType' => $relationship['join_type'],
                        'description' => "Connect to {$relationship['target_table']} (including relationship details)",
                        'confidence' => $relationship['confidence'],
                        'isPivotRelationship' => true,
                        'endTable' => $relationship['target_table'],
                        'autoIncludeEndTable' => true,
                        'secondJoin' => [
                            'sourceTable' => $relationship['pivot_table'],
                            'sourceColumn' => $relationship['pivot_target_column'],
                            'targetTable' => $relationship['target_table'],
                            'targetColumn' => $relationship['target_column'],
                            'joinType' => $relationship['join_type'],
                            'description' => "Auto-included: {$relationship['target_table']} details",
                            'confidence' => $relationship['confidence'],
                        ],
                    ];
                }
            }

            return response()->json([
                'success' => true,
                'data' => [
                    'table' => $tableName,
                    'suggestedJoins' => $suggestedJoins,
                ],
            ]);
        } catch (\Exception $e) {
            Log::error('Error getting suggested joins: ' . $e->getMessage());
            return response()->json(
                [
                    'success' => false,
                    'message' => 'Error getting suggested joins',
                    'error' => $e->getMessage(),
                ],
                500
            );
        }
    }

    /**
     * Get relationships for a table
     *
     * @param string $tableName
     * @return array
     */
    private function getRelationshipsForTable($tableName)
    {
        // Get foreign key columns (potential relationships)
        $columns = Schema::getColumnListing($tableName);
        $relationships = [];

        // Look for columns that might be foreign keys (ending with _id)
        foreach ($columns as $column) {
            if (preg_match('/_id$/', $column)) {
                // Extract the related table name (remove _id suffix)
                $relatedTableBase = preg_replace('/_id$/', '', $column);

                // Try different pluralization patterns
                $possibleTables = [
                    $relatedTableBase . 's', // Simple pluralization (user -> users)
                    $relatedTableBase . 'es', // For words ending in 's', 'x', 'z', 'ch', 'sh' (box -> boxes)
                    $relatedTableBase . 'ies', // For words ending in 'y' (category -> categories)
                    $relatedTableBase, // No pluralization (staff -> staff)
                ];

                // Special cases for irregular plurals
                if ($relatedTableBase === 'person') {
                    $possibleTables[] = 'people';
                }
                if ($relatedTableBase === 'child') {
                    $possibleTables[] = 'children';
                }
                if ($relatedTableBase === 'foot') {
                    $possibleTables[] = 'feet';
                }
                if ($relatedTableBase === 'tooth') {
                    $possibleTables[] = 'teeth';
                }
                if ($relatedTableBase === 'goose') {
                    $possibleTables[] = 'geese';
                }
                if ($relatedTableBase === 'man') {
                    $possibleTables[] = 'men';
                }
                if ($relatedTableBase === 'woman') {
                    $possibleTables[] = 'women';
                }
                if ($relatedTableBase === 'mouse') {
                    $possibleTables[] = 'mice';
                }

                // Check if any of the possible tables exist
                foreach ($possibleTables as $possibleTable) {
                    if (Schema::hasTable($possibleTable)) {
                        // Check if the related table has an 'id' column
                        if (Schema::hasColumn($possibleTable, 'id')) {
                            $relationships[] = [
                                'source_table' => $tableName,
                                'source_column' => $column,
                                'target_table' => $possibleTable,
                                'target_column' => 'id',
                                'type' => 'belongs_to',
                                'join_type' => 'INNER JOIN',
                                'description' => "$tableName.$column references $possibleTable.id",
                                'confidence' => 'high',
                            ];
                            break; // Found a match, no need to check other possibilities
                        }
                    }
                }
            }
        }

        // Look for tables that might have foreign keys to this table
        $allTables = $this->getAllDatabaseTables();

        // Try different singularization patterns for the current table
        $singularTableName = rtrim($tableName, 's'); // Simple singularization
        $potentialForeignKeys = [
            $singularTableName . '_id',
            $tableName . '_id',
        ];

        // Special cases for irregular plurals
        if ($tableName === 'people') {
            $potentialForeignKeys[] = 'person_id';
        }
        if ($tableName === 'children') {
            $potentialForeignKeys[] = 'child_id';
        }
        if ($tableName === 'men') {
            $potentialForeignKeys[] = 'man_id';
        }
        if ($tableName === 'women') {
            $potentialForeignKeys[] = 'woman_id';
        }
        if ($tableName === 'feet') {
            $potentialForeignKeys[] = 'foot_id';
        }
        if ($tableName === 'teeth') {
            $potentialForeignKeys[] = 'tooth_id';
        }
        if ($tableName === 'geese') {
            $potentialForeignKeys[] = 'goose_id';
        }
        if ($tableName === 'mice') {
            $potentialForeignKeys[] = 'mouse_id';
        }

        foreach ($allTables as $otherTable) {
            if ($otherTable !== $tableName) {
                $otherTableColumns = Schema::getColumnListing($otherTable);

                foreach ($potentialForeignKeys as $potentialForeignKey) {
                    if (in_array($potentialForeignKey, $otherTableColumns)) {
                        $relationships[] = [
                            'source_table' => $otherTable,
                            'source_column' => $potentialForeignKey,
                            'target_table' => $tableName,
                            'target_column' => 'id',
                            'type' => 'has_many',
                            'join_type' => 'LEFT JOIN',
                            'description' => "$otherTable.$potentialForeignKey references $tableName.id",
                            'confidence' => 'high',
                        ];
                    }
                }

                // Look for pivot tables (many-to-many relationships)
                // Format: table1_table2 or table2_table1, also handle mixed singular/plural forms
                $pivotPattern1 = $tableName . '_' . $otherTable;
                $pivotPattern2 = $otherTable . '_' . $tableName;
                
                // More robust singularization for common patterns
                $otherTableSingular = $otherTable;
                if (str_ends_with($otherTable, 'ies')) {
                    $otherTableSingular = substr($otherTable, 0, -3) . 'y';
                } elseif (str_ends_with($otherTable, 's') && !str_ends_with($otherTable, 'ss')) {
                    $otherTableSingular = substr($otherTable, 0, -1);
                }
                
                // Also check for singular forms: lead_facility, client_agreement, etc.
                $pivotPattern3 = $singularTableName . '_' . $otherTableSingular;
                $pivotPattern4 = $otherTableSingular . '_' . $singularTableName;

                $possiblePivotTables = [$pivotPattern1, $pivotPattern2, $pivotPattern3, $pivotPattern4];

                foreach ($possiblePivotTables as $pivotPattern) {
                    if (in_array($pivotPattern, $allTables)) {
                        // This is likely a pivot table
                        $pivotTable = $pivotPattern;
                        $pivotColumns = Schema::getColumnListing($pivotTable);

                        $fk1 = $singularTableName . '_id';
                        $fk2 = $otherTableSingular . '_id';

                        if (
                            in_array($fk1, $pivotColumns) &&
                            in_array($fk2, $pivotColumns)
                        ) {
                            $relationships[] = [
                                'source_table' => $tableName,
                                'source_column' => 'id',
                                'pivot_table' => $pivotTable,
                                'pivot_source_column' => $fk1,
                                'pivot_target_column' => $fk2,
                                'target_table' => $otherTable,
                                'target_column' => 'id',
                                'type' => 'many_to_many',
                                'join_type' => 'LEFT JOIN',
                                'description' => "$tableName has many $otherTable through $pivotTable",
                                'confidence' => 'medium',
                            ];
                            break; // Found one, no need to check other patterns
                        }
                    }
                }
            }
        }

        return $relationships;
    }

    /**
     * Format column name for display
     *
     * @param string $columnName
     * @return string
     */
    private function formatColumnName($columnName)
    {
        // Convert snake_case to Title Case
        return ucwords(str_replace('_', ' ', $columnName));
    }

    /**
     * Get all database tables using the appropriate method based on Laravel version
     *
     * @return array
     */
    private function getAllDatabaseTables()
    {
        try {

            // Use the simplest and most reliable method - SHOW TABLES
            $databaseName = DB::connection()->getDatabaseName();

            $tables = DB::select('SHOW TABLES');
            if (!empty($tables)) {

                // Determine the property name from the first table
                $firstTable = $tables[0];


                $propertyName = 'Tables_in_' . strtolower($databaseName);

                // If the property doesn't exist, try to find the first property
                if (!property_exists($firstTable, $propertyName)) {
                    $vars = get_object_vars($firstTable);
                    if (!empty($vars)) {
                        $propertyName = array_key_first($vars);
                    } else {
                        Log::warning('No properties found in table object');
                        return [];
                    }
                }

                $tableNames = array_map(function ($table) use ($propertyName) {
                    return $table->$propertyName;
                }, $tables);


                return $tableNames;
            }

            Log::warning('No tables found using SHOW TABLES');
            return [];
        } catch (\Exception $e) {
            Log::error('Error getting database tables: ' . $e->getMessage());
            Log::error('Stack trace: ' . $e->getTraceAsString());
            return [];
        }
    }

    /**
     * Get column type using the appropriate method based on Laravel version
     *
     * @param string $tableName
     * @param string $column
     * @return string
     */
    private function getColumnType($tableName, $column)
    {
        try {

            // Method 1: Using Schema Builder (Laravel 8+)
            if (
                method_exists(
                    DB::connection()->getSchemaBuilder(),
                    'getColumnType'
                )
            ) {
                try {
                    $type = DB::connection()
                        ->getSchemaBuilder()
                        ->getColumnType($tableName, $column);

                    // Check if it's a JSON type
                    if ($type === 'json') {
                        return 'json';
                    }

                    return $type;
                } catch (\Exception $e) {
                    // Fall through to method 2
                }
            }

            // Method 2: Using raw query
            try {
                $databaseName = DB::connection()->getDatabaseName();
                $connection = DB::connection()->getDriverName();


                // Different query based on database type
                if ($connection === 'pgsql') {
                    // PostgreSQL has native JSON type
                    $result = DB::select(
                        "SELECT format_type(a.atttypid, a.atttypmod) as type
                                         FROM pg_attribute a
                                         JOIN pg_class t ON a.attrelid = t.oid
                                         JOIN pg_namespace s ON t.relnamespace = s.oid
                                         WHERE a.attname = ?
                                         AND t.relname = ?
                                         AND s.nspname = current_schema()",
                        [$column, $tableName]
                    );

                    if (!empty($result) && isset($result[0]->type)) {
                        $type = strtolower($result[0]->type);

                        // Check for JSON types
                        if ($type === 'json' || $type === 'jsonb') {
                            return 'json';
                        }

                        return $type;
                    }
                } else {
                    // MySQL and other databases
                    $result = DB::select(
                        'SELECT DATA_TYPE FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND COLUMN_NAME = ?',
                        [$databaseName, $tableName, $column]
                    );

                    if (!empty($result)) {
                        $type = strtolower($result[0]->DATA_TYPE);

                        // Check for JSON type
                        if ($type === 'json') {
                            return 'json';
                        }

                        return $type;
                    }
                }
            } catch (\Exception $e) {
            }

            // Method 3: Try to get column listing and infer type
            try {
                if (Schema::hasColumn($tableName, $column)) {
                    // Try to infer type from a sample value
                    $sample = DB::table($tableName)
                        ->select($column)
                        ->whereNotNull($column)
                        ->first();

                    if ($sample && property_exists($sample, $column)) {
                        $value = $sample->$column;
                        $inferredType = gettype($value);

                        // Check if it might be a JSON string
                        if ($inferredType === 'string') {
                            $trimmed = trim($value);
                            if (
                                (substr($trimmed, 0, 1) === '{' &&
                                    substr($trimmed, -1) === '}') ||
                                (substr($trimmed, 0, 1) === '[' &&
                                    substr($trimmed, -1) === ']')
                            ) {
                                // Try to decode it as JSON
                                $decoded = json_decode($value, true);
                                if (
                                    json_last_error() === JSON_ERROR_NONE &&
                                    is_array($decoded)
                                ) {
                                    return 'json';
                                }
                            }
                        }

                        return $inferredType;
                    }
                }
            } catch (\Exception $e) {
            }

            Log::warning('Could not determine column type');
            return 'unknown';
        } catch (\Exception $e) {
            Log::warning(
                "Error getting column type for {$column} in {$tableName}: " .
                    $e->getMessage()
            );
            return 'unknown';
        }
    }

    /**
     * Simple method to get tables directly using SHOW TABLES
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function getTablesSimple()
    {
        try {
            $databaseName = DB::connection()->getDatabaseName();
            $tables = DB::select('SHOW TABLES');

            if (!empty($tables)) {
                $firstTable = $tables[0];
                $propertyName = 'Tables_in_' . strtolower($databaseName);

                // If the property doesn't exist, try to find the first property
                if (!property_exists($firstTable, $propertyName)) {
                    $vars = get_object_vars($firstTable);
                    if (!empty($vars)) {
                        $propertyName = array_key_first($vars);
                    }
                }

                $tableNames = array_map(function ($table) use ($propertyName) {
                    return $table->$propertyName;
                }, $tables);

                return response()->json([
                    'success' => true,
                    'database' => $databaseName,
                    'property_name' => $propertyName,
                    'count' => count($tables),
                    'tables' => $tableNames,
                ]);
            }

            return response()->json([
                'success' => false,
                'message' => 'No tables found',
                'database' => $databaseName,
            ]);
        } catch (\Exception $e) {
            Log::error('Error in getTablesSimple: ' . $e->getMessage());
            Log::error('Stack trace: ' . $e->getTraceAsString());
            return response()->json(
                [
                    'success' => false,
                    'message' => 'Error fetching tables',
                    'error' => $e->getMessage(),
                ],
                500
            );
        }
    }

    /**
     * Get a specific column type from a table
     *
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function getColumnTypeInfo(Request $request)
    {
        try {
            $tableName = $request->input('table');
            $columnName = $request->input('column');

            if (!$tableName || !$columnName) {
                return response()->json(
                    [
                        'success' => false,
                        'message' => 'Table name and column name are required',
                    ],
                    400
                );
            }

            // Check if table exists
            if (!Schema::hasTable($tableName)) {
                return response()->json(
                    [
                        'success' => false,
                        'message' => "Table '{$tableName}' does not exist",
                    ],
                    404
                );
            }

            // Check if column exists
            if (!Schema::hasColumn($tableName, $columnName)) {
                return response()->json(
                    [
                        'success' => false,
                        'message' => "Column '{$columnName}' does not exist in table '{$tableName}'",
                    ],
                    404
                );
            }

            $columnType = $this->getColumnType($tableName, $columnName);

            return response()->json([
                'success' => true,
                'data' => [
                    'table' => $tableName,
                    'column' => $columnName,
                    'type' => $columnType,
                ],
            ]);
        } catch (\Exception $e) {
            Log::error('Error getting column type: ' . $e->getMessage());
            return response()->json(
                [
                    'success' => false,
                    'message' => 'Error getting column type',
                    'error' => $e->getMessage(),
                ],
                500
            );
        }
    }

    /**
     * Get all saved reports
     *
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function getReports(Request $request)
    {
        try {
            // Get user ID from authenticated user or request
            $userId = Auth::id() ?? $request->input('user_id');

            // Query to get reports
            $query = ReportBuilder::query();

            // Filter by user if user ID is provided
            if ($userId) {
                $query->where(function ($q) use ($userId) {
                    $q->where('user_id', $userId)->orWhere('is_public', true);
                });
            } else {
                // If no user ID, only return public reports
                $query->where('is_public', true);
            }

            // Get reports
            $reports = $query->get();

            return response()->json([
                'success' => true,
                'data' => $reports,
            ]);
        } catch (\Exception $e) {
            Log::error('Error fetching reports: ' . $e->getMessage());
            return response()->json(
                [
                    'success' => false,
                    'message' => 'Error fetching reports',
                    'error' => $e->getMessage(),
                ],
                500
            );
        }
    }

    /**
     * Get a specific report by ID
     *
     * @param Request $request
     * @param int $id
     * @return \Illuminate\Http\JsonResponse
     */
    public function getReport(Request $request, $id)
    {
        try {
            $report = ReportBuilder::findOrFail($id);

            // Check if user has access to this report
            $userId = Auth::id() ?? $request->input('user_id');
            if (!$report->is_public && $report->user_id !== $userId) {
                return response()->json(
                    [
                        'success' => false,
                        'message' =>
                            'You do not have permission to view this report',
                    ],
                    403
                );
            }

            return response()->json([
                'success' => true,
                'data' => $report,
            ]);
        } catch (\Exception $e) {
            Log::error('Error fetching report: ' . $e->getMessage());
            return response()->json(
                [
                    'success' => false,
                    'message' => 'Error fetching report',
                    'error' => $e->getMessage(),
                ],
                500
            );
        }
    }

    /**
     * Save a new report
     *
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function saveReport(Request $request)
    {
        try {
            // Validate request
            $validated = $request->validate([
                'label' => 'required|string|max:255',
                'detail' => 'required|array',
                'is_public' => 'boolean',
            ]);

            // Get user ID from authenticated user or request
            $userId = Auth::id() ?? $request->input('user_id');

            // Create new report
            $report = new ReportBuilder();
            $report->label = $validated['label'];
            $report->detail = $validated['detail'];
            $report->is_public = $validated['is_public'] ?? false;
            $report->user_id = $userId;
            $report->save();

            return response()->json([
                'success' => true,
                'message' => 'Report saved successfully',
                'data' => $report,
            ]);
        } catch (\Exception $e) {
            Log::error('Error saving report: ' . $e->getMessage());
            return response()->json(
                [
                    'success' => false,
                    'message' => 'Error saving report',
                    'error' => $e->getMessage(),
                ],
                500
            );
        }
    }

    /**
     * Update an existing report
     *
     * @param Request $request
     * @param int $id
     * @return \Illuminate\Http\JsonResponse
     */
    public function updateReport(Request $request, $id)
    {
        try {
            // Find the report
            $report = ReportBuilder::findOrFail($id);

            // Check if user has permission to update this report
            $userId = Auth::id() ?? $request->input('user_id');
            if ($report->user_id !== $userId) {
                return response()->json(
                    [
                        'success' => false,
                        'message' =>
                            'You do not have permission to update this report',
                    ],
                    403
                );
            }

            // Validate request
            $validated = $request->validate([
                'label' => 'string|max:255',
                'detail' => 'array',
                'is_public' => 'boolean',
            ]);

            // Update report
            if (isset($validated['label'])) {
                $report->label = $validated['label'];
            }

            if (isset($validated['detail'])) {
                $report->detail = $validated['detail'];
            }

            if (isset($validated['is_public'])) {
                $report->is_public = $validated['is_public'];
            }

            $report->save();

            return response()->json([
                'success' => true,
                'message' => 'Report updated successfully',
                'data' => $report,
            ]);
        } catch (\Exception $e) {
            Log::error('Error updating report: ' . $e->getMessage());
            return response()->json(
                [
                    'success' => false,
                    'message' => 'Error updating report',
                    'error' => $e->getMessage(),
                ],
                500
            );
        }
    }

    /**
     * Delete a report
     *
     * @param Request $request
     * @param int $id
     * @return \Illuminate\Http\JsonResponse
     */
    public function deleteReport(Request $request, $id)
    {
        try {
            // Find the report
            $report = ReportBuilder::findOrFail($id);

            // Check if user has permission to delete this report
            $userId = Auth::id() ?? $request->input('user_id');
            if ($report->user_id !== $userId) {
                return response()->json(
                    [
                        'success' => false,
                        'message' =>
                            'You do not have permission to delete this report',
                    ],
                    403
                );
            }

            // Delete the report
            $report->delete();

            return response()->json([
                'success' => true,
                'message' => 'Report deleted successfully',
            ]);
        } catch (\Exception $e) {
            Log::error('Error deleting report: ' . $e->getMessage());
            return response()->json(
                [
                    'success' => false,
                    'message' => 'Error deleting report',
                    'error' => $e->getMessage(),
                ],
                500
            );
        }
    }

    /**
     * Execute a report query and return the results
     *
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function executeQuery(Request $request)
    {
        try {
            // Process the request
            
            // Log raw request to debug filter issues
            Log::info('🔍 [DEBUG] Raw request data:', [
                'all_data' => $request->all(),
                'query_keys' => array_keys($request->input('query', [])),
                'has_filters' => isset($request->input('query', [])['filters']),
                'filter_count' => count($request->input('query.filters', [])),
                'raw_filters' => $request->input('query.filters', []),
            ]);

            // Get all request data without strict validation to preserve filters
            $validated = [
                'query' => $request->input('query'),
                'report_id' => $request->input('report_id'),
                'limit' => $request->input('limit'),
                'offset' => $request->input('offset'),
                'parameters' => $request->input('parameters', []),
            ];
            
            // Log what we extracted to debug filter preservation
            Log::info('🔍 [DEBUG] Extracted data:', [
                'query_keys' => array_keys($validated['query'] ?? []),
                'has_filters_in_validated' => isset($validated['query']['filters']),
                'filter_count_validated' => count($validated['query']['filters'] ?? []),
                'validated_filters' => $validated['query']['filters'] ?? [],
            ]);
            
            // Basic validation
            if (empty($validated['query']) || !is_array($validated['query'])) {
                throw new \Exception('Query parameter is required and must be an array');
            }

            // Data validated successfully

            // Set default limit if not provided
            $limit = $validated['limit'] ?? 100;
            $offset = $validated['offset'] ?? 0;
            $parameters = $validated['parameters'] ?? [];

            // Get the query configuration
            $queryConfig = $validated['query'];
            
            // Log the final queryConfig before buildAndExecuteQuery
            Log::info('🔍 [DEBUG] Final queryConfig before buildAndExecuteQuery:', [
                'queryConfig_keys' => array_keys($queryConfig),
                'has_filters_final' => isset($queryConfig['filters']),
                'filter_count_final' => count($queryConfig['filters'] ?? []),
                'final_filters' => $queryConfig['filters'] ?? [],
                'unique_config' => $queryConfig['unique'] ?? 'not set',
            ]);

            // If report_id is provided, load the report configuration
            if (isset($validated['report_id'])) {
                $report = ReportBuilder::findOrFail($validated['report_id']);

                // Check if user has access to this report
                $userId = Auth::id() ?? $request->input('user_id');
                if (!$report->is_public && $report->user_id !== $userId) {
                    return response()->json(
                        [
                            'success' => false,
                            'message' =>
                                'You do not have permission to execute this report',
                        ],
                        403
                    );
                }

                // Use the report configuration if query is not provided
                if (empty($queryConfig)) {
                    $queryConfig = $report->detail;
                }
            }

            // Build and execute the SQL query
            $result = $this->buildAndExecuteQuery(
                $queryConfig,
                $limit,
                $offset,
                $parameters
            );

            // Log the result for debugging

            return response()->json([
                'success' => true,
                'data' => $result['data'],
                'total' => $result['total'],
                'sql' => $result['sql'], // Include the generated SQL for debugging
            ]);
        } catch (\Exception $e) {
            Log::error('Error executing report query: ' . $e->getMessage());
            Log::error('Stack trace: ' . $e->getTraceAsString());
            return response()->json(
                [
                    'success' => false,
                    'message' => 'Error executing report query',
                    'error' => $e->getMessage(),
                ],
                500
            );
        }
    }

    /**
     * Build and execute a SQL query based on the report configuration
     *
     * @param array $queryConfig
     * @param int $limit
     * @param int $offset
     * @param array $parameters
     * @return array
     */
    private function buildAndExecuteQuery(
        array $queryConfig,
        int $limit = 100,
        int $offset = 0,
        array $parameters = []
    ) {
        // Log the query configuration for debugging

        // Log the query configuration for debugging (removed verbose debugging)

        // Extract configuration
        $mainTable = $queryConfig['mainTable'] ?? null;
        $requestColumns = $queryConfig['columns'] ?? [];
        $joins = $queryConfig['joins'] ?? [];
        $filters = $queryConfig['filters'] ?? [];
        $sorting = $queryConfig['sorting'] ?? [];
        $groupBy = $queryConfig['groupBy'] ?? [];
        $unique = $queryConfig['unique'] ?? ['enabled' => false];
        $excludedRows = $queryConfig['excludedRows'] ?? [];

        // Validate main table
        if (!$mainTable) {
            throw new \Exception('Main table is required');
        }

        // Validate columns
        if (empty($requestColumns)) {
            throw new \Exception('At least one column must be selected');
        }

        // Start building the query
        $query = DB::table($mainTable);

        // Add joins
        // Keep track of joined tables to handle chain joins
        $joinedTables = [$mainTable];

        // First pass: Analyze the joins to identify potential pivot tables and chain joins
        $tableRelationships = [];
        $tableColumns = [];

        // Get all tables and their columns to help with relationship detection
        try {
            // Get all tables from the database
            $allTables = $this->getAllDatabaseTables();

            // For each table, get its columns
            foreach ($allTables as $table) {
                try {
                    $columns = Schema::getColumnListing($table);
                    $tableColumns[$table] = $columns;
                } catch (\Exception $e) {
                    // Skip tables that cause errors
                    Log::warning(
                        "Error getting columns for table {$table}: " .
                            $e->getMessage()
                    );
                }
            }

        } catch (\Exception $e) {
            Log::warning(
                'Error retrieving database tables: ' . $e->getMessage()
            );
        }

        // Analyze the joins to build a relationship map
        foreach ($joins as $index => $join) {
            $targetTable = $join['targetTable'] ?? null;
            $sourceColumn = $join['sourceColumn'] ?? null;
            $targetColumn = $join['targetColumn'] ?? null;

            if (!$targetTable || !$sourceColumn || !$targetColumn) {
                continue; // Skip invalid joins
            }

            // Build a map of potential relationships between tables
            // This helps us identify which tables should be joined to which
            if (!isset($tableRelationships[$targetTable])) {
                $tableRelationships[$targetTable] = [];
            }

            // For each target table, keep track of columns that might be foreign keys
            // and the tables they might reference
            if (preg_match('/_id$/', $targetColumn)) {
                $possibleReferencedTable = preg_replace(
                    '/_id$/',
                    's',
                    $targetColumn
                );
                if (
                    !in_array(
                        $possibleReferencedTable,
                        $tableRelationships[$targetTable]
                    )
                ) {
                    $tableRelationships[
                        $targetTable
                    ][] = $possibleReferencedTable;
                }
            }

            // Also track the explicit join relationship
            if (!in_array($mainTable, $tableRelationships[$targetTable])) {
                $tableRelationships[$targetTable][] = $mainTable;
            }

            // For pivot tables (tables with names like table1_table2), identify potential relationships
            if (strpos($targetTable, '_') !== false) {
                $tableParts = explode('_', $targetTable);

                // For each part, check if it might be a related table
                foreach ($tableParts as $part) {
                    // Try both singular and plural forms
                    $singularForm = $part;
                    $pluralForm = $part . 's';

                    // Add potential relationships
                    if (in_array($singularForm, $allTables)) {
                        $tableRelationships[$targetTable][] = $singularForm;
                    }
                    if (in_array($pluralForm, $allTables)) {
                        $tableRelationships[$targetTable][] = $pluralForm;
                    }
                }
            }
        }

        // Log the relationship map for debugging

        // Second pass: Process the joins with the relationship information
        foreach ($joins as $index => $join) {
            $joinType = strtolower($join['joinType'] ?? 'inner');
            $targetTable = $join['targetTable'] ?? null;
            $sourceColumn = $join['sourceColumn'] ?? null;
            $targetColumn = $join['targetColumn'] ?? null;
            // Get the source table from the join configuration or use the main table as default
            $sourceTable = $join['sourceTable'] ?? $mainTable;

            if (!$targetTable || !$sourceColumn || !$targetColumn) {
                continue; // Skip invalid joins
            }

            // Determine the most likely source table for this join

            // Try to determine the correct source table for this join

            // First, check if this is a pivot table join
            $isPivotTable = false;
            if (strpos($targetTable, '_') !== false) {
                $isPivotTable = true;
            }

            // Check if the target table has columns that match our source column
            $targetHasSourceColumn = false;
            if (
                isset($tableColumns[$targetTable]) &&
                in_array($sourceColumn, $tableColumns[$targetTable])
            ) {
                $targetHasSourceColumn = true;
            }

            // Check if any previously joined table has the target column
            $tableWithTargetColumn = null;
            foreach ($joinedTables as $joinedTable) {
                if (
                    isset($tableColumns[$joinedTable]) &&
                    in_array($targetColumn, $tableColumns[$joinedTable])
                ) {
                    $tableWithTargetColumn = $joinedTable;
                    break;
                }
            }

            // Case 1: If this is a chain join (not the first join)
            if ($index > 0) {
                $prevJoin = $joins[$index - 1];
                $prevTargetTable = $prevJoin['targetTable'] ?? null;

                // If the previous join's target table is already joined, it might be our source
                if (in_array($prevTargetTable, $joinedTables)) {
                    // Check if the column names make sense for a relationship
                    if (
                        $sourceColumn === 'id' &&
                        preg_match('/_id$/', $targetColumn)
                    ) {
                        // This looks like a foreign key relationship from prev target to current target
                        $sourceTable = $prevTargetTable;
                    } elseif (
                        preg_match('/_id$/', $sourceColumn) &&
                        $targetColumn === 'id'
                    ) {
                        // This looks like a foreign key relationship from current target to prev target
                        $sourceTable = $prevTargetTable;
                    }
                    // If the previous target table has the source column, it's likely our source
                    elseif (
                        isset($tableColumns[$prevTargetTable]) &&
                        in_array($sourceColumn, $tableColumns[$prevTargetTable])
                    ) {
                        $sourceTable = $prevTargetTable;
                    }
                    // If the target table has a column that references the previous target
                    elseif (isset($tableColumns[$targetTable])) {
                        $prevTableSingular = rtrim($prevTargetTable, 's');
                        $potentialForeignKey = $prevTableSingular . '_id';

                        if (
                            in_array(
                                $potentialForeignKey,
                                $tableColumns[$targetTable]
                            )
                        ) {
                            $sourceTable = $prevTargetTable;
                        }
                    }
                }
            }

            // Case 2: If the target table is a pivot table
            if ($isPivotTable) {
                // Extract potential table names from the pivot table name
                $tableParts = explode('_', $targetTable);

                // Check if any of the parts match a table we've already joined
                foreach ($tableParts as $part) {
                    // Try both singular and plural forms
                    $singularForm = $part;
                    $pluralForm = $part . 's';

                    if (in_array($singularForm, $joinedTables)) {
                        $sourceTable = $singularForm;
                        break;
                    } elseif (in_array($pluralForm, $joinedTables)) {
                        $sourceTable = $pluralForm;
                        break;
                    }
                }

                // If the target column ends with _id, it might reference another table
                if (preg_match('/_id$/', $targetColumn)) {
                    $referencedTable = preg_replace(
                        '/_id$/',
                        's',
                        $targetColumn
                    );
                    if (in_array($referencedTable, $joinedTables)) {
                        $sourceTable = $referencedTable;
                    }
                }
            }

            // Case 3: If the source column is 'id' and target column ends with '_id'
            // This is likely a foreign key relationship
            elseif (
                $sourceColumn === 'id' &&
                preg_match('/_id$/', $targetColumn)
            ) {
                // The target column references the source table
                $referencedTable = preg_replace('/_id$/', 's', $targetColumn);

                // If the referenced table is in our joined tables, use it as the source
                if (in_array($referencedTable, $joinedTables)) {
                    $sourceTable = $referencedTable;
                }
            }

            // Case 4: If the target column is 'id' and source column ends with '_id'
            // This is likely a foreign key relationship in the reverse direction
            elseif (
                $targetColumn === 'id' &&
                preg_match('/_id$/', $sourceColumn)
            ) {
                // The source column references the target table
                $referencedTable = preg_replace('/_id$/', 's', $sourceColumn);

                // If the referenced table matches the target table, this is a valid join
                if ($referencedTable === $targetTable) {
                    // We need to find a table that has the source column
                    foreach ($joinedTables as $joinedTable) {
                        if (
                            isset($tableColumns[$joinedTable]) &&
                            in_array($sourceColumn, $tableColumns[$joinedTable])
                        ) {
                            $sourceTable = $joinedTable;
                            break;
                        }
                    }
                }
            }

            // Case 5: If we found a table that has the target column
            elseif ($tableWithTargetColumn) {
                $sourceTable = $tableWithTargetColumn;
            }

            // Add the target table to our list of joined tables
            $joinedTables[] = $targetTable;

            // Log the join for debugging

            // Determine join method
            // Normalize join type to lowercase and trim any extra spaces
            $joinType = strtolower(trim($joinType));

            // Remove the word "join" if it's included
            $joinType = str_replace(' join', '', $joinType);

            // Log the join type for debugging

            switch ($joinType) {
                case 'left':
                    $query->leftJoin(
                        $targetTable,
                        "$sourceTable.$sourceColumn",
                        '=',
                        "$targetTable.$targetColumn"
                    );
                    break;
                case 'right':
                    $query->rightJoin(
                        $targetTable,
                        "$sourceTable.$sourceColumn",
                        '=',
                        "$targetTable.$targetColumn"
                    );
                    break;
                case 'full':
                    // Full outer join is not directly supported in all databases
                    // For MySQL, we can simulate it with a UNION of LEFT and RIGHT joins
                    // But for simplicity, we'll use LEFT JOIN as a fallback
                    $query->leftJoin(
                        $targetTable,
                        "$sourceTable.$sourceColumn",
                        '=',
                        "$targetTable.$targetColumn"
                    );
                    break;
                case 'inner':
                default:
                    $query->join(
                        $targetTable,
                        "$sourceTable.$sourceColumn",
                        '=',
                        "$targetTable.$targetColumn"
                    );
                    break;
            }
        }

        // Add columns
        $selectColumns = [];
        
        // Log all columns for debugging
        
        foreach ($requestColumns as $column) {
            $tableName = $column['table'] ?? $mainTable;
            $columnName = $column['column'] ?? null;
            $alias = $column['alias'] ?? null;

            if (!$columnName) {
                continue; // Skip invalid columns
            }

            // Log each column processing

            // Check if this is a calculated field
            if (isset($column['isCalculated']) && $column['isCalculated'] === true) {
                // Handle calculated fields
                $formula = $column['formula'] ?? null;
                if ($formula) {
                    $displayName = $column['displayName'] ?? $columnName;
                    
                    // Basic security validation for SQL injection prevention
                    $safeFormula = $this->validateCalculatedFieldFormula($formula);
                    if ($safeFormula) {
                        // Use the formula directly as SQL expression with alias
                        $selectColumns[] = DB::raw("({$safeFormula}) as `{$displayName}`");
                    } else {
                        Log::warning("Calculated field formula failed validation", [
                            'formula' => $formula,
                            'column' => $column
                        ]);
                    }
                } else {
                    Log::warning("Calculated field missing formula", ['column' => $column]);
                }
            } else {
                // Handle regular table columns
                if ($alias) {
                    // Use DB::raw to create a properly formatted column with alias
                    $selectColumns[] = DB::raw(
                        "$tableName.$columnName as `$alias`"
                    );
                } else {
                    // Use a simple string for the column
                    $selectColumns[] = "$tableName.$columnName";
                }
            }
        }

        // Log the columns for debugging

        // Make sure we have at least one column to select
        if (empty($selectColumns)) {
            // If no columns were specified, select all columns from the main table
            Log::warning(
                'No columns specified, selecting all columns from main table'
            );
            
            // Apply unique field logic if enabled (for all columns case)
            if ($unique['enabled'] && ($unique['distinct'] ?? false) && !empty($unique['field'])) {
                // For MySQL compatibility with ONLY_FULL_GROUP_BY, use a subquery approach
                $uniqueField = $unique['field'];
                $uniqueColumn = str_replace($mainTable . '.', '', $uniqueField);
                
                // Get IDs of records that are the first occurrence of each unique value
                $subquery = DB::table($mainTable)
                    ->select(DB::raw("MIN(id) as first_id"))
                    ->whereNotNull($uniqueColumn)
                    ->groupBy($uniqueColumn);
                
                // Select all columns but only for the first occurrence IDs
                $query->select("$mainTable.*")
                      ->whereIn("$mainTable.id", function($query) use ($mainTable, $uniqueColumn) {
                          $query->select(DB::raw("MIN(id)"))
                                ->from($mainTable)
                                ->whereNotNull($uniqueColumn)
                                ->groupBy($uniqueColumn);
                      });
                
                Log::info('Applied subquery deduplication for unique field (all columns)', [
                    'mainTable' => $mainTable,
                    'uniqueField' => $uniqueField,
                    'sqlQuery' => $query->toSql()
                ]);
            } else {
                $query->select("$mainTable.*");
            }
        } else {
            // Select the specified columns directly on the existing query
            // Apply GROUP BY if unique field is enabled
            Log::info('🔍 [DEBUG] Code Path 2: Specific columns selected', [
                'columnsCount' => count($selectColumns),
                'uniqueEnabled' => $unique['enabled'] ?? false,
                'uniqueField' => $unique['field'] ?? 'N/A',
                'uniqueDistinct' => $unique['distinct'] ?? false
            ]);
            
            if ($unique['enabled'] && ($unique['distinct'] ?? false) && !empty($unique['field'])) {
                // Use a subquery approach to handle ONLY_FULL_GROUP_BY mode  
                $uniqueField = $unique['field'];
                $uniqueColumn = str_replace($mainTable . '.', '', $uniqueField);
                
                // Select specified columns but only for the first occurrence IDs
                $query->select($selectColumns)
                      ->whereIn("$mainTable.id", function($query) use ($mainTable, $uniqueColumn) {
                          $query->select(DB::raw("MIN(id)"))
                                ->from($mainTable)
                                ->whereNotNull($uniqueColumn)
                                ->groupBy($uniqueColumn);
                      });
                
                Log::info('✅ Applied subquery deduplication for unique field (Path 2)', [
                    'uniqueField' => $uniqueField,
                    'distinctField' => $unique['distinctField'] ?? 'N/A',
                    'columnsCount' => count($selectColumns),
                    'sqlQuery' => $query->toSql()
                ]);
            } else {
                $query->select($selectColumns);
                Log::info('❌ No unique field applied (Path 2)', [
                    'reason' => !$unique['enabled'] ? 'disabled' : (empty($unique['field']) ? 'no field' : 'no distinct flag')
                ]);
            }

            // Log the query after selecting columns
        }

        // Process filters
        // Handle filters structure - it could be an array or an object with groups
        $filterArray = [];
        if (isset($filters['groups'])) {
            // This is the new format with groups
            foreach ($filters['groups'] as $group) {
                if (isset($group['filters']) && is_array($group['filters'])) {
                    $filterArray = array_merge($filterArray, $group['filters']);
                }
            }
        } else {
            // This is the old format - a simple array of filters
            $filterArray = $filters;
        }

        // Log the filter structure for debugging

        // Add filters
        foreach ($filterArray as $filter) {
            $tableName = $filter['table'] ?? $mainTable;
            $columnName = $filter['column'] ?? null;
            $operator = $filter['operator'] ?? '=';
            $value = $filter['value'] ?? null;
            $logicalOperator = strtolower($filter['logicalOperator'] ?? 'and');

            if (!$columnName) {
                continue; // Skip invalid filters
            }

            // Apply the filter
            $columnRef = "$tableName.$columnName";

            // Simple where clause
            if (strtolower($operator) === 'like') {
                $query->where($columnRef, 'like', "%$value%");
            } elseif (strtolower($operator) === 'in' && is_array($value)) {
                $query->whereIn($columnRef, $value);
            } elseif (strtolower($operator) === 'not in' && is_array($value)) {
                $query->whereNotIn($columnRef, $value);
            } elseif (
                strtolower($operator) === 'between' &&
                is_array($value) &&
                count($value) === 2
            ) {
                $query->whereBetween($columnRef, $value);
            } elseif (
                strtolower($operator) === 'not between' &&
                is_array($value) &&
                count($value) === 2
            ) {
                $query->whereNotBetween($columnRef, $value);
            } elseif (in_array(strtolower(trim($operator)), [
                'null', 
                'is null', 
                'isnull', 
                '= null',
                'equals null'
            ])) {
                $query->whereNull($columnRef);
            } elseif (in_array(strtolower(trim($operator)), [
                'not null', 
                'is not null', 
                'isnot null',
                'isnotnull',
                '!= null',
                'not equals null',
                '<> null'
            ])) {
                $query->whereNotNull($columnRef);
                Log::info('Applied IS NOT NULL filter', [
                    'column' => $columnRef,
                    'operator' => $operator,
                    'originalOperator' => $operator,
                    'normalizedOperator' => strtolower(trim($operator))
                ]);
            } else {
                $query->where($columnRef, $operator, $value);
            }
        }

        // Add sorting
        foreach ($sorting as $sort) {
            $tableName = $sort['table'] ?? $mainTable;
            $columnName = $sort['column'] ?? null;
            $direction = strtolower($sort['direction'] ?? 'asc');

            if (!$columnName) {
                continue; // Skip invalid sorting
            }

            $query->orderBy("$tableName.$columnName", $direction);
        }

        // Apply row exclusions if specified
        if (!empty($excludedRows) && is_array($excludedRows)) {
            // Assume exclusions are based on the main table's primary key 'id'
            $query->whereNotIn("$mainTable.id", $excludedRows);
            Log::info('Applied row exclusions to query', [
                'excludedRowsCount' => count($excludedRows),
                'excludedRows' => $excludedRows,
                'mainTable' => $mainTable
            ]);
        }

        // Get the total count before applying limit and offset
        // For complex queries with multiple joins, we need to be careful with the count
        // to avoid duplicate counting due to the joins
        $countQuery = clone $query;

        // For queries with multiple joins, we should count distinct on the main table's primary key
        // to avoid duplicate counting
        if (count($joins) > 0) {
            try {
                // Use the same query structure but with a count distinct
                $distinctCountQuery = DB::table($mainTable);

                // Add the same joins as the original query
                foreach ($joins as $join) {
                    $joinType = strtolower($join['joinType'] ?? 'inner');
                    $targetTable = $join['targetTable'] ?? null;
                    $sourceTable = $join['sourceTable'] ?? $mainTable;
                    $sourceColumn = $join['sourceColumn'] ?? null;
                    $targetColumn = $join['targetColumn'] ?? null;

                    if (!$targetTable || !$sourceColumn || !$targetColumn) {
                        continue; // Skip invalid joins
                    }

                    // Normalize join type to lowercase and trim any extra spaces
                    $joinType = strtolower(trim($joinType));

                    // Remove the word "join" if it's included
                    $joinType = str_replace(' join', '', $joinType);

                    // Log the join type for debugging

                    switch ($joinType) {
                        case 'left':
                            $distinctCountQuery->leftJoin(
                                $targetTable,
                                "$sourceTable.$sourceColumn",
                                '=',
                                "$targetTable.$targetColumn"
                            );
                            break;
                        case 'right':
                            $distinctCountQuery->rightJoin(
                                $targetTable,
                                "$sourceTable.$sourceColumn",
                                '=',
                                "$targetTable.$targetColumn"
                            );
                            break;
                        case 'full':
                            // Full outer join is not directly supported in all databases
                            // For MySQL, we can simulate it with a UNION of LEFT and RIGHT joins
                            // But for simplicity, we'll use LEFT JOIN as a fallback
                            $distinctCountQuery->leftJoin(
                                $targetTable,
                                "$sourceTable.$sourceColumn",
                                '=',
                                "$targetTable.$targetColumn"
                            );
                            break;
                        case 'inner':
                        default:
                            $distinctCountQuery->join(
                                $targetTable,
                                "$sourceTable.$sourceColumn",
                                '=',
                                "$targetTable.$targetColumn"
                            );
                            break;
                    }
                }

                // Add the same where clauses as the original query
                // Since we can't directly copy the where clauses, we'll use the same filter logic
                // to add the where clauses to our count query
                $filterArray = [];
                if (isset($filters['groups'])) {
                    // This is the new format with groups
                    foreach ($filters['groups'] as $group) {
                        if (
                            isset($group['filters']) &&
                            is_array($group['filters'])
                        ) {
                            $filterArray = array_merge(
                                $filterArray,
                                $group['filters']
                            );
                        }
                    }
                } else {
                    // This is the old format - a simple array of filters
                    $filterArray = $filters;
                }

                // Add filters to the count query
                foreach ($filterArray as $filter) {
                    $tableName = $filter['table'] ?? $mainTable;
                    $columnName = $filter['column'] ?? null;
                    $operator = $filter['operator'] ?? '=';
                    $value = $filter['value'] ?? null;

                    if (!$columnName) {
                        continue; // Skip invalid filters
                    }

                    // Apply the filter
                    $columnRef = "$tableName.$columnName";

                    // Simple where clause for the count query
                    if (strtolower($operator) === 'like') {
                        $distinctCountQuery->where(
                            $columnRef,
                            'like',
                            "%$value%"
                        );
                    } elseif (strtolower($operator) === 'in' && is_array($value)) {
                        $distinctCountQuery->whereIn($columnRef, $value);
                    } elseif (strtolower($operator) === 'not in' && is_array($value)) {
                        $distinctCountQuery->whereNotIn($columnRef, $value);
                    } elseif (
                        strtolower($operator) === 'between' &&
                        is_array($value) &&
                        count($value) === 2
                    ) {
                        $distinctCountQuery->whereBetween($columnRef, $value);
                    } elseif (
                        strtolower($operator) === 'not between' &&
                        is_array($value) &&
                        count($value) === 2
                    ) {
                        $distinctCountQuery->whereNotBetween($columnRef, $value);
                    } elseif (in_array(strtolower(trim($operator)), [
                        'null', 
                        'is null', 
                        'isnull', 
                        '= null',
                        'equals null'
                    ])) {
                        $distinctCountQuery->whereNull($columnRef);
                    } elseif (in_array(strtolower(trim($operator)), [
                        'not null', 
                        'is not null', 
                        'isnot null',
                        'isnotnull',
                        '!= null',
                        'not equals null',
                        '<> null'
                    ])) {
                        $distinctCountQuery->whereNotNull($columnRef);
                    } else {
                        $distinctCountQuery->where(
                            $columnRef,
                            $operator,
                            $value
                        );
                    }
                }

                // Select count distinct on the main table's primary key
                $distinctCountQuery->select(
                    DB::raw("COUNT(DISTINCT $mainTable.id) as total_count")
                );

                // Execute the count query
                $countResult = $distinctCountQuery->first();
                $total = $countResult->total_count ?? 0;

            } catch (\Exception $e) {
                // If there's an error with the distinct count, try a simpler approach
                Log::warning(
                    'Error with distinct count, trying simpler approach: ' .
                        $e->getMessage()
                );

                try {
                    // Try a simpler approach - just count all rows and accept potential duplicates
                    $total = $countQuery->count();

                } catch (\Exception $e2) {
                    // If even the simple count fails, just return a default value
                    Log::error(
                        'Error with simple count, using default value: ' .
                            $e2->getMessage()
                    );
                    $total = 0;
                }
            }
        } else {
            // Simple count for queries without joins
            $total = $countQuery->count();
        }

        // Apply limit and offset
        $query->limit($limit)->offset($offset);

        // Get the SQL for debugging
        $sql = $query->toSql();
        $bindings = $query->getBindings();

        // Replace bindings in SQL for debugging
        foreach ($bindings as $binding) {
            $value = is_numeric($binding) ? $binding : "'$binding'";
            $sql = preg_replace('/\?/', $value, $sql, 1);
        }

        // Log the final SQL query with all bindings replaced

        // Log the final SQL query for debugging

        // Execute the query
        $results = $query->get();

        // Log the results for debugging

        // If we have results but the columns don't match what we expected, log a warning
        if (count($results) > 0) {
            $actualColumns = array_keys((array) $results[0]);
            $expectedColumnCount = count($selectColumns);
            $actualColumnCount = count($actualColumns);

            if ($actualColumnCount != $expectedColumnCount) {
                Log::warning('Column count mismatch', [
                    'expectedColumnCount' => $expectedColumnCount,
                    'actualColumnCount' => $actualColumnCount,
                    'expectedColumns' => $selectColumns,
                    'actualColumns' => $actualColumns,
                ]);
            }
        }

        return [
            'data' => $results,
            'total' => $total,
            'sql' => $sql,
        ];
    }

    /**
     * Export report data as CSV, Excel or PDF
     *
     * @param Request $request
     * @return \Symfony\Component\HttpFoundation\BinaryFileResponse|\Illuminate\Http\JsonResponse
     */
    public function exportReport(Request $request)
    {
        try {
            // Log the raw request data for debugging

            // Get the query parameter
            $query = $request->input('query');
            if (empty($query) || !is_array($query)) {
                Log::error('Invalid or missing query parameter', [
                    'query' => $query,
                ]);
                return response()->json(
                    [
                        'success' => false,
                        'message' =>
                            'The query parameter is required and must be an array',
                    ],
                    422
                );
            }

            // Get the format parameter
            $format = $request->input('format', 'pdf');

            // Get the report_id parameter
            $reportId = $request->input('report_id');

            // Get parameters if provided
            $parameters = $request->input('parameters', []);
            if (!is_array($parameters)) {
                $parameters = [];
            }

            // Set up report name
            $reportName = 'report';
            if (!empty($reportId)) {
                $report = ReportBuilder::find($reportId);
                if ($report) {
                    $reportName = $report->label;
                }
            }

            // Execute query

            $result = $this->buildAndExecuteQuery(
                $query,
                100000,
                0,
                $parameters
            );

            // Check if we have data to export
            if (empty($result['data']) || count($result['data']) === 0) {
                Log::warning('No data to export', [
                    'report_name' => $reportName,
                ]);
                return response()->json(
                    [
                        'success' => false,
                        'message' =>
                            'No data to export. The query returned no results.',
                    ],
                    404
                );
            }

            // Check PDF row limits and memory requirements
            $rowCount = count($result['data']);
            $pdfMaxRows = config(
                'visns-packages.report_export.pdf_max_rows',
                2000
            );
            $autoSwitchToCsv = config(
                'visns-packages.report_export.auto_switch_to_csv',
                false
            );


            // Handle PDF row limits
            if ($format === 'pdf' && $pdfMaxRows && $rowCount > $pdfMaxRows) {
                if ($autoSwitchToCsv) {
                    $format = 'csv';
                    $filename = str_replace('.pdf', '.csv', $filename);
                } else {
                    Log::warning('Dataset too large for PDF export', [
                        'row_count' => $rowCount,
                        'pdf_max_rows' => $pdfMaxRows,
                    ]);
                    return response()->json(
                        [
                            'success' => false,
                            'message' => "Dataset too large for PDF export. The dataset contains {$rowCount} rows, but PDF export is limited to {$pdfMaxRows} rows to prevent memory issues. Please use Excel or CSV format for large datasets, or reduce your query results.",
                            'error_code' => 'PDF_ROW_LIMIT_EXCEEDED',
                            'details' => [
                                'row_count' => $rowCount,
                                'pdf_max_rows' => $pdfMaxRows,
                                'suggested_formats' => ['xlsx', 'csv'],
                            ],
                        ],
                        413
                    );
                }
            }

            // Format the date for the filename
            $date = date('Ymd');
            $filename = "{$date}_{$reportName}.{$format}";


            // Generate the export file based on format
            return $this->generateExportFile(
                $result['data'],
                $filename,
                $format
            );
        } catch (\Exception $e) {
            Log::error('Error exporting report: ' . $e->getMessage());
            Log::error('Stack trace: ' . $e->getTraceAsString());

            // Check if this is a memory exhaustion error
            $errorMessage = $e->getMessage();
            if (
                strpos($errorMessage, 'memory') !== false ||
                strpos($errorMessage, 'Allowed memory size') !== false
            ) {
                Log::error('Memory exhaustion detected during export', [
                    'memory_limit' => ini_get('memory_limit'),
                    'memory_usage' => memory_get_usage(true),
                    'memory_peak' => memory_get_peak_usage(true),
                ]);

                return response()->json(
                    [
                        'success' => false,
                        'message' =>
                            'Export failed due to memory limitations. The dataset is too large for the requested format. Please try using Excel or CSV format, or reduce the number of rows in your query.',
                        'error_code' => 'MEMORY_EXHAUSTION',
                        'details' => [
                            'suggested_formats' => ['xlsx', 'csv'],
                            'memory_limit' => ini_get('memory_limit'),
                        ],
                    ],
                    507
                );
            }

            return response()->json(
                [
                    'success' => false,
                    'message' => 'Error exporting report',
                    'error' => $e->getMessage(),
                ],
                500
            );
        }
    }

    /**
     * Format JSON data for display in HTML
     *
     * @param array $data
     * @return string
     */
    private function formatJsonForDisplay($data)
    {
        if (empty($data)) {
            return '';
        }

        // Special handling for project details
        if (isset($data['sector']) && isset($data['project_status'])) {
            return $this->formatProjectDetailsForHtml($data);
        }

        $html =
            '<div class="json-data" style="font-family: Arial, sans-serif; font-size: 12px; line-height: 1.4; border-left: 3px solid #e0e0e0; padding-left: 10px;">';

        foreach ($data as $key => $value) {
            // Format the key for better readability
            $displayKey = $this->humanizeFieldName($key);

            if (is_array($value)) {
                // For nested arrays, show a more detailed version
                if (!empty($value)) {
                    $html .=
                        '<div class="json-item" style="margin-bottom: 6px;"><span class="json-key" style="font-weight: bold; color: #333;">' .
                        htmlspecialchars($displayKey) .
                        ':</span> ';

                    if (isset($value[0]) && is_array($value[0])) {
                        // It's a numeric array of objects
                        $html .=
                            '<span style="color: #666;">[' .
                            count($value) .
                            ' items]</span>';
                    } else {
                        // It's an associative array - show nested values
                        $html .=
                            '<div style="margin-left: 15px; margin-top: 3px;">';
                        foreach ($value as $nestedKey => $nestedValue) {
                            $nestedDisplayKey = $this->humanizeFieldName(
                                $nestedKey
                            );

                            $html .=
                                '<div style="margin-bottom: 3px;"><span style="font-weight: bold; color: #555;">' .
                                htmlspecialchars($nestedDisplayKey) .
                                ':</span> ';

                            if (is_array($nestedValue)) {
                                $html .=
                                    '<span style="color: #666;">[...]</span>';
                            } else {
                                $formattedValue = $this->formatValueForDisplay(
                                    $nestedValue,
                                    $nestedKey
                                );
                                $html .=
                                    '<span style="color: #0066cc;">' .
                                    htmlspecialchars($formattedValue) .
                                    '</span>';
                            }

                            $html .= '</div>';
                        }
                        $html .= '</div>';
                    }

                    $html .= '</div>';
                }
            } else {
                $formattedValue = $this->formatValueForDisplay($value, $key);
                $html .=
                    '<div class="json-item" style="margin-bottom: 4px;"><span class="json-key" style="font-weight: bold; color: #333;">' .
                    htmlspecialchars($displayKey) .
                    ':</span> <span class="json-value" style="color: #0066cc;">' .
                    htmlspecialchars($formattedValue) .
                    '</span></div>';
            }
        }

        $html .= '</div>';
        return $html;
    }

    /**
     * Format project details JSON specifically for HTML display
     *
     * @param array $data
     * @return string
     */
    private function formatProjectDetailsForHtml($data)
    {
        $html =
            '<div class="json-data project-details" style="font-family: Arial, sans-serif; font-size: 12px; line-height: 1.5; border-left: 3px solid #4a86e8; padding-left: 10px; background-color: #f8f9fa;">';

        // Handle common project detail fields in a specific order
        $orderedFields = [
            'sector' => 'Sector',
            'project_status' => 'Project Status',
            'active_projects' => 'Active Projects',
            'agreement_end_date' => 'Agreement End Date',
            'agreement_start_date' => 'Agreement Start Date',
        ];

        foreach ($orderedFields as $field => $label) {
            if (isset($data[$field])) {
                $value = $this->formatValueForDisplay($data[$field], $field);
                $html .=
                    '<div class="json-item" style="margin-bottom: 5px;"><span class="json-key" style="font-weight: bold; color: #333;">' .
                    htmlspecialchars($label) .
                    ':</span> <span class="json-value" style="color: #0066cc;">' .
                    htmlspecialchars($value) .
                    '</span></div>';
            }
        }

        // Add any remaining fields not in the ordered list
        foreach ($data as $key => $value) {
            if (!isset($orderedFields[$key])) {
                $displayKey = $this->humanizeFieldName($key);

                if (is_array($value)) {
                    // Handle nested arrays
                    $html .=
                        '<div class="json-item" style="margin-bottom: 5px;"><span class="json-key" style="font-weight: bold; color: #333;">' .
                        htmlspecialchars($displayKey) .
                        ':</span> ';

                    if (!empty($value)) {
                        if (isset($value[0]) && is_array($value[0])) {
                            $html .=
                                '<span style="color: #666;">[' .
                                count($value) .
                                ' items]</span>';
                        } else {
                            $nestedValues = [];
                            foreach ($value as $nestedKey => $nestedValue) {
                                $nestedDisplayKey = $this->humanizeFieldName(
                                    $nestedKey
                                );
                                $formattedValue = is_array($nestedValue)
                                    ? '[...]'
                                    : $this->formatValueForDisplay(
                                        $nestedValue,
                                        $nestedKey
                                    );
                                $nestedValues[] =
                                    $nestedDisplayKey . ': ' . $formattedValue;
                            }
                            $html .=
                                '<span style="color: #666;">{' .
                                implode(', ', $nestedValues) .
                                '}</span>';
                        }
                    }

                    $html .= '</div>';
                } else {
                    $formattedValue = $this->formatValueForDisplay(
                        $value,
                        $key
                    );
                    $html .=
                        '<div class="json-item" style="margin-bottom: 5px;"><span class="json-key" style="font-weight: bold; color: #333;">' .
                        htmlspecialchars($displayKey) .
                        ':</span> <span class="json-value" style="color: #0066cc;">' .
                        htmlspecialchars($formattedValue) .
                        '</span></div>';
                }
            }
        }

        $html .= '</div>';
        return $html;
    }

    /**
     * Format JSON data for Excel export
     *
     * @param array $data
     * @return string
     */
    private function formatJsonForExcel($data)
    {
        if (empty($data)) {
            return '';
        }

        // Special handling for project details
        if (isset($data['sector']) && isset($data['project_status'])) {
            return $this->formatProjectDetailsForExcel($data);
        }

        $result = [];

        // Format each key-value pair
        foreach ($data as $key => $value) {
            // Format the key for better readability
            $displayKey = $this->humanizeFieldName($key);

            if (is_array($value)) {
                if (!empty($value)) {
                    if (isset($value[0]) && is_array($value[0])) {
                        // It's a numeric array of objects
                        $result[] =
                            "$displayKey: [" . count($value) . ' items]';
                    } else {
                        // It's an associative array - format nested values
                        $nestedValues = [];
                        foreach ($value as $nestedKey => $nestedValue) {
                            $nestedDisplayKey = $this->humanizeFieldName(
                                $nestedKey
                            );
                            if (is_array($nestedValue)) {
                                $nestedValues[] = "$nestedDisplayKey: [...]";
                            } else {
                                $formattedValue = $this->formatValueForDisplay(
                                    $nestedValue,
                                    $nestedKey
                                );
                                $nestedValues[] = "$nestedDisplayKey: $formattedValue";
                            }
                        }
                        $result[] =
                            "$displayKey: {" .
                            implode(', ', $nestedValues) .
                            '}';
                    }
                }
            } else {
                $formattedValue = $this->formatValueForDisplay($value, $key);
                $result[] = "$displayKey: $formattedValue";
            }
        }

        return implode("\n", $result);
    }

    /**
     * Format project details JSON specifically for Excel
     *
     * @param array $data
     * @return string
     */
    private function formatProjectDetailsForExcel($data)
    {
        $result = [];

        // Handle common project detail fields in a specific order
        $orderedFields = [
            'sector' => 'Sector',
            'project_status' => 'Project Status',
            'active_projects' => 'Active Projects',
            'agreement_end_date' => 'Agreement End Date',
            'agreement_start_date' => 'Agreement Start Date',
        ];

        foreach ($orderedFields as $field => $label) {
            if (isset($data[$field])) {
                $value = $this->formatValueForDisplay($data[$field], $field);
                $result[] = "$label: $value";
            }
        }

        // Add any remaining fields not in the ordered list
        foreach ($data as $key => $value) {
            if (!isset($orderedFields[$key])) {
                $displayKey = $this->humanizeFieldName($key);
                $formattedValue = $this->formatValueForDisplay($value, $key);
                $result[] = "$displayKey: $formattedValue";
            }
        }

        return implode("\n", $result);
    }

    /**
     * Format a value for display based on its type and field name
     *
     * @param mixed $value
     * @param string $fieldName
     * @return string
     */
    private function formatValueForDisplay($value, $fieldName)
    {
        if (is_null($value)) {
            return '';
        }

        if (is_bool($value)) {
            return $value ? 'Yes' : 'No';
        }

        // Format dates
        if (strpos($fieldName, '_date') !== false && is_string($value)) {
            // Try to parse and format the date
            try {
                $date = new \DateTime($value);
                return $date->format('d/m/Y');
            } catch (\Exception $e) {
                // If it's not a valid date, return as is
                return $value;
            }
        }

        return (string) $value;
    }

    /**
     * Convert a field name to a more human-readable format
     *
     * @param string $fieldName
     * @return string
     */
    private function humanizeFieldName($fieldName)
    {
        // Replace underscores with spaces
        $humanized = str_replace('_', ' ', $fieldName);

        // Capitalize each word
        $humanized = ucwords($humanized);

        return $humanized;
    }

    /**
     * Get available keys from a JSON field in a table
     *
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function getJsonFieldKeys(Request $request)
    {
        try {
            // Validate request
            $validated = $request->validate([
                'table' => 'required|string',
                'column' => 'required|string',
                'limit' => 'nullable|integer|min:1|max:1000',
            ]);

            $tableName = $validated['table'];
            $columnName = $validated['column'];
            $limit = $validated['limit'] ?? 100;

            // Check if table exists
            if (!Schema::hasTable($tableName)) {
                return response()->json(
                    [
                        'success' => false,
                        'message' => "Table '{$tableName}' does not exist",
                    ],
                    404
                );
            }

            // Check if column exists
            if (!Schema::hasColumn($tableName, $columnName)) {
                return response()->json(
                    [
                        'success' => false,
                        'message' => "Column '{$columnName}' does not exist in table '{$tableName}'",
                    ],
                    404
                );
            }

            // Get column type
            $columnType = $this->getColumnType($tableName, $columnName);

            // Check if column is a JSON type
            if (!in_array(strtolower($columnType), ['json', 'jsonb'])) {
                return response()->json(
                    [
                        'success' => false,
                        'message' => "Column '{$columnName}' is not a JSON type column",
                    ],
                    400
                );
            }

            // Query the table to get sample JSON data
            $query = DB::table($tableName)
                ->select($columnName)
                ->whereNotNull($columnName)
                ->where($columnName, '<>', '{}')
                ->where($columnName, '<>', '[]')
                ->limit($limit);

            $results = $query->get();

            // Extract all unique keys from the JSON data
            $allKeys = [];
            $keyFrequency = [];
            $keyExamples = [];
            $totalRecords = count($results);

            foreach ($results as $row) {
                $jsonData = json_decode($row->{$columnName}, true);

                if (is_array($jsonData)) {
                    // Extract keys recursively
                    $this->extractJsonKeys(
                        $jsonData,
                        '',
                        $allKeys,
                        $keyFrequency,
                        $keyExamples
                    );
                }
            }

            // Sort keys by frequency (most common first)
            arsort($keyFrequency);

            // Format the response
            $formattedKeys = [];
            foreach ($keyFrequency as $key => $frequency) {
                $formattedKeys[] = [
                    'key' => $key,
                    'frequency' => $frequency,
                    'percentage' =>
                        $totalRecords > 0
                            ? round(($frequency / $totalRecords) * 100, 2)
                            : 0,
                    'example' => $keyExamples[$key] ?? null,
                ];
            }

            return response()->json([
                'success' => true,
                'data' => [
                    'table' => $tableName,
                    'column' => $columnName,
                    'total_records_analyzed' => $totalRecords,
                    'keys' => $formattedKeys,
                ],
            ]);
        } catch (\Exception $e) {
            Log::error('Error extracting JSON field keys: ' . $e->getMessage());
            Log::error('Stack trace: ' . $e->getTraceAsString());
            return response()->json(
                [
                    'success' => false,
                    'message' => 'Error extracting JSON field keys',
                    'error' => $e->getMessage(),
                ],
                500
            );
        }
    }

    /**
     * Recursively extract keys from a JSON structure
     *
     * @param array $data The JSON data as an array
     * @param string $prefix Current key prefix for nested objects
     * @param array &$allKeys Reference to array of all keys found
     * @param array &$keyFrequency Reference to array tracking key frequency
     * @param array &$keyExamples Reference to array storing example values for each key
     * @return void
     */
    private function extractJsonKeys(
        $data,
        $prefix,
        &$allKeys,
        &$keyFrequency,
        &$keyExamples
    ) {
        if (!is_array($data)) {
            return;
        }

        // Handle both objects and arrays
        if ($this->isAssociativeArray($data)) {
            // Object-like structure
            foreach ($data as $key => $value) {
                $fullKey = $prefix ? "{$prefix}.{$key}" : $key;

                // Add this key to our collection
                if (!in_array($fullKey, $allKeys)) {
                    $allKeys[] = $fullKey;
                }

                // Increment frequency counter
                if (!isset($keyFrequency[$fullKey])) {
                    $keyFrequency[$fullKey] = 1;

                    // Store an example value if we don't have one yet
                    if (!isset($keyExamples[$fullKey]) && !is_array($value)) {
                        $keyExamples[$fullKey] = $this->formatExampleValue(
                            $value
                        );
                    }
                } else {
                    $keyFrequency[$fullKey]++;
                }

                // Recursively process nested objects/arrays
                if (is_array($value)) {
                    $this->extractJsonKeys(
                        $value,
                        $fullKey,
                        $allKeys,
                        $keyFrequency,
                        $keyExamples
                    );
                }
            }
        } else {
            // Array structure - check the first few items to identify structure
            $sampleSize = min(count($data), 5);
            for ($i = 0; $i < $sampleSize; $i++) {
                if (isset($data[$i]) && is_array($data[$i])) {
                    $this->extractJsonKeys(
                        $data[$i],
                        $prefix ? "{$prefix}[*]" : '[*]',
                        $allKeys,
                        $keyFrequency,
                        $keyExamples
                    );
                }
            }
        }
    }

    /**
     * Check if an array is associative (object-like) or sequential (array-like)
     *
     * @param array $array
     * @return bool
     */
    private function isAssociativeArray($array)
    {
        if (!is_array($array)) {
            return false;
        }

        // If array is empty, consider it associative
        if (empty($array)) {
            return true;
        }

        // Check if array keys are sequential integers starting from 0
        return array_keys($array) !== range(0, count($array) - 1);
    }

    /**
     * Format an example value for display
     *
     * @param mixed $value
     * @return string
     */
    private function formatExampleValue($value)
    {
        if (is_null($value)) {
            return 'null';
        }

        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }

        if (is_string($value)) {
            // Truncate long strings
            if (strlen($value) > 50) {
                return substr($value, 0, 47) . '...';
            }
            return $value;
        }

        if (is_numeric($value)) {
            return (string) $value;
        }

        // For other types, convert to string
        return (string) $value;
    }

    /**
     * Check if a string is valid JSON
     *
     * @param string $string
     * @return bool
     */
    private function isJsonString($string)
    {
        if (!is_string($string)) {
            return false;
        }

        $string = trim($string);

        // Quick check for JSON-like structure
        if (
            (substr($string, 0, 1) !== '{' || substr($string, -1) !== '}') &&
            (substr($string, 0, 1) !== '[' || substr($string, -1) !== ']')
        ) {
            return false;
        }

        // Try to decode the string
        json_decode($string);
        return json_last_error() === JSON_ERROR_NONE;
    }

    /**
     * Generate export file in the specified format
     *
     * @param \Illuminate\Support\Collection $data
     * @param string $filename
     * @param string $format
     * @return \Symfony\Component\HttpFoundation\BinaryFileResponse|\Illuminate\Http\Response
     * @throws \InvalidArgumentException If the format is invalid
     * @throws \Exception If there's an error during file generation
     */
    private function generateExportFile($data, $filename, $format)
    {
        // Log the input parameters

        // Normalize format
        if (is_string($format)) {
            $format = strtolower(trim($format));
        } else {
            $format = 'pdf'; // Default to PDF
        }

        // Map formats
        $formatMap = [
            'pdf' => 'pdf',
            'xlsx' => 'xlsx',
            'excel' => 'xlsx',
            'csv' => 'csv',
        ];

        if (isset($formatMap[$format])) {
            $format = $formatMap[$format];
        } else {
            $format = 'pdf'; // Default to PDF for unknown formats
        }


        // Convert data collection to array
        $dataArray = json_decode(json_encode($data), true);

        // Check if data was properly converted
        if (!is_array($dataArray)) {
            Log::error('Failed to convert data to array', [
                'original_type' => gettype($data),
                'converted_type' => gettype($dataArray),
            ]);
            return response()->json(
                [
                    'success' => false,
                    'message' => 'Failed to convert data to array for export',
                ],
                500
            );
        }

        // Get column headers from the first row
        $headers = [];
        if (!empty($dataArray)) {
            $headers = array_keys($dataArray[0]);
        } else {
            Log::warning('No data to export, empty array provided');
        }

        // Create a temporary file
        $tempFile = tempnam(sys_get_temp_dir(), 'report_');

        if ($format === 'csv') {
            // Generate CSV file
            $handle = fopen($tempFile, 'w');

            // Add headers
            fputcsv($handle, $headers);

            // Add data rows
            foreach ($dataArray as $row) {
                fputcsv($handle, $row);
            }

            fclose($handle);

            // Return the file as a download
            return response()
                ->download($tempFile, $filename, [
                    'Content-Type' => 'text/csv',
                ])
                ->deleteFileAfterSend(true);
        } elseif ($format === 'xlsx') {
            // Generate Excel file using PhpSpreadsheet
            $spreadsheet = new Spreadsheet();
            $sheet = $spreadsheet->getActiveSheet();

            // Add headers (first row)
            $column = 1;
            foreach ($headers as $header) {
                $sheet->setCellValueByColumnAndRow(
                    $column++,
                    1,
                    $this->formatColumnName($header)
                );
            }

            // Add data rows
            $row = 2;
            foreach ($dataArray as $dataRow) {
                $column = 1;
                foreach ($dataRow as $value) {
                    // Handle null values
                    if (is_null($value)) {
                        $value = '';
                    }

                    // Format JSON values for better readability
                    if (is_array($value)) {
                        // For Excel, we need to simplify the JSON structure
                        $value = $this->formatJsonForExcel($value);
                    } elseif (
                        is_string($value) &&
                        $this->isJsonString($value)
                    ) {
                        // If it's a JSON string, decode and format it
                        $value = $this->formatJsonForExcel(
                            json_decode($value, true)
                        );
                    }

                    $sheet->setCellValueByColumnAndRow($column++, $row, $value);
                }
                $row++;
            }

            // Create Excel writer
            $writer = new Xlsx($spreadsheet);
            $writer->save($tempFile);

            // Return the file as a download
            return response()
                ->download($tempFile, $filename, [
                    'Content-Type' =>
                        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                ])
                ->deleteFileAfterSend(true);
        } else {
            // Set memory limit for PDF generation
            $originalMemoryLimit = ini_get('memory_limit');
            $pdfMemoryLimit = config('visns-packages.report_export.pdf_memory_limit', '1G');
            ini_set('memory_limit', $pdfMemoryLimit);


            // Determine which PDF engine to use
            $pdfEngine = config('visns-packages.report_export.pdf_engine', 'dompdf');
            $tcpdfThreshold = config('visns-packages.report_export.tcpdf_threshold', 1000);
            $rowCount = count($dataArray);

            // Auto-switch to TCPDF for large datasets
            if ($pdfEngine === 'dompdf' && $rowCount > $tcpdfThreshold) {
                $pdfEngine = 'tcpdf';
            }

            // Check if dataset is too large even for optimized engines
            $chunkSize = config('visns-packages.report_export.pdf_chunk_size', 500);
            $maxChunks = config('visns-packages.report_export.pdf_max_chunks', 10);
            $maxRowsForSinglePDF = $chunkSize * $maxChunks;

            if ($rowCount > $maxRowsForSinglePDF) {
                Log::warning('Dataset too large for single PDF', [
                    'row_count' => $rowCount,
                    'max_rows_single_pdf' => $maxRowsForSinglePDF,
                ]);
                
                // Reset memory limit
                ini_set('memory_limit', $originalMemoryLimit);
                
                return response()->json([
                    'success' => false,
                    'message' => "Dataset is too large for PDF generation. The dataset contains {$rowCount} rows, but the maximum supported for PDF is {$maxRowsForSinglePDF} rows. Please use Excel or CSV format, or reduce your query results.",
                    'error_code' => 'PDF_DATASET_TOO_LARGE',
                    'details' => [
                        'row_count' => $rowCount,
                        'max_rows_single_pdf' => $maxRowsForSinglePDF,
                        'suggested_formats' => ['xlsx', 'csv'],
                    ],
                ], 413);
            }


            try {
                // Generate PDF using selected engine
                if ($pdfEngine === 'tcpdf') {
                    return $this->generatePdfWithTcpdf($dataArray, $headers, $filename, $originalMemoryLimit);
                } elseif ($pdfEngine === 'chunked') {
                    return $this->generateChunkedPdf($dataArray, $headers, $filename, $originalMemoryLimit);
                } else {
                    return $this->generatePdfWithDompdf($dataArray, $headers, $filename, $originalMemoryLimit);
                }
            } catch (\Exception $pdfException) {
                // Reset memory limit on error
                ini_set('memory_limit', $originalMemoryLimit);
                
                Log::error('PDF generation failed', [
                    'engine' => $pdfEngine,
                    'message' => $pdfException->getMessage(),
                    'trace' => $pdfException->getTraceAsString(),
                    'memory_usage' => memory_get_usage(true),
                    'memory_peak' => memory_get_peak_usage(true),
                    'row_count' => count($dataArray),
                ]);
                
                // Check if this is a memory-related error and suggest alternatives
                if (strpos($pdfException->getMessage(), 'memory') !== false || 
                    strpos($pdfException->getMessage(), 'Allowed memory size') !== false) {
                    throw new \Exception(
                        'PDF generation failed due to memory limitations. The dataset contains ' . 
                        count($dataArray) . ' rows which is too large for PDF export. ' .
                        'Please try using Excel or CSV format instead.',
                        0,
                        $pdfException
                    );
                }
                
                throw new \Exception('PDF generation failed: ' . $pdfException->getMessage(), 0, $pdfException);
            }
        }
    }

    /**
     * Generate PDF using DomPDF (original method, optimized)
     */
    private function generatePdfWithDompdf($dataArray, $headers, $filename, $originalMemoryLimit)
    {
        // Check if we should use simplified styling
        $simplifiedStylingThreshold = config('visns-packages.report_export.simplified_styling_threshold', 1000);
        $useSimplifiedStyling = count($dataArray) > $simplifiedStylingThreshold;


            // Create HTML content with conditional styling
            if ($useSimplifiedStyling) {
                // Simplified styling for large datasets
                $html =
                    '<!DOCTYPE html>
                <html>
                <head>
                    <meta charset="UTF-8">
                    <title>' .
                    htmlspecialchars(str_replace('.pdf', '', $filename)) .
                    '</title>
                    <style>
                        body { font-family: Arial, sans-serif; margin: 10px; font-size: 8pt; }
                        h1 { text-align: center; margin-bottom: 10px; font-size: 12pt; }
                        table { width: 100%; border-collapse: collapse; }
                        th, td { border: 1px solid #000; padding: 2px; font-size: 7pt; }
                        th { background-color: #ccc; font-weight: bold; }
                    </style>
                </head>
                <body>
                    <h1>' .
                    htmlspecialchars(str_replace('.pdf', '', $filename)) .
                    '</h1>
                    <table>
                        <thead>
                            <tr>';
            } else {
                // Enhanced styling for smaller datasets
                $html =
                    '<!DOCTYPE html>
                <html>
                <head>
                    <meta charset="UTF-8">
                    <title>' .
                    htmlspecialchars(str_replace('.pdf', '', $filename)) .
                    '</title>
                    <style>
                        body { font-family: Arial, sans-serif; margin: 20px; }
                        h1 { text-align: center; margin-bottom: 20px; }
                        table { width: 100%; border-collapse: collapse; margin-bottom: 20px; }
                        th { background-color: #f2f2f2; font-weight: bold; text-align: left; }
                        th, td { border: 1px solid #ddd; padding: 8px; }
                        tr:nth-child(even) { background-color: #f9f9f9; }

                        /* JSON Styling */
                        .json-data { font-family: Arial, sans-serif; font-size: 12px; line-height: 1.4; border-left: 3px solid #e0e0e0; padding-left: 10px; margin-bottom: 8px; }
                        .json-data.project-details { border-left: 3px solid #4a86e8; background-color: #f8f9fa; padding: 8px 10px; }
                        .json-item { margin-bottom: 5px; }
                        .json-key { font-weight: bold; color: #333; }
                        .json-value { color: #0066cc; }

                        /* Print styles */
                        @media print {
                            body { font-size: 10pt; }
                            h1 { font-size: 14pt; }
                            table { page-break-inside: auto; }
                            tr { page-break-inside: avoid; page-break-after: auto; }
                            td { word-break: break-word; }
                        }
                    </style>
                </head>
                <body>
                    <h1>' .
                    htmlspecialchars(str_replace('.pdf', '', $filename)) .
                    '</h1>
                    <table>
                        <thead>
                            <tr>';
            }

            // Add table headers
            foreach ($headers as $header) {
                $html .=
                    '<th>' .
                    htmlspecialchars($this->formatColumnName($header)) .
                    '</th>';
            }

            $html .= '</tr>
                    </thead>
                    <tbody>';

            // Add table rows with memory-efficient processing
            $processedRows = 0;
            foreach ($dataArray as $dataRow) {
                $html .= '<tr>';
                foreach ($dataRow as $key => $value) {
                    // Handle null values
                    if (is_null($value)) {
                        $value = '';
                    }

                    // For large datasets, simplify JSON processing to save memory
                    if ($useSimplifiedStyling) {
                        // Simplified value processing for large datasets
                        if (is_array($value)) {
                            $value = json_encode($value);
                        } elseif (
                            is_string($value) &&
                            $this->isJsonString($value)
                        ) {
                            // Keep JSON as-is for simplified processing
                            $value = htmlspecialchars($value);
                        } else {
                            $value = htmlspecialchars($value);
                        }
                    } else {
                        // Enhanced formatting for smaller datasets
                        if (is_array($value)) {
                            $value = $this->formatJsonForDisplay($value);
                        } elseif (
                            is_string($value) &&
                            $this->isJsonString($value)
                        ) {
                            $value = $this->formatJsonForDisplay(
                                json_decode($value, true)
                            );
                        } else {
                            $value = htmlspecialchars($value);
                        }
                    }

                    $html .= '<td>' . $value . '</td>';
                }
                $html .= '</tr>';

                $processedRows++;

                // Log progress for large datasets
                if ($processedRows % 500 === 0) {
                }
            }

            $html .= '</tbody>
                </table>
            </body>
            </html>';


            try {
                // Set options based on dataset size
                if ($useSimplifiedStyling) {
                    // Simplified options for large datasets
                    $options = [
                        'isHtml5ParserEnabled' => false,
                        'isRemoteEnabled' => false,
                        'defaultFont' => 'sans-serif',
                        'dpi' => 96,
                    ];
                } else {
                    // Enhanced options for smaller datasets
                    $options = [
                        'isHtml5ParserEnabled' => true,
                        'isRemoteEnabled' => true,
                        'defaultFont' => 'sans-serif',
                        'dpi' => 150,
                    ];
                }


                // Generate PDF using Dompdf with memory-optimized options
                $pdf = Pdf::loadHTML($html)
                    ->setOptions($options)
                    ->setPaper('a4', 'landscape');


                // Reset memory limit to original value
                ini_set('memory_limit', $originalMemoryLimit);

                // Return the PDF as a download
                return $pdf->download($filename);
            } catch (\Exception $pdfException) {
                // Reset memory limit on error
                ini_set('memory_limit', $originalMemoryLimit);
                
                Log::error('DomPDF generation failed', [
                    'message' => $pdfException->getMessage(),
                    'trace' => $pdfException->getTraceAsString(),
                    'html_length' => strlen($html),
                    'memory_usage' => memory_get_usage(true),
                    'memory_peak' => memory_get_peak_usage(true),
                    'row_count' => count($dataArray),
                ]);
                
                throw $pdfException;
            }
    }


    /**
     * Generate PDF using TCPDF for better memory handling with large datasets
     */
    private function generatePdfWithTcpdf($dataArray, $headers, $filename, $originalMemoryLimit)
    {
        try {
            // Include TCPDF
            require_once(base_path('vendor/tecnickcom/tcpdf/tcpdf.php'));
            

            // Create new PDF document
            $pdf = new \TCPDF('L', 'mm', 'A4', true, 'UTF-8', false);

            // Set document information
            $pdf->SetCreator('Visns Packages Report Builder');
            $pdf->SetTitle(str_replace('.pdf', '', $filename));
            $pdf->SetSubject('Report Export');

            // Remove default header/footer
            $pdf->setPrintHeader(false);
            $pdf->setPrintFooter(false);

            // Set margins
            $pdf->SetMargins(10, 10, 10);
            $pdf->SetAutoPageBreak(true, 10);

            // Set font
            $pdf->SetFont('helvetica', '', 8);

            // Add a page
            $pdf->AddPage();

            // Title
            $pdf->SetFont('helvetica', 'B', 12);
            $pdf->Cell(0, 10, str_replace('.pdf', '', $filename), 0, 1, 'C');
            $pdf->Ln(5);

            // Table headers with smart column width calculation
            $pdf->SetFont('helvetica', 'B', 7);
            $pdf->SetFillColor(240, 240, 240);
            
            // Calculate optimal column widths based on content
            $columnWidths = $this->calculateOptimalColumnWidths($headers, $dataArray);
            
            $columnIndex = 0;
            foreach ($headers as $header) {
                $width = $columnWidths[$columnIndex];
                $pdf->Cell($width, 8, $this->formatColumnName($header), 1, 0, 'L', true);
                $columnIndex++;
            }
            $pdf->Ln();

            // Table data with enhanced text wrapping
            $pdf->SetFont('helvetica', '', 6);
            $pdf->SetFillColor(255, 255, 255);
            
            // Get formatting configuration
            $enableTextWrapping = config('visns-packages.report_export.pdf_formatting.enable_text_wrapping', true);
            $maxCellHeight = config('visns-packages.report_export.pdf_formatting.max_cell_height', 50);
            $lineHeightMultiplier = config('visns-packages.report_export.pdf_formatting.line_height_multiplier', 1.2);
            
            $processedRows = 0;
            foreach ($dataArray as $dataRow) {
                // Check if we need a new page
                if ($pdf->GetY() > 180) { // Near bottom of page
                    $pdf->AddPage();
                    
                    // Re-add headers on new page
                    $pdf->SetFont('helvetica', 'B', 7);
                    $pdf->SetFillColor(240, 240, 240);
                    $columnIndex = 0;
                    foreach ($headers as $header) {
                        $width = $columnWidths[$columnIndex];
                        $pdf->Cell($width, 8, $this->formatColumnName($header), 1, 0, 'L', true);
                        $columnIndex++;
                    }
                    $pdf->Ln();
                    $pdf->SetFont('helvetica', '', 6);
                    $pdf->SetFillColor(255, 255, 255);
                }

                if ($enableTextWrapping) {
                    // Enhanced text wrapping with MultiCell
                    $this->generatePdfRowWithWrapping($pdf, $dataRow, $headers, $columnWidths, $maxCellHeight, $lineHeightMultiplier);
                } else {
                    // Fallback to original Cell method
                    $this->generatePdfRowSimple($pdf, $dataRow, $columnWidths);
                }
                
                $processedRows++;
                
                // Log progress and manage memory
                if ($processedRows % 200 === 0) {
                    
                    // Force garbage collection
                    if (function_exists('gc_collect_cycles')) {
                        gc_collect_cycles();
                    }
                }
            }


            // Reset memory limit
            ini_set('memory_limit', $originalMemoryLimit);

            // Output PDF
            $pdfContent = $pdf->Output('', 'S'); // Get as string
            
            return response($pdfContent)
                ->header('Content-Type', 'application/pdf')
                ->header('Content-Disposition', 'attachment; filename="' . $filename . '"');

        } catch (\Exception $e) {
            // Reset memory limit on error
            ini_set('memory_limit', $originalMemoryLimit);
            
            Log::error('TCPDF generation failed', [
                'message' => $e->getMessage(),
                'memory_usage' => memory_get_usage(true),
            ]);
            
            throw $e;
        }
    }

    /**
     * Generate chunked PDF for very large datasets
     */
    private function generateChunkedPdf($dataArray, $headers, $filename, $originalMemoryLimit)
    {
        $chunkSize = config('visns-packages.report_export.pdf_chunk_size', 500);
        $chunks = array_chunk($dataArray, $chunkSize);
        $maxChunks = config('visns-packages.report_export.pdf_max_chunks', 10);
        
        if (count($chunks) > $maxChunks) {
            $chunks = array_slice($chunks, 0, $maxChunks);
            Log::warning('Limiting chunks to maximum allowed', [
                'total_chunks' => count(array_chunk($dataArray, $chunkSize)),
                'max_chunks' => $maxChunks,
                'using_chunks' => count($chunks),
            ]);
        }


        try {
            // For now, generate a single PDF with chunked processing
            // Future enhancement: could generate multiple PDF files in a ZIP
            return $this->generatePdfWithTcpdf($dataArray, $headers, $filename, $originalMemoryLimit);
            
        } catch (\Exception $e) {
            ini_set('memory_limit', $originalMemoryLimit);
            throw $e;
        }
    }

    /**
     * Generate PDF row with text wrapping using MultiCell
     */
    private function generatePdfRowWithWrapping($pdf, $dataRow, $headers, $columnWidths, $maxCellHeight, $lineHeightMultiplier)
    {
        // Calculate the required height for this row with variable column widths
        $rowHeight = $this->calculateRowHeightWithWidths($pdf, $dataRow, $columnWidths, $maxCellHeight);
        
        // Store current position
        $startY = $pdf->GetY();
        $startX = $pdf->GetX();
        
        $columnIndex = 0;
        $currentX = $startX;
        
        foreach ($dataRow as $key => $value) {
            // Handle null values
            if (is_null($value)) {
                $value = '';
            }

            // Format value for PDF display
            $formattedValue = $this->formatValueForPdf($value);
            
            // Get width for this specific column
            $columnWidth = $columnWidths[$columnIndex];
            
            // Set position for this cell
            $pdf->SetXY($currentX, $startY);
            
            // Use MultiCell for text wrapping
            $pdf->MultiCell(
                $columnWidth,           // width (specific to this column)
                $rowHeight,            // height
                $formattedValue,       // text
                1,                     // border (1 = border around)
                'L',                   // align (L = left)
                false,                 // fill (false = no background fill)
                0,                     // ln (0 = to the right)
                '',                    // x (empty = current)
                '',                    // y (empty = current)  
                true,                  // reseth (true = reset height)
                0,                     // stretch (0 = disabled)
                false,                 // ishtml (false = not HTML)
                true,                  // autopadding (true = auto padding)
                $rowHeight,            // maxh (max height)
                'T',                   // valign (T = top)
                false                  // fitcell (false = no fit)
            );
            
            // Move to next column position
            $currentX += $columnWidth;
            $columnIndex++;
        }
        
        // Move to next row
        $pdf->SetXY($startX, $startY + $rowHeight);
    }

    /**
     * Generate PDF row using simple Cell method (fallback)
     */
    private function generatePdfRowSimple($pdf, $dataRow, $columnWidths)
    {
        $columnIndex = 0;
        foreach ($dataRow as $key => $value) {
            // Handle null values
            if (is_null($value)) {
                $value = '';
            }

            // Basic formatting
            $formattedValue = $this->formatValueForPdf($value);
            
            // Get width for this specific column
            $columnWidth = $columnWidths[$columnIndex];
            
            // Truncate long values for simple mode based on column width
            $maxChars = floor($columnWidth * 2.5); // Rough estimate for 6pt font
            if (strlen($formattedValue) > $maxChars) {
                $formattedValue = substr($formattedValue, 0, $maxChars - 3) . '...';
            }

            $pdf->Cell($columnWidth, 6, $formattedValue, 1, 0, 'L');
            $columnIndex++;
        }
        $pdf->Ln();
    }

    /**
     * Calculate the required height for a row based on content
     */
    private function calculateRowHeight($pdf, $dataRow, $columnWidth, $maxCellHeight)
    {
        $maxLines = 1;
        $fontSize = 6; // Current font size
        $lineHeight = $fontSize * 1.2; // Line height in points
        
        foreach ($dataRow as $value) {
            if (is_null($value)) {
                continue;
            }
            
            $formattedValue = $this->formatValueForPdf($value);
            
            // Estimate number of lines needed for this cell
            $textLength = strlen($formattedValue);
            $charsPerLine = floor($columnWidth * 2.8); // Rough estimate: 2.8 chars per mm for 6pt font
            $estimatedLines = max(1, ceil($textLength / $charsPerLine));
            
            // Account for explicit line breaks
            $explicitLines = substr_count($formattedValue, "\n") + 1;
            $lines = max($estimatedLines, $explicitLines);
            
            $maxLines = max($maxLines, $lines);
        }
        
        // Convert to millimeters and apply limits
        $heightMM = ($maxLines * $lineHeight * 0.352778); // Convert points to mm
        return min($heightMM, $maxCellHeight);
    }

    /**
     * Format value for PDF display with enhanced JSON handling
     */
    private function formatValueForPdf($value)
    {
        // Handle null values
        if (is_null($value)) {
            return '';
        }
        
        // Handle arrays
        if (is_array($value)) {
            return $this->formatJsonForPdf($value);
        }
        
        // Handle JSON strings
        if (is_string($value) && $this->isJsonString($value)) {
            $decoded = json_decode($value, true);
            if ($decoded !== null) {
                return $this->formatJsonForPdf($decoded);
            }
        }
        
        // Handle regular strings and other types
        return (string) $value;
    }

    /**
     * Format JSON data for PDF display with human-readable formatting
     */
    private function formatJsonForPdf($data)
    {
        if (empty($data)) {
            return '';
        }

        $maxLength = config('visns-packages.report_export.pdf_formatting.max_json_display_length', 100);
        $style = config('visns-packages.report_export.pdf_formatting.json_formatting_style', 'compact');

        // Special handling for project details (if exists in your data structure)
        if (isset($data['sector']) && isset($data['project_status'])) {
            return $this->formatProjectDetailsForPdf($data);
        }

        switch ($style) {
            case 'minimal':
                return $this->formatJsonMinimal($data, $maxLength);
            case 'detailed':
                return $this->formatJsonDetailed($data, $maxLength);
            case 'compact':
            default:
                return $this->formatJsonCompact($data, $maxLength);
        }
    }

    /**
     * Format JSON in compact style for PDF
     */
    private function formatJsonCompact($data, $maxLength)
    {
        $result = [];
        $currentLength = 0;

        foreach ($data as $key => $value) {
            // Format the key for better readability
            $displayKey = $this->humanizeFieldName($key);

            if (is_array($value)) {
                if (!empty($value)) {
                    if (isset($value[0]) && is_array($value[0])) {
                        // Array of objects
                        $formatted = "$displayKey: [" . count($value) . ' items]';
                    } else {
                        // Associative array - show count and sample
                        $sampleKeys = array_slice(array_keys($value), 0, 2);
                        $sampleStr = implode(', ', array_map([$this, 'humanizeFieldName'], $sampleKeys));
                        $formatted = "$displayKey: {$sampleStr}" . (count($value) > 2 ? ', ...' : '');
                    }
                } else {
                    $formatted = "$displayKey: (empty)";
                }
            } else {
                // Simple value
                $formattedValue = is_bool($value) ? ($value ? 'Yes' : 'No') : (string) $value;
                if (strlen($formattedValue) > 30) {
                    $formattedValue = substr($formattedValue, 0, 27) . '...';
                }
                $formatted = "$displayKey: $formattedValue";
            }

            // Check if adding this would exceed length limit
            $additionalLength = strlen($formatted) + 2; // +2 for \n
            if ($currentLength + $additionalLength > $maxLength && !empty($result)) {
                $result[] = '...';
                break;
            }

            $result[] = $formatted;
            $currentLength += $additionalLength;
        }

        return implode("\n", $result);
    }

    /**
     * Format JSON in detailed style for PDF
     */
    private function formatJsonDetailed($data, $maxLength)
    {
        $result = [];
        $currentLength = 0;

        foreach ($data as $key => $value) {
            $displayKey = $this->humanizeFieldName($key);

            if (is_array($value)) {
                if (!empty($value)) {
                    $result[] = "$displayKey:";
                    $currentLength += strlen($displayKey) + 3;

                    if (isset($value[0]) && is_array($value[0])) {
                        // Array of objects
                        $result[] = "  [" . count($value) . " items]";
                        $currentLength += 20;
                    } else {
                        // Show nested key-value pairs
                        $nestedCount = 0;
                        foreach ($value as $nestedKey => $nestedValue) {
                            if ($nestedCount >= 3) {
                                $result[] = "  ...";
                                $currentLength += 10;
                                break;
                            }
                            
                            $nestedDisplayKey = $this->humanizeFieldName($nestedKey);
                            $nestedFormatted = is_bool($nestedValue) ? ($nestedValue ? 'Yes' : 'No') : (string) $nestedValue;
                            if (strlen($nestedFormatted) > 25) {
                                $nestedFormatted = substr($nestedFormatted, 0, 22) . '...';
                            }
                            
                            $line = "  $nestedDisplayKey: $nestedFormatted";
                            $result[] = $line;
                            $currentLength += strlen($line) + 1;
                            $nestedCount++;
                        }
                    }
                } else {
                    $result[] = "$displayKey: (empty)";
                    $currentLength += strlen($displayKey) + 10;
                }
            } else {
                $formattedValue = is_bool($value) ? ($value ? 'Yes' : 'No') : (string) $value;
                if (strlen($formattedValue) > 40) {
                    $formattedValue = substr($formattedValue, 0, 37) . '...';
                }
                $line = "$displayKey: $formattedValue";
                $result[] = $line;
                $currentLength += strlen($line) + 1;
            }

            // Check length limit
            if ($currentLength > $maxLength) {
                $result[] = '...';
                break;
            }
        }

        return implode("\n", $result);
    }

    /**
     * Format JSON in minimal style for PDF
     */
    private function formatJsonMinimal($data, $maxLength)
    {
        $summary = [];
        
        foreach ($data as $key => $value) {
            if (is_array($value)) {
                $summary[] = $this->humanizeFieldName($key) . ':Array';
            } elseif (is_bool($value)) {
                $summary[] = $this->humanizeFieldName($key) . ':' . ($value ? 'Y' : 'N');
            } else {
                $shortValue = strlen((string) $value) > 15 ? substr((string) $value, 0, 12) . '...' : (string) $value;
                $summary[] = $this->humanizeFieldName($key) . ':' . $shortValue;
            }
        }

        $result = implode(', ', $summary);
        
        if (strlen($result) > $maxLength) {
            $result = substr($result, 0, $maxLength - 3) . '...';
        }

        return $result;
    }

    /**
     * Format project details specifically for PDF (if this data structure exists)
     */
    private function formatProjectDetailsForPdf($data)
    {
        $important = [];
        
        // Prioritize key project information
        if (isset($data['project_status'])) {
            $important[] = 'Status: ' . $this->humanizeFieldName($data['project_status']);
        }
        if (isset($data['sector'])) {
            $important[] = 'Sector: ' . $this->humanizeFieldName($data['sector']);
        }
        if (isset($data['project_value'])) {
            $important[] = 'Value: ' . $data['project_value'];
        }
        if (isset($data['completion_date'])) {
            $important[] = 'Due: ' . $data['completion_date'];
        }
        
        // Add other fields if space allows
        $maxLength = config('visns-packages.report_export.pdf_formatting.max_json_display_length', 100);
        $current = implode("\n", $important);
        
        if (strlen($current) < $maxLength) {
            foreach ($data as $key => $value) {
                if (!in_array($key, ['project_status', 'sector', 'project_value', 'completion_date'])) {
                    $additional = $this->humanizeFieldName($key) . ': ' . (string) $value;
                    if (strlen($current . "\n" . $additional) <= $maxLength) {
                        $important[] = $additional;
                        $current .= "\n" . $additional;
                    } else {
                        break;
                    }
                }
            }
        }
        
        return implode("\n", $important);
    }

    /**
     * Calculate optimal column widths based on headers and content analysis
     */
    private function calculateOptimalColumnWidths($headers, $dataArray)
    {
        $totalWidth = 270; // A4 landscape width minus margins (mm)
        $minWidth = config('visns-packages.report_export.pdf_formatting.min_column_width', 25);
        $maxWidth = config('visns-packages.report_export.pdf_formatting.max_column_width', 70);
        
        $columnCount = count($headers);
        $columnTypes = $this->analyzeColumnTypes($headers, $dataArray);
        $baseWidths = $this->calculateBaseWidths($headers, $columnTypes, $dataArray);
        
        // Apply constraints and distribute remaining width
        $constrainedWidths = $this->applyWidthConstraints($baseWidths, $minWidth, $maxWidth, $totalWidth);
        
        
        return $constrainedWidths;
    }

    /**
     * Analyze column types based on headers and sample data
     */
    private function analyzeColumnTypes($headers, $dataArray)
    {
        $types = [];
        $sampleSize = min(10, count($dataArray)); // Sample first 10 rows
        
        foreach ($headers as $index => $header) {
            $type = $this->determineColumnType($header, $dataArray, $index, $sampleSize);
            $types[$index] = $type;
        }
        
        return $types;
    }

    /**
     * Determine column type based on header name and content
     */
    private function determineColumnType($header, $dataArray, $columnIndex, $sampleSize)
    {
        $headerLower = strtolower($header);
        
        // Analyze header name patterns
        if (preg_match('/^(id|pk|key)$/i', $headerLower) || str_ends_with($headerLower, '_id')) {
            return 'id';
        }
        
        if (preg_match('/(date|time|created|updated)/', $headerLower)) {
            return 'date';
        }
        
        if (preg_match('/(name|title|subject|description)/', $headerLower)) {
            return 'text';
        }
        
        if (preg_match('/(email|url|link)/', $headerLower)) {
            return 'text';
        }
        
        // Analyze sample data content
        $jsonCount = 0;
        $longTextCount = 0;
        $shortTextCount = 0;
        $numericCount = 0;
        
        for ($i = 0; $i < $sampleSize && $i < count($dataArray); $i++) {
            $rowData = array_values($dataArray[$i]);
            if (isset($rowData[$columnIndex])) {
                $value = $rowData[$columnIndex];
                
                if (is_array($value) || (is_string($value) && $this->isJsonString($value))) {
                    $jsonCount++;
                } elseif (is_numeric($value)) {
                    $numericCount++;
                } elseif (is_string($value)) {
                    if (strlen($value) > 50) {
                        $longTextCount++;
                    } else {
                        $shortTextCount++;
                    }
                }
            }
        }
        
        // Determine type based on content analysis
        if ($jsonCount > $sampleSize * 0.5) {
            return 'json';
        } elseif ($longTextCount > $sampleSize * 0.3) {
            return 'long_text';
        } elseif ($numericCount > $sampleSize * 0.7) {
            return 'numeric';
        } else {
            return 'text';
        }
    }

    /**
     * Calculate base widths for each column type
     */
    private function calculateBaseWidths($headers, $columnTypes, $dataArray)
    {
        $baseWidths = [];
        
        // Base width allocation by type (relative units)
        $typeWeights = [
            'id' => 1.0,        // Narrow - just IDs/keys
            'numeric' => 1.5,   // Medium - numbers
            'date' => 2.0,      // Medium - dates
            'text' => 2.5,      // Standard text
            'long_text' => 4.0, // Wide - descriptions
            'json' => 3.5,      // Wide - JSON content
        ];
        
        foreach ($columnTypes as $index => $type) {
            $baseWidths[$index] = $typeWeights[$type] ?? 2.5;
        }
        
        // Adjust for header length
        foreach ($headers as $index => $header) {
            $headerLength = strlen($this->formatColumnName($header));
            $minWidthForHeader = $headerLength * 1.5; // Rough estimate
            
            // Ensure header text fits
            if ($minWidthForHeader > $baseWidths[$index] * 10) { // Scale factor
                $baseWidths[$index] = $minWidthForHeader / 10;
            }
        }
        
        return $baseWidths;
    }

    /**
     * Apply width constraints and distribute total width
     */
    private function applyWidthConstraints($baseWidths, $minWidth, $maxWidth, $totalWidth)
    {
        $totalWeight = array_sum($baseWidths);
        $proportionalWidths = [];
        
        // Calculate proportional widths
        foreach ($baseWidths as $index => $weight) {
            $proportionalWidths[$index] = ($weight / $totalWeight) * $totalWidth;
        }
        
        // Apply min/max constraints
        $constrainedWidths = [];
        $totalUsed = 0;
        $flexibleColumns = [];
        
        foreach ($proportionalWidths as $index => $width) {
            if ($width < $minWidth) {
                $constrainedWidths[$index] = $minWidth;
                $totalUsed += $minWidth;
            } elseif ($width > $maxWidth) {
                $constrainedWidths[$index] = $maxWidth;
                $totalUsed += $maxWidth;
            } else {
                $flexibleColumns[] = $index;
            }
        }
        
        // Distribute remaining width among flexible columns
        $remainingWidth = $totalWidth - $totalUsed;
        $flexibleCount = count($flexibleColumns);
        
        if ($flexibleCount > 0) {
            $flexibleTotalWeight = 0;
            foreach ($flexibleColumns as $index) {
                $flexibleTotalWeight += $baseWidths[$index];
            }
            
            foreach ($flexibleColumns as $index) {
                $weight = $baseWidths[$index];
                $allocatedWidth = ($weight / $flexibleTotalWeight) * $remainingWidth;
                $constrainedWidths[$index] = max($minWidth, min($maxWidth, $allocatedWidth));
            }
        }
        
        // Final adjustment to ensure total width is used
        $actualTotal = array_sum($constrainedWidths);
        if ($actualTotal != $totalWidth) {
            $adjustment = $totalWidth / $actualTotal;
            foreach ($constrainedWidths as $index => $width) {
                $constrainedWidths[$index] = $width * $adjustment;
            }
        }
        
        return array_values($constrainedWidths); // Ensure sequential array
    }

    /**
     * Calculate row height with variable column widths
     */
    private function calculateRowHeightWithWidths($pdf, $dataRow, $columnWidths, $maxCellHeight)
    {
        $maxLines = 1;
        $fontSize = 6;
        $lineHeight = $fontSize * 1.2;
        
        $columnIndex = 0;
        foreach ($dataRow as $value) {
            if (is_null($value)) {
                $columnIndex++;
                continue;
            }
            
            $formattedValue = $this->formatValueForPdf($value);
            $columnWidth = $columnWidths[$columnIndex];
            
            // Estimate lines needed for this specific column width
            $charsPerLine = floor($columnWidth * 2.8);
            $estimatedLines = max(1, ceil(strlen($formattedValue) / $charsPerLine));
            
            // Account for explicit line breaks
            $explicitLines = substr_count($formattedValue, "\n") + 1;
            $lines = max($estimatedLines, $explicitLines);
            
            $maxLines = max($maxLines, $lines);
            $columnIndex++;
        }
        
        // Convert to millimeters and apply limits
        $heightMM = ($maxLines * $lineHeight * 0.352778);
        return min($heightMM, $maxCellHeight);
    }

    /**
     * Validate calculated field formula for security
     * 
     * @param string $formula
     * @return string|false Returns safe formula or false if validation fails
     */
    private function validateCalculatedFieldFormula($formula)
    {
        // Basic security checks to prevent SQL injection
        $formula = trim($formula);
        
        // Reject if empty
        if (empty($formula)) {
            return false;
        }

        // Convert to lowercase for checks (but preserve original case for return)
        $lowerFormula = strtolower($formula);

        // Blacklist dangerous SQL keywords/patterns
        $dangerousPatterns = [
            'drop ', 'delete ', 'truncate ', 'alter ', 'create ', 'insert ',
            'update ', 'grant ', 'revoke ', 'exec ', 'execute ', 'sp_',
            'xp_', '--', '/*', '*/', 'union ', 'script', '<script',
            'javascript:', 'vbscript:', 'onload=', 'onerror=', 'eval(',
            'information_schema', 'mysql.', 'performance_schema', 'sys.'
        ];

        foreach ($dangerousPatterns as $pattern) {
            if (strpos($lowerFormula, $pattern) !== false) {
                Log::warning("Calculated field formula contains dangerous pattern", [
                    'formula' => $formula,
                    'pattern' => $pattern
                ]);
                return false;
            }
        }

        // Allow only safe SQL functions and operators
        $allowedPatterns = [
            // Math functions
            'round', 'floor', 'ceil', 'abs', 'sqrt', 'pow',
            // Date functions  
            'datediff', 'date_add', 'date_sub', 'now', 'curdate', 'year', 'month', 'day',
            // String functions
            'concat', 'substring', 'length', 'upper', 'lower', 'trim',
            // Aggregate functions
            'count', 'sum', 'avg', 'min', 'max',
            // Conditional functions
            'case', 'when', 'then', 'else', 'end', 'if', 'ifnull', 'coalesce',
            // Operators and literals
            '+', '-', '*', '/', '(', ')', ',', '.', '=', '>', '<', '>=', '<=', '!=', '<>',
            'and', 'or', 'not', 'is', 'null', 'like', 'in', 'between',
            // Common column patterns (table.column)
            // Numbers and quotes for literals
        ];

        // Additional validation could be added here for more sophisticated checking
        // For now, we'll rely on the blacklist approach and let MySQL validate syntax

        // Ensure formula doesn't exceed reasonable length
        if (strlen($formula) > 1000) {
            Log::warning("Calculated field formula too long", [
                'formula_length' => strlen($formula)
            ]);
            return false;
        }

        return $formula;
    }
}
