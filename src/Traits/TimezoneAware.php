<?php

namespace Visnsstudio\VisnsPackages\Traits;

use Carbon\Carbon;
use DateTimeInterface;
use Illuminate\Support\Arr;

trait TimezoneAware
{
    /**
     * Get the application timezone or fallback to UTC.
     *
     * @return string
     */
    protected function getApplicationTimezone()
    {
        return config(
            'visns-packages.timezone.display_timezone',
            config('app.timezone', 'UTC')
        );
    }

    /**
     * Get the storage timezone (always UTC for database storage).
     *
     * @return string
     */
    protected function getStorageTimezone()
    {
        return 'UTC';
    }

    /**
     * Get the fields that should be converted to application timezone.
     *
     * @return array
     */
    protected function getTimezoneAwareFields()
    {
        // If the model has a timezoneAwareFields property, use it
        if (property_exists($this, 'timezoneAwareFields')) {
            return $this->timezoneAwareFields;
        }

        // Otherwise, use all datetime fields from casts
        return array_keys(
            array_filter($this->getCasts(), function ($cast) {
                return in_array($cast, [
                    'datetime',
                    'date',
                    'immutable_datetime',
                    'immutable_date',
                ]);
            })
        );
    }

    /**
     * Convert a UTC datetime to the application timezone.
     *
     * @param  mixed  $value
     * @return \Carbon\Carbon|null
     */
    public function convertToApplicationTimezone($value)
    {
        if (empty($value)) {
            return $value;
        }

        if (!$value instanceof Carbon) {
            // Check if the value already has timezone information
            if (
                is_string($value) &&
                (strpos($value, '+') !== false || strpos($value, 'Z') !== false)
            ) {
                // Parse with the timezone information from the string
                $value = Carbon::parse($value);
            } else {
                // Assume storage timezone if no timezone info is present
                $value = Carbon::parse($value, $this->getStorageTimezone());
            }
        }

        return $value->setTimezone($this->getApplicationTimezone());
    }

    /**
     * Convert a datetime from application timezone to UTC for storage.
     *
     * @param  mixed  $value
     * @return \Carbon\Carbon|null
     */
    public function convertToStorageTimezone($value)
    {
        if (empty($value)) {
            return $value;
        }

        if (!$value instanceof Carbon) {
            // Check if the value already has timezone information
            if (
                is_string($value) &&
                (strpos($value, '+') !== false || strpos($value, 'Z') !== false)
            ) {
                // Parse with the timezone information from the string
                $value = Carbon::parse($value);
            } else {
                // Assume application timezone if no timezone info is present
                $value = Carbon::parse($value, $this->getApplicationTimezone());
            }
        }

        return $value->setTimezone($this->getStorageTimezone());
    }

    /**
     * Prepare a date for array / JSON serialization.
     *
     * @param  \DateTimeInterface  $date
     * @return string
     */
    protected function serializeDate(DateTimeInterface $date)
    {
        // Check if automatic timezone conversion is enabled
        if (config('visns-packages.timezone.auto_convert', true)) {
            // Get the current timezone of the date
            $currentTz =
                $date instanceof Carbon ? $date->timezone->getName() : 'UTC';
            $appTz = $this->getApplicationTimezone();

            // Only convert if not already in application timezone
            if ($currentTz !== $appTz) {
                $date = Carbon::instance($date)->setTimezone($appTz);
            }
        }

        $format = $this->getDateFormat();

        // If a custom date format is specified in the config, use it
        if ($customFormat = config('visns-packages.timezone.date_format')) {
            $format = $customFormat;
        }

        // Check if we should preserve the application timezone in the output
        if (config('visns-packages.timezone.preserve_timezone', false)) {
            // Use ISO8601 format with timezone information
            return $date->toIso8601String();
        }

        return $date->format($format);
    }

    /**
     * Convert the model's attributes to an array.
     *
     * @return array
     */
    public function toArray()
    {
        $array = parent::toArray();

        // If automatic timezone conversion is disabled, return the array as is
        if (!config('visns-packages.timezone.auto_convert', true)) {
            return $array;
        }

        // Get the fields that should be converted
        $fields = $this->getTimezoneAwareFields();

        // Convert each field to application timezone
        foreach ($fields as $field) {
            if (
                Arr::has($array, $field) &&
                !is_null(Arr::get($array, $field))
            ) {
                $value = $this->getAttribute($field);

                if ($value instanceof DateTimeInterface) {
                    // The serializeDate method will handle the conversion
                    // This is just to ensure the attribute is accessed and serialized
                    Arr::set($array, $field, $this->serializeDate($value));
                }
            }
        }

        return $array;
    }

    /**
     * Set a given attribute on the model.
     *
     * @param  string  $key
     * @param  mixed  $value
     * @return mixed
     */
    public function setAttribute($key, $value)
    {
        // If the attribute is a date field and automatic conversion is enabled
        if (
            in_array($key, $this->getTimezoneAwareFields()) &&
            config('visns-packages.timezone.auto_convert', true) &&
            !is_null($value)
        ) {
            // Check if the value is already in UTC format with 'Z' suffix
            $isAlreadyUtc = is_string($value) && strpos($value, 'Z') !== false;

            // Only convert if not already in UTC
            if (!$isAlreadyUtc) {
                // Convert the value to UTC for storage
                $value = $this->convertToStorageTimezone($value);
            } else {
                // If already in UTC format, just parse it without conversion
                $value = Carbon::parse($value);
            }
        }

        return parent::setAttribute($key, $value);
    }

    /**
     * Debug timezone conversion for a specific attribute.
     *
     * @param  string  $attribute
     * @return array
     */
    public function debugTimezoneConversion($attribute)
    {
        $value = $this->getAttribute($attribute);
        $originalValue = $this->getOriginal($attribute);

        return [
            'attribute' => $attribute,
            'current_value' => $value,
            'current_value_timezone' =>
                $value instanceof Carbon ? $value->timezone->getName() : null,
            'original_value' => $originalValue,
            'original_value_timezone' =>
                $originalValue instanceof Carbon
                    ? $originalValue->timezone->getName()
                    : null,
            'app_timezone' => $this->getApplicationTimezone(),
            'storage_timezone' => $this->getStorageTimezone(),
            'is_timezone_aware' => in_array(
                $attribute,
                $this->getTimezoneAwareFields()
            ),
            'auto_convert_enabled' => config(
                'visns-packages.timezone.auto_convert',
                true
            ),
        ];
    }
}
