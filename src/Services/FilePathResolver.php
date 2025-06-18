<?php

namespace Visnsstudio\VisnsPackages\Services;

use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Visnsstudio\VisnsPackages\Models\File;

class FilePathResolver
{
    protected $cachePrefix = 'file_path_resolver:';
    protected $cacheTtl = 3600; // 1 hour

    public function resolve(File $file): ?array
    {
        $cacheKey = $this->cachePrefix . $file->id;
        
        return Cache::remember($cacheKey, $this->cacheTtl, function () use ($file) {
            return $this->findValidPath($file);
        });
    }

    public function getValidatedFilePath(File $file): ?string
    {
        $result = $this->resolve($file);
        return $result ? $result['path'] : null;
    }

    public function fileExists(File $file): bool
    {
        return $this->resolve($file) !== null;
    }

    protected function findValidPath(File $file): ?array
    {
        $variations = $this->generateAllPathVariations($file);
        $attemptedPaths = [];

        foreach ($variations as $path) {
            $attemptedPaths[] = $path;
            
            $result = $this->checkPathExists($path);
            if ($result) {
                Log::info("FilePathResolver: Found valid path for file {$file->id}", [
                    'file_id' => $file->id,
                    'valid_path' => $path,
                    'storage_disk' => $result['disk'],
                    'attempts' => count($attemptedPaths)
                ]);
                
                return [
                    'path' => $path,
                    'disk' => $result['disk'],
                    'full_url' => $result['url'] ?? null
                ];
            }
        }

        Log::warning("FilePathResolver: No valid path found for file {$file->id}", [
            'file_id' => $file->id,
            'fileable_type' => $file->fileable_type,
            'original_path' => $file->file_path,
            'attempted_paths' => $attemptedPaths,
            'total_attempts' => count($attemptedPaths)
        ]);

        return null;
    }

    protected function generateAllPathVariations(File $file): array
    {
        $modelName = $this->extractModelName($file->fileable_type);
        $fileName = $file->file_path;
        $variations = [];

        // If file_path already contains a folder structure, try it as-is first
        $variations[] = $fileName;

        // Generate all naming variations
        $namingVariations = $this->getNamingVariations($modelName);
        
        foreach ($namingVariations as $variant) {
            // Generate all path structure variations
            foreach ($this->getPathStructures() as $structure) {
                $path = $this->buildPath($structure, $variant, $fileName);
                if ($path && !in_array($path, $variations)) {
                    $variations[] = $path;
                }
            }
        }

        // Prioritize variations (most likely patterns first)
        return $this->prioritizeVariations(array_unique($variations));
    }

    protected function extractModelName(?string $fileableType): string
    {
        if (!$fileableType) {
            return 'file';
        }

        // Remove namespace and get base class name
        $baseClassName = class_basename($fileableType);
        
        // Handle common model naming patterns
        return $baseClassName;
    }

    protected function getNamingVariations(string $modelName): array
    {
        $variations = [
            // Original forms
            $modelName,                          // ClientNote
            Str::plural($modelName),             // ClientNotes
            Str::singular($modelName),           // ClientNote
            
            // camelCase variations
            Str::camel($modelName),              // clientNote
            Str::camel(Str::plural($modelName)), // clientNotes
            Str::camel(Str::singular($modelName)), // clientNote
            
            // snake_case variations
            Str::snake($modelName),              // client_note
            Str::snake(Str::plural($modelName)), // client_notes
            Str::snake(Str::singular($modelName)), // client_note
            
            // StudlyCase variations
            Str::studly($modelName),             // ClientNote
            Str::studly(Str::plural($modelName)), // ClientNotes
            Str::studly(Str::singular($modelName)), // ClientNote
            
            // kebab-case variations
            Str::kebab($modelName),              // client-note
            Str::kebab(Str::plural($modelName)), // client-notes
            Str::kebab(Str::singular($modelName)), // client-note
            
            // lowercase variations
            strtolower($modelName),              // clientnote
            strtolower(Str::plural($modelName)), // clientnotes
            strtolower(Str::singular($modelName)), // clientnote
            
            // UPPERCASE variations
            strtoupper($modelName),              // CLIENTNOTE
            strtoupper(Str::plural($modelName)), // CLIENTNOTES
            strtoupper(Str::singular($modelName)), // CLIENTNOTE
        ];

        // Remove duplicates while preserving order
        return array_values(array_unique($variations));
    }

