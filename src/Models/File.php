<?php

namespace Visnsstudio\VisnsPackages\Models;

use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Visnsstudio\VisnsPackages\Services\FilePathResolver;
use Visnsstudio\VisnsPackages\Traits\HasRelationshipSorting;

use OwenIt\Auditing\Contracts\Auditable;

class File extends Model implements Auditable
{
    use \OwenIt\Auditing\Auditable;
    use HasFactory, HasRelationshipSorting;

    protected $fillable = [
        'fileable_id',
        'fileable_field',
        'fileable_type',
        'file_path',
        'file_name',
        'file_extension',
        'file_size',
        'file_title',
        'file_description',
        'category_type',
        'subcategory',
        'sort_order',
        'is_public',
    ];

    protected $casts = [
        'file_size' => 'integer',
        'sort_order' => 'integer',
        'is_public' => 'boolean',
        'category_type' => 'string',
    ];

    protected $appends = ['file_url', 'file_full_path', 'file_exists'];

    protected function fileUrl(): Attribute
    {
        return Attribute::make(
            get: function ($value, $attributes) {
                // Use intelligent path resolver to find actual file path
                $resolver = app(FilePathResolver::class);
                $validatedResult = $resolver->resolve($this);

                if ($validatedResult && isset($validatedResult['url'])) {
                    // Return pre-generated URL from resolver
                    return $validatedResult['url'];
                }

                if ($validatedResult && isset($validatedResult['path'])) {
                    // Generate URL for validated path
                    try {
                        return Storage::disk($validatedResult['disk'])->temporaryUrl(
                            $validatedResult['path'], 
                            now()->addMinutes(60)
                        );
                    } catch (\Exception $e) {
                        \Log::warning("File model: Could not generate URL for validated path", [
                            'file_id' => $this->id,
                            'path' => $validatedResult['path'],
                            'disk' => $validatedResult['disk'],
                            'error' => $e->getMessage()
                        ]);
                    }
                }

                // Fallback: Check CloudFront URL
                if (env('CLOUDFRONT_URL')) {
                    return env('CLOUDFRONT_URL') . $this->file_path;
                }

                // Final fallback: Try to generate URL with original path
                try {
                    return Storage::disk('s3')->temporaryUrl($this->file_path, now()->addMinutes(60));
                } catch (\Exception $e) {
                    \Log::warning("File model: Could not generate fallback URL", [
                        'file_id' => $this->id,
                        'original_path' => $this->file_path,
                        'error' => $e->getMessage()
                    ]);
                    return null;
                }
            }
        );
    }

    protected function fileFullPath(): Attribute
    {
        return Attribute::make(
            get: function ($value, $attributes) {
                // Use intelligent path resolver to find actual file path
                $resolver = app(FilePathResolver::class);
                $validatedPath = $resolver->getValidatedFilePath($this);

                if ($validatedPath) {
                    return $validatedPath;
                }

                // Log that no valid path was found
                \Log::warning("File model: No valid path found for file {$this->id}, using fallback", [
                    'file_id' => $this->id,
                    'fileable_type' => $this->fileable_type,
                    'original_path' => $this->file_path
                ]);

                // Return the original file_path as stored in database
                // This prevents generating invalid paths like "clientNotes/file.ext"
                return $this->file_path;
            }
        );
    }

    public function fileable()
    {
        return $this->morphTo();
    }


    protected function fileExists(): Attribute
    {
        return Attribute::make(
            get: function () {
                $resolver = app(FilePathResolver::class);
                return $resolver->fileExists($this);
            }
        );
    }

    public function getValidatedPath(): ?array
    {
        $resolver = app(FilePathResolver::class);
        return $resolver->resolve($this);
    }

    public function refreshPathCache(): void
    {
        $resolver = app(FilePathResolver::class);
        $resolver->clearCache($this);
    }

    public function warmPathCache(): void
    {
        $resolver = app(FilePathResolver::class);
        $resolver->warmCache($this);
    }

    protected function generateFallbackPath(): string
    {
        $baseClassName = str_replace('App\\Models\\', '', $this->fileable_type);
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
                if (stripos($this->file_path, $possibleModel . '/') !== false) {
                    $containsModelName = true;
                    break;
                }
            }

            // Only prepend model if it does not already contain it
            if (!$containsModelName) {
                $path = $model . '/' . $this->file_path;
            }
        }

        return $path;
    }
}
