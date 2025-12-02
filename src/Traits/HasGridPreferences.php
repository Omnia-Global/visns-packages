<?php

namespace Visnsstudio\VisnsPackages\Traits;

/**
 * Trait HasGridPreferences
 *
 * Provides user-specific grid preferences functionality.
 * This trait can be used by User models to manage DataGrid preferences
 * like rows per page settings.
 *
 * Requirements:
 * - The model should have either a 'rows_per_page' column OR 'dashboard_settings' JSON column
 * - 'dashboard_settings' should be cast as 'array' in the model's $casts
 *
 * Usage:
 * 1. Add the trait to your User model: use HasGridPreferences;
 * 2. Access preferences: $user->getRowsPerPage() or $user->rows_per_page
 * 3. Save preferences: $user->setRowsPerPage(50)
 */
trait HasGridPreferences
{
    /**
     * Get the user's preferred rows per page setting.
     *
     * Priority:
     * 1. Direct 'rows_per_page' column if it exists and is set
     * 2. 'dashboard_settings.rows_per_page' if using JSON storage
     * 3. Default value (25)
     *
     * @param int $default The default value if no preference is set
     * @return int
     */
    public function getRowsPerPage(int $default = 25): int
    {
        // Check direct column first
        if (isset($this->attributes['rows_per_page']) && $this->attributes['rows_per_page'] !== null) {
            return (int) $this->attributes['rows_per_page'];
        }

        // Fall back to dashboard_settings JSON
        $settings = $this->dashboard_settings ?? [];
        return (int) ($settings['rows_per_page'] ?? $default);
    }

    /**
     * Set the user's preferred rows per page setting.
     *
     * Automatically detects whether to use direct column or JSON storage.
     *
     * @param int $value The number of rows per page (will be validated)
     * @return void
     */
    public function setRowsPerPage(int $value): void
    {
        // Validate the value is within acceptable range
        $value = $this->validateRowsPerPage($value);

        // Check if we have a direct column available
        if ($this->hasRowsPerPageColumn()) {
            $this->rows_per_page = $value;
        } else {
            // Use dashboard_settings JSON storage
            $settings = $this->dashboard_settings ?? [];
            $settings['rows_per_page'] = $value;
            $this->dashboard_settings = $settings;
        }

        $this->save();
    }

    /**
     * Check if the model has a direct rows_per_page column.
     *
     * @return bool
     */
    protected function hasRowsPerPageColumn(): bool
    {
        return in_array('rows_per_page', $this->fillable ?? []) ||
            \Schema::hasColumn($this->getTable(), 'rows_per_page');
    }

    /**
     * Validate rows per page value is within acceptable range.
     *
     * @param int $value
     * @return int
     */
    protected function validateRowsPerPage(int $value): int
    {
        $allowedValues = [10, 25, 50, 100, 200];

        // If the value is in the allowed list, use it
        if (in_array($value, $allowedValues)) {
            return $value;
        }

        // Otherwise, find the closest allowed value
        $closest = 25;
        $minDiff = PHP_INT_MAX;

        foreach ($allowedValues as $allowed) {
            $diff = abs($value - $allowed);
            if ($diff < $minDiff) {
                $minDiff = $diff;
                $closest = $allowed;
            }
        }

        return $closest;
    }

    /**
     * Get grid preference by key.
     * Can be extended to support more grid-specific preferences in the future.
     *
     * @param string $gridKey The unique identifier for the grid (e.g., 'contacts', 'tasks')
     * @param string|null $key The preference key (e.g., 'limit', 'columns')
     * @param mixed $default The default value if preference is not set
     * @return mixed
     */
    public function getGridPreference(string $gridKey, ?string $key = null, $default = null)
    {
        $settings = $this->dashboard_settings ?? [];
        $gridPreferences = $settings['grid_preferences'][$gridKey] ?? [];

        if ($key === null) {
            return $gridPreferences ?: $default;
        }

        return $gridPreferences[$key] ?? $default;
    }

    /**
     * Set grid preference by key.
     * Can be extended to support more grid-specific preferences in the future.
     *
     * @param string $gridKey The unique identifier for the grid
     * @param string $key The preference key
     * @param mixed $value The preference value
     * @return void
     */
    public function setGridPreference(string $gridKey, string $key, $value): void
    {
        $settings = $this->dashboard_settings ?? [];

        if (!isset($settings['grid_preferences'])) {
            $settings['grid_preferences'] = [];
        }

        if (!isset($settings['grid_preferences'][$gridKey])) {
            $settings['grid_preferences'][$gridKey] = [];
        }

        $settings['grid_preferences'][$gridKey][$key] = $value;
        $this->dashboard_settings = $settings;
        $this->save();
    }
}
