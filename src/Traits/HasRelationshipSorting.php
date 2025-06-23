<?php

namespace Visnsstudio\VisnsPackages\Traits;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

trait HasRelationshipSorting
{
    /**
     * Enhanced scope for custom ordering with relationship sorting support.
     *
     * Supports:
     * - Regular column sorting: 'name', 'created_at'
     * - Relationship sorting: 'user.name', 'profile.title', 'category.name'
     * - Multiple relationship levels: 'user.profile.company.name'
     *
     * @param \Illuminate\Database\Eloquent\Builder $query
     * @param string|null $orderBy
     * @param string|null $order
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeCustomOrder($query, $orderBy, $order)
    {
        \Log::info("HasRelationshipSorting scopeCustomOrder called", [
            'model' => get_class($this),
            'orderBy' => $orderBy,
            'order' => $order,
            'hasTraitMethod' => method_exists($this, 'applyRelationshipSorting')
        ]);

        if (!isset($orderBy) || !isset($order)) {
            return $query;
        }

        // Handle dot notation (could be relationship or JSON field)
        if (str_contains($orderBy, '.')) {
            \Log::info("Detected dot notation for: {$orderBy}");
            
            $parts = explode('.', $orderBy);
            $firstPart = $parts[0];
            
            // Check if this is a JSON field (column exists on this model)
            if ($this->isJsonField($firstPart)) {
                \Log::info("Detected JSON field sorting for: {$orderBy}");
                return $this->applyJsonFieldSorting($query, $orderBy, $order);
            }
            
            // Check if this is a relationship
            if (method_exists($this, $firstPart)) {
                \Log::info("Detected relationship sorting for: {$orderBy}");
                return $this->applyRelationshipSorting($query, $orderBy, $order);
            }
            
            // Fallback: try as JSON field anyway
            \Log::info("Fallback to JSON field sorting for: {$orderBy}");
            return $this->applyJsonFieldSorting($query, $orderBy, $order);
        }

        // Regular column sorting
        \Log::info("Using regular column sorting for: {$orderBy}");
        return $query->orderBy($orderBy, $order);
    }

    /**
     * Apply sorting based on relationship fields using subqueries.
     *
     * @param \Illuminate\Database\Eloquent\Builder $query
     * @param string $orderBy
     * @param string $order
     * @return \Illuminate\Database\Eloquent\Builder
     */
    protected function applyRelationshipSorting($query, $orderBy, $order)
    {
        $parts = explode('.', $orderBy);
        $relationName = array_shift($parts);
        $column = implode('.', $parts);

        // Validate that the relationship exists
        if (!method_exists($this, $relationName)) {
            // Fallback to regular ordering if relationship doesn't exist
            return $query->orderBy($orderBy, $order);
        }

        try {
            $relation = $this->$relationName();
            
            // Handle different relationship types
            if ($relation instanceof BelongsTo || $relation instanceof HasOne) {
                return $this->applySingleRelationSorting($query, $relation, $column, $order);
            } elseif ($relation instanceof HasMany || $relation instanceof BelongsToMany) {
                return $this->applyMultipleRelationSorting($query, $relation, $column, $order);
            }
            
            // Fallback for unsupported relationship types
            return $query->orderBy($orderBy, $order);
            
        } catch (\Exception $e) {
            // Log the error and fallback to regular sorting
            \Log::warning("Relationship sorting failed for {$orderBy}: " . $e->getMessage());
            return $query->orderBy($orderBy, $order);
        }
    }

    /**
     * Apply sorting for single-value relationships (BelongsTo, HasOne).
     *
     * @param \Illuminate\Database\Eloquent\Builder $query
     * @param \Illuminate\Database\Eloquent\Relations\Relation $relation
     * @param string $column
     * @param string $order
     * @return \Illuminate\Database\Eloquent\Builder
     */
    protected function applySingleRelationSorting($query, $relation, $column, $order)
    {
        // Handle nested relationships (e.g., user.profile.company.name)
        if (str_contains($column, '.')) {
            $subQuery = $relation->getQuery()
                ->select($column)
                ->whereColumn(
                    $relation->getQualifiedForeignKeyName(),
                    $relation->getQualifiedOwnerKeyName()
                )
                ->limit(1);
        } else {
            // Simple relationship column
            $relatedTable = $relation->getRelated()->getTable();
            $qualifiedColumn = "{$relatedTable}.{$column}";
            
            $subQuery = $relation->getQuery()
                ->select($qualifiedColumn)
                ->whereColumn(
                    $relation->getQualifiedForeignKeyName(),
                    $relation->getQualifiedOwnerKeyName()
                )
                ->limit(1);
        }

        return $query->orderBy($subQuery, $order);
    }

