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
        return config('visns-packages.timezone.display_timezone', config('app.timezone', 'UTC'));
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
        return array_keys(array_filter($this->getCasts(), function ($cast) {
            return in_array($cast, ['datetime', 'date', 'immutable_datetime', 'immutable_date']);
        }));
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
            $value = Carbon::parse($value, $this->getStorageTimezone());
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
            $value = Carbon::parse($value, $this->getApplicationTimezone());
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
            // Convert to application timezone before serializing
            $date = Carbon::instance($date)->setTimezone($this->getApplicationTimezone());
        }

        $format = $this->getDateFormat();
        
        // If a custom date format is specified in the config, use it
        if ($customFormat = config('visns-packages.timezone.date_format')) {
            $format = $customFormat;
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
            if (Arr::has($array, $field) && !is_null(Arr::get($array, $field))) {
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
            // Convert the value to UTC for storage
            $value = $this->convertToStorageTimezone($value);
        }

        return parent::setAttribute($key, $value);
    }
}
