<?php

/**
 * Batch script to update all models with HasRelationshipSorting trait
 * This script adds the trait import, usage, and removes old scopeCustomOrder methods
 */

$modelsToUpdate = [
    'AgreementType', 'ClientInsurance', 'ContactType', 'Facility', 'FacilityType',
    'Insurance', 'Job', 'JobStatus', 'LeadNote', 'LeadStatus', 'LeadVariation',
    'Likelihood', 'OnsiteProjectStatus', 'Priority', 'ProjectStatus', 'Safebook',
    'SafebookCategory', 'Site', 'Source', 'Task', 'TaskStatus', 'User',
    'ClientAgreement', 'TourStatus', 'VisitRequestApprovedDwaStatus', 'TourFormat',
    'Tag', 'ContactNote', 'File', 'TourLocation', 'TourType', 'VisitRequestNote',
    'StakeholderCategory', 'StakeholderType', 'ClientNote'
];

$basePath = '/root/Projects/amc-crm/app/Models/';
$traitImport = 'use Visnsstudio\VisnsPackages\Traits\HasRelationshipSorting;';
$updatedModels = [];
$skippedModels = [];

foreach ($modelsToUpdate as $modelName) {
    $filePath = $basePath . $modelName . '.php';
    
    if (!file_exists($filePath)) {
        $skippedModels[] = $modelName . ' (file not found)';
        continue;
    }
    
    $content = file_get_contents($filePath);
    
    if (strpos($content, 'HasRelationshipSorting') !== false) {
        $skippedModels[] = $modelName . ' (already updated)';
        continue;
    }
    
    // Add import
    if (strpos($content, $traitImport) === false) {
        // Find the last use statement before the class declaration
        if (preg_match('/^use OwenIt\\\\Auditing\\\\Contracts\\\\Auditable;$/m', $content)) {
            $content = preg_replace(
                '/^(use OwenIt\\\\Auditing\\\\Contracts\\\\Auditable;)$/m',
                '$1' . "\n" . $traitImport,
                $content
            );
        } else {
            // If no Auditable import, find any use statement and add after
            $content = preg_replace(
                '/^(use [^;]+;)(?=\s*$)/m',
                '$1' . "\n" . $traitImport,
                $content,
                1
            );
        }
    }
    
    // Add trait usage - find existing use statements in class
    $content = preg_replace(
        '/(\s+use\s+[^;]+);(\s*$)/m',
        '$1, HasRelationshipSorting;$2',
        $content,
        1
    );
    
    // Remove scopeCustomOrder method
    $content = preg_replace(
        '/\s*public function scopeCustomOrder\(\$query, \$orderBy, \$order\)\s*\{[^}]*\}\s*/',
        "\n",
        $content
    );
    
    file_put_contents($filePath, $content);
    $updatedModels[] = $modelName;
}

echo "Updated models:\n";
foreach ($updatedModels as $model) {
    echo "✅ $model\n";
}

echo "\nSkipped models:\n";
foreach ($skippedModels as $model) {
    echo "⏭️ $model\n";
}

echo "\nTotal updated: " . count($updatedModels) . "\n";
echo "Total skipped: " . count($skippedModels) . "\n";