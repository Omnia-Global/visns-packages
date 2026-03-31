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
    protected $maxAttemptsBeforeGiveUp = 10; // Stop after 10 failed attempts

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
        $variations = $this->generatePrioritizedPathVariations($file);
        $attemptedPaths = [];

        // Check only S3 first (most likely location)
        foreach ($variations as $path) {
            $attemptedPaths[] = $path;
            
            // Early termination if we've tried too many paths
            if (count($attemptedPaths) > $this->maxAttemptsBeforeGiveUp) {
                Log::debug("FilePathResolver: Early termination after {$this->maxAttemptsBeforeGiveUp} attempts for file {$file->id}");
                break;
            }
            
            $result = $this->checkPathExistsS3Only($path);
            if ($result) {
                Log::info("FilePathResolver: Found valid path for file {$file->id}", [
                    'file_id' => $file->id,
                    'valid_path' => $path,
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
            'total_attempts' => count($attemptedPaths)
        ]);

        return null;
    }

    protected function generatePrioritizedPathVariations(File $file): array
    {
        $modelName = $this->extractModelName($file->fileable_type);
        $fileName = $file->file_path;
        $variations = [];

        // If file_path already contains a folder structure, try it as-is first
        $variations[] = $fileName;

        // Generate only the most likely naming variations first
        $prioritizedNamingVariations = $this->getPrioritizedNamingVariations($modelName);
        $prioritizedStructures = $this->getPrioritizedPathStructures();
        
        foreach ($prioritizedNamingVariations as $variant) {
            foreach ($prioritizedStructures as $structure) {
                $path = $this->buildPath($structure, $variant, $fileName);
                if ($path && !in_array($path, $variations)) {
                    $variations[] = $path;
                }
            }
        }

        return array_unique($variations);
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

    protected function getPrioritizedNamingVariations(string $modelName): array
    {
        // Only the most common variations, in order of likelihood
        $variations = [
            // Most common Laravel patterns
            Str::snake(Str::plural($modelName)), // client_notes (most common)
            Str::camel(Str::plural($modelName)), // clientNotes 
            Str::snake($modelName),              // client_note
            Str::camel($modelName),              // clientNote
            $modelName,                          // ClientNote
            Str::plural($modelName),             // ClientNotes
        ];

        return array_values(array_unique($variations));
    }

    protected function getPrioritizedPathStructures(): array
    {
        return [
            '{filename}',                        // file.ext (no folder) - try first
            '{folder}/{filename}',               // clientNotes/file.ext - most common
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

    protected function checkPathExistsS3Only(string $path): ?array
    {
        $diskName = config('filesystems.default', 's3');

        try {
            if ($this->diskExists($diskName) && Storage::disk($diskName)->exists($path)) {
                $result = [
                    'disk' => $diskName,
                    'path' => $path
                ];

                // Generate URL if possible
                try {
                    $result['url'] = Storage::disk($diskName)->temporaryUrl($path, now()->addMinutes(60));
                } catch (\Exception $e) {
                    // Ignore URL generation errors
                }

                return $result;
            }
        } catch (\Exception $e) {
            // Ignore storage errors and continue
        }

        return null;
    }

    protected function getStorageDisks(): array
    {
        $default = config('filesystems.default', 's3');

        return [
            $default => ['priority' => 1],     // Primary storage (S3-compatible)
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