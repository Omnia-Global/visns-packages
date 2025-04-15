<?php

namespace Visnsstudio\VisnsPackages\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Auth;
use Visnsstudio\VisnsPackages\Models\ReportBuilder;

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
            Log::info('getTables method called');

            // Get all tables from the database
            $tables = $this->getAllDatabaseTables();
            Log::info('Retrieved tables from getAllDatabaseTables', [
                'count' => count($tables),
            ]);

            // Debug: Log all tables
            Log::debug('All tables before filtering', ['tables' => $tables]);

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
            Log::info('Tables after filtering', ['count' => count($tables)]);

            // Format the response
            $formattedTables = [];
            foreach ($tables as $table) {
                $formattedTables[] = [
                    'name' => $table,
                    'label' => $this->formatTableName($table),
                ];
            }

            Log::info('Formatted tables for response', [
                'count' => count($formattedTables),
            ]);

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
                        $relatedTableBase . 's',      // Simple pluralization (user -> users)
                        $relatedTableBase . 'es',    // For words ending in 's', 'x', 'z', 'ch', 'sh' (box -> boxes)
                        $relatedTableBase . 'ies',   // For words ending in 'y' (category -> categories)
                        $relatedTableBase,           // No pluralization (staff -> staff)
                    ];

                    // Special cases for irregular plurals
                    if ($relatedTableBase === 'person') $possibleTables[] = 'people';
                    if ($relatedTableBase === 'child') $possibleTables[] = 'children';
                    if ($relatedTableBase === 'foot') $possibleTables[] = 'feet';
                    if ($relatedTableBase === 'tooth') $possibleTables[] = 'teeth';
                    if ($relatedTableBase === 'goose') $possibleTables[] = 'geese';
                    if ($relatedTableBase === 'man') $possibleTables[] = 'men';
                    if ($relatedTableBase === 'woman') $possibleTables[] = 'women';
                    if ($relatedTableBase === 'mouse') $possibleTables[] = 'mice';

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
                                    'confidence' => 'high'
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
                $tableName . '_id'
            ];

            // Special cases for irregular plurals
            if ($tableName === 'people') $potentialForeignKeys[] = 'person_id';
            if ($tableName === 'children') $potentialForeignKeys[] = 'child_id';
            if ($tableName === 'men') $potentialForeignKeys[] = 'man_id';
            if ($tableName === 'women') $potentialForeignKeys[] = 'woman_id';
            if ($tableName === 'feet') $potentialForeignKeys[] = 'foot_id';
            if ($tableName === 'teeth') $potentialForeignKeys[] = 'tooth_id';
            if ($tableName === 'geese') $potentialForeignKeys[] = 'goose_id';
            if ($tableName === 'mice') $potentialForeignKeys[] = 'mouse_id';

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
                                'confidence' => 'high'
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

                        if (in_array($fk1, $pivotColumns) && in_array($fk2, $pivotColumns)) {
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
                                'confidence' => 'medium'
                            ];
                        }
                    } else if (in_array($pivotPattern2, $allTables)) {
                        // This is likely a pivot table
                        $pivotTable = $pivotPattern2;
                        $pivotColumns = Schema::getColumnListing($pivotTable);

                        $fk1 = $singularTableName . '_id';
                        $fk2 = rtrim($otherTable, 's') . '_id';

                        if (in_array($fk1, $pivotColumns) && in_array($fk2, $pivotColumns)) {
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
                                'confidence' => 'medium'
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
                        'confidence' => $relationship['confidence']
                    ];
                } else if ($relationship['type'] === 'has_many') {
                    // Another table belongs to this table
                    $suggestedJoins[] = [
                        'sourceTable' => $relationship['target_table'],
                        'sourceColumn' => $relationship['target_column'],
                        'targetTable' => $relationship['source_table'],
                        'targetColumn' => $relationship['source_column'],
                        'joinType' => $relationship['join_type'],
                        'description' => $relationship['description'],
                        'confidence' => $relationship['confidence']
                    ];
                } else if ($relationship['type'] === 'many_to_many') {
                    // Many-to-many relationship through a pivot table
                    // First join from main table to pivot table
                    $suggestedJoins[] = [
                        'sourceTable' => $relationship['source_table'],
                        'sourceColumn' => $relationship['source_column'],
                        'targetTable' => $relationship['pivot_table'],
                        'targetColumn' => $relationship['pivot_source_column'],
                        'joinType' => $relationship['join_type'],
                        'description' => "Join from {$relationship['source_table']} to pivot table {$relationship['pivot_table']}",
                        'confidence' => $relationship['confidence'],
                        'isFirstPartOfManyToMany' => true,
                        'secondJoin' => [
                            'sourceTable' => $relationship['pivot_table'],
                            'sourceColumn' => $relationship['pivot_target_column'],
                            'targetTable' => $relationship['target_table'],
                            'targetColumn' => $relationship['target_column'],
                            'joinType' => $relationship['join_type'],
                            'description' => "Join from pivot table {$relationship['pivot_table']} to {$relationship['target_table']}",
                            'confidence' => $relationship['confidence']
                        ]
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
            Log::error(
                'Error getting suggested joins: ' . $e->getMessage()
            );
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
                    $relatedTableBase . 's',      // Simple pluralization (user -> users)
                    $relatedTableBase . 'es',    // For words ending in 's', 'x', 'z', 'ch', 'sh' (box -> boxes)
                    $relatedTableBase . 'ies',   // For words ending in 'y' (category -> categories)
                    $relatedTableBase,           // No pluralization (staff -> staff)
                ];

                // Special cases for irregular plurals
                if ($relatedTableBase === 'person') $possibleTables[] = 'people';
                if ($relatedTableBase === 'child') $possibleTables[] = 'children';
                if ($relatedTableBase === 'foot') $possibleTables[] = 'feet';
                if ($relatedTableBase === 'tooth') $possibleTables[] = 'teeth';
                if ($relatedTableBase === 'goose') $possibleTables[] = 'geese';
                if ($relatedTableBase === 'man') $possibleTables[] = 'men';
                if ($relatedTableBase === 'woman') $possibleTables[] = 'women';
                if ($relatedTableBase === 'mouse') $possibleTables[] = 'mice';

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
                                'confidence' => 'high'
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
            $tableName . '_id'
        ];

        // Special cases for irregular plurals
        if ($tableName === 'people') $potentialForeignKeys[] = 'person_id';
        if ($tableName === 'children') $potentialForeignKeys[] = 'child_id';
        if ($tableName === 'men') $potentialForeignKeys[] = 'man_id';
        if ($tableName === 'women') $potentialForeignKeys[] = 'woman_id';
        if ($tableName === 'feet') $potentialForeignKeys[] = 'foot_id';
        if ($tableName === 'teeth') $potentialForeignKeys[] = 'tooth_id';
        if ($tableName === 'geese') $potentialForeignKeys[] = 'goose_id';
        if ($tableName === 'mice') $potentialForeignKeys[] = 'mouse_id';

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
                            'confidence' => 'high'
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

                    if (in_array($fk1, $pivotColumns) && in_array($fk2, $pivotColumns)) {
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
                            'confidence' => 'medium'
                        ];
                    }
                } else if (in_array($pivotPattern2, $allTables)) {
                    // This is likely a pivot table
                    $pivotTable = $pivotPattern2;
                    $pivotColumns = Schema::getColumnListing($pivotTable);

                    $fk1 = $singularTableName . '_id';
                    $fk2 = rtrim($otherTable, 's') . '_id';

                    if (in_array($fk1, $pivotColumns) && in_array($fk2, $pivotColumns)) {
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
                            'confidence' => 'medium'
                        ];
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
            Log::info('Getting all database tables');

            // Use the simplest and most reliable method - SHOW TABLES
            $databaseName = DB::connection()->getDatabaseName();
            Log::info('Using SHOW TABLES to get tables', [
                'database' => $databaseName,
            ]);

            $tables = DB::select('SHOW TABLES');
            if (!empty($tables)) {
                Log::info('Retrieved tables using SHOW TABLES', [
                    'count' => count($tables),
                ]);

                // Determine the property name from the first table
                $firstTable = $tables[0];

                // Dump the first table object to see its structure
                Log::debug('First table object structure', [
                    'object' => json_encode($firstTable),
                    'properties' => get_object_vars($firstTable),
                ]);

                $propertyName = 'Tables_in_' . strtolower($databaseName);

                // If the property doesn't exist, try to find the first property
                if (!property_exists($firstTable, $propertyName)) {
                    $vars = get_object_vars($firstTable);
                    if (!empty($vars)) {
                        $propertyName = array_key_first($vars);
                        Log::info('Using detected property name', [
                            'property' => $propertyName,
                        ]);
                    } else {
                        Log::warning('No properties found in table object');
                        return [];
                    }
                }

                $tableNames = array_map(function ($table) use ($propertyName) {
                    return $table->$propertyName;
                }, $tables);

                Log::info('Successfully extracted table names', [
                    'count' => count($tableNames),
                    'first_few' => array_slice($tableNames, 0, 5),
                ]);

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
            Log::info('Getting column type', [
                'table' => $tableName,
                'column' => $column,
            ]);

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
                    Log::info('Retrieved column type using Schema Builder', [
                        'type' => $type,
                    ]);
                    return $type;
                } catch (\Exception $e) {
                    Log::info(
                        'Schema Builder getColumnType failed: ' .
                            $e->getMessage()
                    );
                }
            }

            // Method 2: Using raw query
            try {
                $databaseName = DB::connection()->getDatabaseName();
                Log::info('Using raw query to get column type', [
                    'database' => $databaseName,
                    'table' => $tableName,
                    'column' => $column,
                ]);

                $result = DB::select(
                    'SELECT DATA_TYPE FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND COLUMN_NAME = ?',
                    [$databaseName, $tableName, $column]
                );

                if (!empty($result)) {
                    $type = strtolower($result[0]->DATA_TYPE);
                    Log::info('Retrieved column type using raw query', [
                        'type' => $type,
                    ]);
                    return $type;
                }
            } catch (\Exception $e) {
                Log::info(
                    'Raw query for column type failed: ' . $e->getMessage()
                );
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
                        Log::info('Inferred column type from sample value', [
                            'type' => $inferredType,
                        ]);
                        return $inferredType;
                    }
                }
            } catch (\Exception $e) {
                Log::info('Inferring column type failed: ' . $e->getMessage());
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
            // Validate request
            $validated = $request->validate([
                'query' => 'required|array',
                'report_id' => 'nullable|integer',
                'limit' => 'nullable|integer|min:1|max:1000',
                'offset' => 'nullable|integer|min:0',
                'parameters' => 'nullable|array',
            ]);

            // Set default limit if not provided
            $limit = $validated['limit'] ?? 100;
            $offset = $validated['offset'] ?? 0;
            $parameters = $validated['parameters'] ?? [];

            // Get the query configuration
            $queryConfig = $validated['query'];

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
        // Extract configuration
        $mainTable = $queryConfig['mainTable'] ?? null;
        $columns = $queryConfig['columns'] ?? [];
        $joins = $queryConfig['joins'] ?? [];
        $filters = $queryConfig['filters'] ?? [];
        $sorting = $queryConfig['sorting'] ?? [];
        $groupBy = $queryConfig['groupBy'] ?? [];

        // Validate main table
        if (!$mainTable) {
            throw new \Exception('Main table is required');
        }

        // Validate columns
        if (empty($columns)) {
            throw new \Exception('At least one column must be selected');
        }

        // Start building the query
        $query = DB::table($mainTable);

        // Add joins
        foreach ($joins as $join) {
            $joinType = strtolower($join['joinType'] ?? 'inner');
            $targetTable = $join['targetTable'] ?? null;
            $sourceColumn = $join['sourceColumn'] ?? null;
            $targetColumn = $join['targetColumn'] ?? null;

            if (!$targetTable || !$sourceColumn || !$targetColumn) {
                continue; // Skip invalid joins
            }

            // Determine join method
            switch ($joinType) {
                case 'left':
                    $query->leftJoin(
                        $targetTable,
                        "$mainTable.$sourceColumn",
                        '=',
                        "$targetTable.$targetColumn"
                    );
                    break;
                case 'right':
                    $query->rightJoin(
                        $targetTable,
                        "$mainTable.$sourceColumn",
                        '=',
                        "$targetTable.$targetColumn"
                    );
                    break;
                case 'inner':
                default:
                    $query->join(
                        $targetTable,
                        "$mainTable.$sourceColumn",
                        '=',
                        "$targetTable.$targetColumn"
                    );
                    break;
            }
        }

        // Add columns
        $selectColumns = [];
        foreach ($columns as $column) {
            $tableName = $column['table'] ?? $mainTable;
            $columnName = $column['column'] ?? null;
            $alias = $column['alias'] ?? null;

            if (!$columnName) {
                continue; // Skip invalid columns
            }

            if ($alias) {
                $selectColumns[] = DB::raw(
                    "$tableName.$columnName as `$alias`"
                );
            } else {
                $selectColumns[] = "$tableName.$columnName";
            }
        }

        $query->select($selectColumns);

        // Add filters
        foreach ($filters as $filter) {
            $tableName = $filter['table'] ?? $mainTable;
            $columnName = $filter['column'] ?? null;
            $operator = $filter['operator'] ?? '=';
            $value = $filter['value'] ?? null;
            $logicalOperator = strtolower($filter['logicalOperator'] ?? 'and');

            if (!$columnName) {
                continue; // Skip invalid filters
            }

            // Check if this is a parameter reference
            if (
                is_string($value) &&
                preg_match('/^\{([^\}]+)\}$/', $value, $matches)
            ) {
                $paramName = $matches[1];
                if (isset($parameters[$paramName])) {
                    $value = $parameters[$paramName];
                }
            }

            // Apply the filter
            $columnRef = "$tableName.$columnName";

            if ($logicalOperator === 'or') {
                switch (strtolower($operator)) {
                    case 'like':
                        $query->orWhere($columnRef, 'like', "%$value%");
                        break;
                    case 'not like':
                        $query->orWhere($columnRef, 'not like', "%$value%");
                        break;
                    case 'in':
                        if (is_array($value)) {
                            $query->orWhereIn($columnRef, $value);
                        }
                        break;
                    case 'not in':
                        if (is_array($value)) {
                            $query->orWhereNotIn($columnRef, $value);
                        }
                        break;
                    case 'between':
                        if (is_array($value) && count($value) === 2) {
                            $query->orWhereBetween($columnRef, $value);
                        }
                        break;
                    case 'not between':
                        if (is_array($value) && count($value) === 2) {
                            $query->orWhereNotBetween($columnRef, $value);
                        }
                        break;
                    case 'null':
                        $query->orWhereNull($columnRef);
                        break;
                    case 'not null':
                        $query->orWhereNotNull($columnRef);
                        break;
                    default:
                        $query->orWhere($columnRef, $operator, $value);
                        break;
                }
            } else {
                switch (strtolower($operator)) {
                    case 'like':
                        $query->where($columnRef, 'like', "%$value%");
                        break;
                    case 'not like':
                        $query->where($columnRef, 'not like', "%$value%");
                        break;
                    case 'in':
                        if (is_array($value)) {
                            $query->whereIn($columnRef, $value);
                        }
                        break;
                    case 'not in':
                        if (is_array($value)) {
                            $query->whereNotIn($columnRef, $value);
                        }
                        break;
                    case 'between':
                        if (is_array($value) && count($value) === 2) {
                            $query->whereBetween($columnRef, $value);
                        }
                        break;
                    case 'not between':
                        if (is_array($value) && count($value) === 2) {
                            $query->whereNotBetween($columnRef, $value);
                        }
                        break;
                    case 'null':
                        $query->whereNull($columnRef);
                        break;
                    case 'not null':
                        $query->whereNotNull($columnRef);
                        break;
                    default:
                        $query->where($columnRef, $operator, $value);
                        break;
                }
            }
        }

        // Add group by
        foreach ($groupBy as $group) {
            $tableName = $group['table'] ?? $mainTable;
            $columnName = $group['column'] ?? null;

            if (!$columnName) {
                continue; // Skip invalid group by
            }

            $query->groupBy("$tableName.$columnName");
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

        // Get the total count before applying limit and offset
        $countQuery = clone $query;
        $total = $countQuery->count();

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

        // Execute the query
        $results = $query->get();

        return [
            'data' => $results,
            'total' => $total,
            'sql' => $sql,
        ];
    }

    /**
     * Export report data as CSV or Excel
     *
     * @param Request $request
     * @return \Symfony\Component\HttpFoundation\BinaryFileResponse
     */
    public function exportReport(Request $request)
    {
        try {
            // Validate request
            $validated = $request->validate([
                'query' => 'required|array',
                'report_id' => 'nullable|integer',
                'format' => 'required|in:csv,xlsx',
                'parameters' => 'nullable|array',
            ]);

            // Get the query configuration
            $queryConfig = $validated['query'];
            $format = $validated['format'];
            $parameters = $validated['parameters'] ?? [];

            // If report_id is provided, load the report configuration
            $reportName = 'report';
            if (isset($validated['report_id'])) {
                $report = ReportBuilder::findOrFail($validated['report_id']);

                // Check if user has access to this report
                $userId = Auth::id() ?? $request->input('user_id');
                if (!$report->is_public && $report->user_id !== $userId) {
                    return response()->json([
                        'success' => false,
                        'message' => 'You do not have permission to export this report',
                    ], 403);
                }

                // Use the report configuration if query is not provided
                if (empty($queryConfig)) {
                    $queryConfig = $report->detail;
                }

                $reportName = $report->label;
            }

            // Build and execute the SQL query without limits for export
            $result = $this->buildAndExecuteQuery($queryConfig, 100000, 0, $parameters);

            // Format the date for the filename
            $date = date('Ymd');
            $filename = "{$date}_{$reportName}.{$format}";

            // Generate the export file based on format
            return $this->generateExportFile($result['data'], $filename, $format);
        } catch (\Exception $e) {
            Log::error('Error exporting report: ' . $e->getMessage());
            Log::error('Stack trace: ' . $e->getTraceAsString());
            return response()->json([
                'success' => false,
                'message' => 'Error exporting report',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Generate export file in the specified format
     *
     * @param \Illuminate\Support\Collection $data
     * @param string $filename
     * @param string $format
     * @return \Symfony\Component\HttpFoundation\BinaryFileResponse
     */
    private function generateExportFile($data, $filename, $format)
    {
        // Convert data collection to array
        $dataArray = json_decode(json_encode($data), true);

        // Get column headers from the first row
        $headers = [];
        if (!empty($dataArray)) {
            $headers = array_keys($dataArray[0]);
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
            return response()->download($tempFile, $filename, [
                'Content-Type' => 'text/csv',
            ])->deleteFileAfterSend(true);
        } else {
            // Generate Excel file using PhpSpreadsheet
            $spreadsheet = new \PhpOffice\PhpSpreadsheet\Spreadsheet();
            $sheet = $spreadsheet->getActiveSheet();

            // Add headers (first row)
            $column = 1;
            foreach ($headers as $header) {
                $sheet->setCellValueByColumnAndRow($column++, 1, $header);
            }

            // Add data rows
            $row = 2;
            foreach ($dataArray as $dataRow) {
                $column = 1;
                foreach ($dataRow as $value) {
                    $sheet->setCellValueByColumnAndRow($column++, $row, $value);
                }
                $row++;
            }

            // Create Excel writer
            $writer = new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($spreadsheet);
            $writer->save($tempFile);

            // Return the file as a download
            return response()->download($tempFile, $filename, [
                'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            ])->deleteFileAfterSend(true);
        }
    }
}
