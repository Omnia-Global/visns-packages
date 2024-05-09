<?php

namespace Visnsstudio\VisnsPackages\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Audit extends Model
{
    use HasFactory;

    protected $casts = [
        "old_values" => "array",
        "new_values" => "array",
    ];

    protected $appends = ["formatted_old_values", "formatted_new_values"];

    // Accessor for human-friendly old values
    public function getFormattedOldValuesAttribute()
    {
        return $this->formatValues($this->old_values);
    }

    // Accessor for human-friendly new values
    public function getFormattedNewValuesAttribute()
    {
        return $this->formatValues($this->new_values);
    }

    private function formatValues($values)
    {
        if (is_array($values)) {
            $formattedValues = [];
            foreach ($values as $key => $value) {
                $formattedValues[] = ucfirst($key) . ": " . $value;
            }
            return implode(", ", $formattedValues);
        }
        return null; // Return null if values are not an array
    }

    public function user()
    {
        return $this->belongsTo(App\Models\User::class);
    }
}