    /**
     * Apply sorting for multiple-value relationships (HasMany, BelongsToMany).
     * Uses the first record for sorting.
     *
     * @param \Illuminate\Database\Eloquent\Builder $query
     * @param \Illuminate\Database\Eloquent\Relations\Relation $relation
     * @param string $column
     * @param string $order
     * @return \Illuminate\Database\Eloquent\Builder
     */
    protected function applyMultipleRelationSorting($query, $relation, $column, $order)
    {
        $relatedTable = $relation->getRelated()->getTable();
        $qualifiedColumn = "{$relatedTable}.{$column}";
        
        if ($relation instanceof BelongsToMany) {
            // Handle BelongsToMany relationships with pivot table
            $pivotTable = $relation->getTable();
            $foreignPivotKey = $relation->getForeignPivotKeyName();
            $relatedPivotKey = $relation->getRelatedPivotKeyName();
            $parentKey = $relation->getParentKeyName();
            $relatedKey = $relation->getRelatedKeyName();
            
            $subQuery = $relation->getRelated()->newQuery()
                ->select($qualifiedColumn)
                ->join($pivotTable, "{$relatedTable}.{$relatedKey}", '=', "{$pivotTable}.{$relatedPivotKey}")
                ->whereColumn("{$pivotTable}.{$foreignPivotKey}", $this->getTable() . ".{$parentKey}")
                ->orderBy($qualifiedColumn, $order)
                ->limit(1);
        } else {
            // Handle HasMany relationships
            $subQuery = $relation->getQuery()
                ->select($qualifiedColumn)
                ->whereColumn(
                    $relation->getQualifiedForeignKeyName(),
                    $relation->getQualifiedParentKeyName()
                )
                ->orderBy($qualifiedColumn, $order)
                ->limit(1);
        }

        return $query->orderBy($subQuery, $order);
    }

    /**
     * Check if a field is a JSON field on this model.
     *
     * @param string $fieldName
     * @return bool
     */
    protected function isJsonField($fieldName)
    {
        // Check if the field is in the model's casts as 'array' or 'json'
        $casts = $this->getCasts();
        if (isset($casts[$fieldName]) && in_array($casts[$fieldName], ['array', 'json', 'object', 'collection'])) {
            return true;
        }
        
        // Check if the field is in the fillable array (basic existence check)
        if (in_array($fieldName, $this->getFillable())) {
            return true;
        }
        
        // Check against a list of common JSON field names
        $commonJsonFields = ['data', 'metadata', 'settings', 'config', 'options', 'detail', 'details', 'project_detail', 'client_detail'];
        if (in_array($fieldName, $commonJsonFields)) {
            return true;
        }
        
        return false;
    }

    /**
     * Apply sorting for JSON fields using MySQL JSON functions.
     *
     * @param \Illuminate\Database\Eloquent\Builder $query
     * @param string $orderBy
     * @param string $order
     * @return \Illuminate\Database\Eloquent\Builder
     */
    protected function applyJsonFieldSorting($query, $orderBy, $order)
    {
        // Convert dot notation to JSON path
        // e.g., 'project_detail.project_status' becomes 'project_detail->>"$.project_status"'
        $parts = explode('.', $orderBy);
        $jsonField = array_shift($parts);
        $jsonPath = '$.' . implode('.', $parts);
        
        // Use MySQL JSON_EXTRACT function for sorting
        $jsonExtract = "JSON_EXTRACT({$this->getTable()}.{$jsonField}, '{$jsonPath}')";
        
        return $query->orderByRaw("{$jsonExtract} {$order}");
    }

    /**
     * Get the sortable relationship fields for this model.
     * Override this method in your model to define which relationship fields can be sorted.
     *
     * @return array
     */
    public function getSortableRelationshipFields()
    {
        return [];
    }

    /**
     * Check if a relationship field is sortable.
     *
     * @param string $field
     * @return bool
     */
    public function isRelationshipFieldSortable($field)
    {
        $sortableFields = $this->getSortableRelationshipFields();
        
        if (empty($sortableFields)) {
            return true; // Allow all if not explicitly defined
        }
        
        return in_array($field, $sortableFields);
    }
}