    protected function getPathStructures(): array
    {
        return [
            '{folder}/{filename}',               // clientNotes/file.ext
            '{filename}',                        // file.ext (no folder)
            'files/{folder}/{filename}',         // files/clientNotes/file.ext
            'uploads/{folder}/{filename}',       // uploads/clientNotes/file.ext
            'storage/{folder}/{filename}',       // storage/clientNotes/file.ext
            'attachments/{folder}/{filename}',   // attachments/clientNotes/file.ext
            'documents/{folder}/{filename}',     // documents/clientNotes/file.ext
        ];
    }

    protected function buildPath(string $structure, string $folderName, string $fileName): string
    {
        // Extract filename without any existing folder structure
        $cleanFileName = basename($fileName);
        
        // Handle case where filename already contains folder structure
        if (strpos($fileName, '/') !== false) {
            $pathParts = explode('/', $fileName);
            $cleanFileName = end($pathParts);
        }

        return str_replace(
            ['{folder}', '{filename}'],
            [$folderName, $cleanFileName],
            $structure
        );
    }

    protected function prioritizeVariations(array $variations): array
    {
        // Define priority patterns (most common first)
        $priorityPatterns = [
            // Direct database path (highest priority)
            function($path) { return !str_contains($path, '/'); },
            
            // snake_case plural (common Laravel convention)
            function($path) { return preg_match('/^[a-z_]+\//', $path); },
            
            // camelCase patterns
            function($path) { return preg_match('/^[a-z][a-zA-Z]+\//', $path); },
            
            // StudlyCase patterns
            function($path) { return preg_match('/^[A-Z][a-zA-Z]+\//', $path); },
            
            // Nested folder patterns
            function($path) { return substr_count($path, '/') > 1; },
        ];

        $prioritized = [];
        $remaining = $variations;

        // Apply priority patterns
        foreach ($priorityPatterns as $pattern) {
            $matched = array_filter($remaining, $pattern);
            $prioritized = array_merge($prioritized, $matched);
            $remaining = array_diff($remaining, $matched);
        }

        // Add any remaining variations
        $prioritized = array_merge($prioritized, $remaining);

        return array_values(array_unique($prioritized));
    }

    protected function checkPathExists(string $path): ?array
    {
        $storageDisks = $this->getStorageDisks();
        
        foreach ($storageDisks as $diskName => $config) {
            try {
                if ($this->diskExists($diskName) && Storage::disk($diskName)->exists($path)) {
                    $result = [
                        'disk' => $diskName,
                        'path' => $path
                    ];
                    
                    // Generate URL if possible
                    if ($diskName === 's3' && method_exists(Storage::disk($diskName), 'temporaryUrl')) {
                        try {
                            $result['url'] = Storage::disk($diskName)->temporaryUrl($path, now()->addMinutes(60));
                        } catch (\Exception $e) {
                            Log::debug("FilePathResolver: Could not generate temporary URL", [
                                'path' => $path,
                                'disk' => $diskName,
                                'error' => $e->getMessage()
                            ]);
                        }
                    }
                    
                    return $result;
                }
            } catch (\Exception $e) {
                Log::debug("FilePathResolver: Error checking path on disk {$diskName}", [
                    'path' => $path,
                    'disk' => $diskName,
                    'error' => $e->getMessage()
                ]);
                continue;
            }
        }

        return null;
    }

    protected function getStorageDisks(): array
    {
        return [
            's3' => ['priority' => 1],          // Primary S3 storage
            'public' => ['priority' => 2],      // Public storage
            'local' => ['priority' => 3],       // Local storage
        ];
    }

    protected function diskExists(string $diskName): bool
    {
        try {
            $config = config("filesystems.disks.{$diskName}");
            return !empty($config);
        } catch (\Exception $e) {
            return false;
        }
    }

    public function clearCache(File $file): void
    {
        $cacheKey = $this->cachePrefix . $file->id;
        Cache::forget($cacheKey);
    }

    public function warmCache(File $file): void
    {
        $this->clearCache($file);
        $this->resolve($file);
    }
}