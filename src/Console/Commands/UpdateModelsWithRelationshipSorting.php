<?php

namespace Visnsstudio\VisnsPackages\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use ReflectionClass;
use Illuminate\Database\Eloquent\Model;

class UpdateModelsWithRelationshipSorting extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'visns:update-models-sorting 
                            {--path=app/Models : The path to scan for models}
                            {--namespace=App\\Models : The namespace to use for models}
                            {--dry-run : Preview changes without applying them}
                            {--backup : Create backup files before modifying}
                            {--force : Skip confirmation prompts}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Update all discovered models to use the HasRelationshipSorting trait';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $path = $this->option('path');
        $namespace = $this->option('namespace');
        $dryRun = $this->option('dry-run');
        $backup = $this->option('backup');
        $force = $this->option('force');

        $this->info('🔍 Discovering models in your project...');
        
        $models = $this->discoverModels($path, $namespace);
        
        if (empty($models)) {
            $this->warn("No models found in {$path}");
            return;
        }

        $this->info("Found " . count($models) . " models:");
        foreach ($models as $model) {
            $this->line("  - {$model['class']} ({$model['file']})");
        }

        $modelsToUpdate = $this->analyzeModels($models);
        
        if (empty($modelsToUpdate)) {
            $this->info('✅ All models are already using the HasRelationshipSorting trait!');
            return;
        }

        $this->info("\n📝 Models that need updating:");
        foreach ($modelsToUpdate as $model) {
            $status = $this->getModelStatus($model);
            $this->line("  - {$model['class']} {$status}");
        }

        if ($dryRun) {
            $this->info("\n🔍 Dry run mode - showing what would be changed:");
            foreach ($modelsToUpdate as $model) {
                $this->showPreview($model);
            }
            return;
        }

        if (!$force && !$this->confirm("\nDo you want to update these models?")) {
            $this->info('Operation cancelled.');
            return;
        }

        $this->info("\n🚀 Updating models...");
        
        $successful = 0;
        $failed = 0;

        foreach ($modelsToUpdate as $model) {
            try {
                if ($backup) {
                    $this->createBackup($model['file']);
                }
                
                $this->updateModel($model);
                $this->info("  ✅ Updated {$model['class']}");
                $successful++;
            } catch (\Exception $e) {
                $this->error("  ❌ Failed to update {$model['class']}: " . $e->getMessage());
                $failed++;
            }
        }

        $this->info("\n📊 Summary:");
        $this->info("  ✅ Successfully updated: {$successful}");
        if ($failed > 0) {
            $this->error("  ❌ Failed to update: {$failed}");
        }
        
        if ($successful > 0) {
            $this->info("\n🎉 Models have been updated to use HasRelationshipSorting trait!");
            $this->info("You can now sort by relationship fields using dot notation (e.g., 'user.profile.name')");
        }
    }

    /**
     * Discover all model files in the specified path
     */
    protected function discoverModels(string $path, string $namespace): array
    {
        $models = [];
        $fullPath = base_path($path);

        if (!File::exists($fullPath)) {
            return $models;
        }

        $files = File::allFiles($fullPath);

        foreach ($files as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }

            $relativePath = $file->getRelativePathname();
            $className = str_replace(['/', '.php'], ['\\', ''], $relativePath);
            $fullClassName = $namespace . '\\' . $className;

            // Skip if class doesn't exist
            if (!class_exists($fullClassName)) {
                continue;
            }

            try {
                $reflection = new ReflectionClass($fullClassName);
                
                // Skip abstract classes and interfaces
                if ($reflection->isAbstract() || $reflection->isInterface()) {
                    continue;
                }

                // Check if it's a Model
                if ($reflection->isSubclassOf(Model::class)) {
                    $models[] = [
                        'class' => $fullClassName,
                        'file' => $file->getRealPath(),
                        'reflection' => $reflection
                    ];
                }
            } catch (\Exception $e) {
                // Skip files that can't be reflected
                continue;
            }
        }

        return $models;
    }

    /**
     * Analyze models to determine which need updating
     */
    protected function analyzeModels(array $models): array
    {
        $needsUpdate = [];

        foreach ($models as $model) {
            $fileContent = File::get($model['file']);
            
            // Check if already has the trait (with or without leading backslash)
            $hasTraitImport = str_contains($fileContent, 'use Visnsstudio\\VisnsPackages\\Traits\\HasRelationshipSorting;') || 
                              str_contains($fileContent, 'use \\Visnsstudio\\VisnsPackages\\Traits\\HasRelationshipSorting;');
            $hasTraitUsage = preg_match('/use\s+[^;]*HasRelationshipSorting[^;]*;/s', $fileContent);
            $hasOldMethod = str_contains($fileContent, 'public function scopeCustomOrder($query, $orderBy, $order)');
            
            // Check for malformed trait usage (like in the error case)
            $hasMalformedUsage = preg_match('/use\s+[^;]*,\s*HasRelationshipSorting[^;]*;/s', $fileContent) && !$hasTraitImport;

            if (!$hasTraitImport || !$hasTraitUsage || $hasOldMethod || $hasMalformedUsage) {
                $model['needs_import'] = !$hasTraitImport;
                $model['needs_usage'] = !$hasTraitUsage || $hasMalformedUsage;
                $model['needs_removal'] = $hasOldMethod;
                $model['needs_cleanup'] = $hasMalformedUsage;
                $model['file_content'] = $fileContent;
                $needsUpdate[] = $model;
            }
        }

        return $needsUpdate;
    }

    /**
     * Get status message for a model
     */
    protected function getModelStatus(array $model): string
    {
        $status = [];
        
        if ($model['needs_import']) {
            $status[] = 'needs trait import';
        }
        
        if ($model['needs_usage']) {
            $status[] = 'needs trait usage fix';
        }
        
        if ($model['needs_removal']) {
            $status[] = 'has old scopeCustomOrder method';
        }
        
        if (isset($model['needs_cleanup']) && $model['needs_cleanup']) {
            $status[] = 'needs malformed trait cleanup';
        }

        return '(' . implode(', ', $status) . ')';
    }

    /**
     * Show preview of changes for dry run
     */
    protected function showPreview(array $model): void
    {
        $this->line("\n📄 {$model['class']}:");
        
        if ($model['needs_import']) {
            $this->line("  + Add import: use \\Visnsstudio\\VisnsPackages\\Traits\\HasRelationshipSorting;");
        }
        
        if ($model['needs_usage']) {
            $this->line("  + Fix trait usage in class");
        }
        
        if ($model['needs_removal']) {
            $this->line("  - Remove old scopeCustomOrder method");
        }
        
        if (isset($model['needs_cleanup']) && $model['needs_cleanup']) {
            $this->line("  ! Clean up malformed trait usage");
        }
    }

    /**
     * Create backup of the model file
     */
    protected function createBackup(string $filePath): void
    {
        $backupPath = $filePath . '.backup.' . date('Y-m-d-H-i-s');
        File::copy($filePath, $backupPath);
    }

    /**
     * Update a model file
     */
    protected function updateModel(array $model): void
    {
        $content = $model['file_content'];
        
        // Clean up malformed trait usage first
        if (isset($model['needs_cleanup']) && $model['needs_cleanup']) {
            $content = $this->cleanupMalformedTraitUsage($content);
        }
        
        // Remove old scopeCustomOrder method if needed
        if ($model['needs_removal']) {
            $content = $this->removeOldScopeMethod($content);
        }
        
        // Add trait import if needed
        if ($model['needs_import']) {
            $content = $this->addTraitImport($content);
        }
        
        // Add trait usage if needed
        if ($model['needs_usage']) {
            $content = $this->addTraitUsage($content);
        }
        
        File::put($model['file'], $content);
    }

    /**
     * Add trait import to the file
     */
    protected function addTraitImport(string $content): string
    {
        // Find the last use statement
        $lines = explode("\n", $content);
        $lastUseIndex = -1;
        $indentation = '';
        
        foreach ($lines as $index => $line) {
            if (str_starts_with(trim($line), 'use ') && !str_contains($line, ' as ') && str_ends_with(trim($line), ';')) {
                $lastUseIndex = $index;
                // Capture the indentation from existing use statements
                $indentation = str_replace(trim($line), '', $line);
            }
        }
        
        // Create the new import line with proper indentation and leading backslash
        $newImport = $indentation . 'use \\Visnsstudio\\VisnsPackages\\Traits\\HasRelationshipSorting;';
        
        if ($lastUseIndex >= 0) {
            // Add after the last use statement
            array_splice($lines, $lastUseIndex + 1, 0, $newImport);
        } else {
            // Add after namespace declaration, detect indentation from nearby lines
            foreach ($lines as $index => $line) {
                if (str_starts_with(trim($line), 'namespace ')) {
                    // Look for indentation pattern in the file
                    for ($i = $index + 1; $i < count($lines) && $i < $index + 10; $i++) {
                        if (!empty(trim($lines[$i])) && str_starts_with($lines[$i], ' ') || str_starts_with($lines[$i], "\t")) {
                            $indentation = str_replace(trim($lines[$i]), '', $lines[$i]);
                            break;
                        }
                    }
                    $newImport = $indentation . 'use \\Visnsstudio\\VisnsPackages\\Traits\\HasRelationshipSorting;';
                    array_splice($lines, $index + 2, 0, $newImport);
                    break;
                }
            }
        }
        
        return implode("\n", $lines);
    }

    /**
     * Add trait usage to the class
     */
    protected function addTraitUsage(string $content): string
    {
        // Check if HasRelationshipSorting is already properly used
        if (preg_match('/use\s+[^;]*HasRelationshipSorting[^;]*;/s', $content)) {
            return $content; // Already has proper trait usage
        }
        
        // Find existing trait usage pattern
        if (preg_match('/class\s+\w+[^{]*\{\s*([^}]*?use\s+[^;]+;)/s', $content, $matches)) {
            $useBlock = $matches[1];
            
            // Check if there are multiple use statements in the class
            if (preg_match_all('/use\s+([^;]+);/s', $useBlock, $useMatches)) {
                $lastUseStatement = end($useMatches[0]);
                $newUseStatement = "    use HasRelationshipSorting;";
                
                // Add the new trait usage after the last use statement
                $content = str_replace($lastUseStatement, $lastUseStatement . "\n" . $newUseStatement, $content);
            }
        } else {
            // No existing traits, add new use statement after class opening brace
            $content = preg_replace('/class\s+\w+[^{]*\{/', "$0\n    use HasRelationshipSorting;\n", $content);
        }
        
        return $content;
    }

    /**
     * Clean up malformed trait usage
     */
    protected function cleanupMalformedTraitUsage(string $content): string
    {
        // Remove malformed trait usage like "use \OwenIt\Auditing\Auditable, HasRelationshipSorting;"
        $content = preg_replace('/use\s+([^;]*),\s*HasRelationshipSorting([^;]*);/s', 'use $1$2;', $content);
        
        // Remove standalone "HasRelationshipSorting" without proper import
        $content = preg_replace('/use\s+HasRelationshipSorting\s*;/s', '', $content);
        
        // Clean up extra whitespace and newlines
        $content = preg_replace('/\n{3,}/', "\n\n", $content);
        
        return $content;
    }

    /**
     * Remove old scopeCustomOrder method
     */
    protected function removeOldScopeMethod(string $content): string
    {
        // Use regex to match the complete scopeCustomOrder method with proper brace matching
        $pattern = '/\s*public\s+function\s+scopeCustomOrder\s*\([^)]*\)\s*\{[^{}]*(?:\{[^{}]*\}[^{}]*)*\}\s*/s';
        
        // First try the precise regex pattern
        $content = preg_replace($pattern, "\n", $content);
        
        // If that didn't work, try a more comprehensive approach
        if (str_contains($content, 'scopeCustomOrder')) {
            // Split into lines and manually remove the method
            $lines = explode("\n", $content);
            $newLines = [];
            $inScopeMethod = false;
            $braceDepth = 0;
            $startedMethod = false;
            
            foreach ($lines as $line) {
                $trimmedLine = trim($line);
                
                // Check if we're starting the scopeCustomOrder method
                if (!$inScopeMethod && str_contains($trimmedLine, 'public function scopeCustomOrder(')) {
                    $inScopeMethod = true;
                    $startedMethod = true;
                    $braceDepth = 0;
                    
                    // Check if the opening brace is on the same line
                    if (str_contains($line, '{')) {
                        $braceDepth += substr_count($line, '{') - substr_count($line, '}');
                        if ($braceDepth <= 0) {
                            $inScopeMethod = false; // Method completed on same line
                        }
                    }
                    continue; // Skip the method declaration line
                }
                
                // If we're inside the method
                if ($inScopeMethod) {
                    // Count braces to find the end
                    $braceDepth += substr_count($line, '{') - substr_count($line, '}');
                    
                    // If we've balanced all braces, the method is complete
                    if ($braceDepth <= 0) {
                        $inScopeMethod = false;
                    }
                    continue; // Skip all lines inside the method
                }
                
                // Keep lines that are not part of the scopeCustomOrder method
                $newLines[] = $line;
            }
            
            $content = implode("\n", $newLines);
        }
        
        // Clean up extra newlines
        $content = preg_replace('/\n{3,}/', "\n\n", $content);
        
        return $content;
    }
}