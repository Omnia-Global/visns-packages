<?php

namespace Visnsstudio\VisnsPackages\Models;

use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

use OwenIt\Auditing\Contracts\Auditable;

class File extends Model implements Auditable
{
    use \OwenIt\Auditing\Auditable;
    use HasFactory;

    protected $fillable = [
        "fileable_id",
        "fileable_field",
        "fileable_type",
        "file_path",
        "file_name",
        "file_extension",
        "file_size",
        "file_title",
        "file_description",
        "sort_order",
    ];

    protected $appends = ["file_url", "file_full_path"];

    protected function fileUrl(): Attribute
    {
        return Attribute::make(
            get: function ($value, $attributes) {
                $baseClassName = str_replace(
                    "App\\Models\\",
                    "",
                    $this->fileable_type
                );
                $plural = Str::plural($baseClassName);
                $singular = Str::singular($baseClassName);

                $possibleModels = [
                    lcfirst($plural),
                    ucfirst($plural),
                    lcfirst($singular),
                    ucfirst($singular),
                ];

                $model = lcfirst($plural);

                $path = $this->file_path;
                $fileableTypeExists = $this->fileable_type && $this->file_path;

                if ($fileableTypeExists) {
                    // Check if file_path already contains any form of the model name
                    $containsModelName = false;
                    foreach ($possibleModels as $possibleModel) {
                        if (
                            stripos($this->file_path, $possibleModel . "/") !==
                            false
                        ) {
                            $containsModelName = true;
                            break;
                        }
                    }

                    // Only prepend model if it does not already contain it
                    if (!$containsModelName) {
                        $path = $model . "/" . $this->file_path;
                    }
                }

                return env("CLOUDFRONT_URL")
                    ? env("CLOUDFRONT_URL") . $attributes["file_path"]
                    : Storage::temporaryUrl($path, now()->addMinutes(60));
            }
        );
    }

    protected function fileFullPath(): Attribute
    {
        return Attribute::make(
            get: function ($value, $attributes) {
                $baseClassName = str_replace(
                    "App\\Models\\",
                    "",
                    $this->fileable_type
                );
                $plural = Str::plural($baseClassName);
                $singular = Str::singular($baseClassName);

                $possibleModels = [
                    lcfirst($plural),
                    ucfirst($plural),
                    lcfirst($singular),
                    ucfirst($singular),
                ];

                $model = lcfirst($plural);

                $path = $this->file_path;
                $fileableTypeExists = $this->fileable_type && $this->file_path;

                if ($fileableTypeExists) {
                    // Check if file_path already contains any form of the model name
                    $containsModelName = false;
                    foreach ($possibleModels as $possibleModel) {
                        if (
                            stripos($this->file_path, $possibleModel . "/") !==
                            false
                        ) {
                            $containsModelName = true;
                            break;
                        }
                    }

                    // Only prepend model if it does not already contain it
                    if (!$containsModelName) {
                        $path = $model . "/" . $this->file_path;
                    }
                }

                return $path;
            }
        );
    }

    public function fileable()
    {
        return $this->morphTo();
    }

    public function scopeCustomOrder($query, $orderBy, $order)
    {
        if (isset($orderBy) && isset($order)) {
            $query->orderBy($orderBy, $order);
        }

        return $query;
    }
}
