<?php

/**
 * Debug script to test header functionality
 * Place this in your Laravel application root and run: php test_header_debug.php
 */

// This script should be run from your Laravel application root, not the package directory
echo "=== Header Debug Test Script ===\n";
echo "To use this script:\n";
echo "1. Copy this file to your Laravel application root directory\n";
echo "2. Run: php test_header_debug.php\n";
echo "3. Check the Laravel logs for detailed header processing information\n\n";

echo "Example cURL command to test PDF generation with headers:\n\n";

$curlCommand = <<<'CURL'
curl -X POST http://your-app.test/ajax/pdf/generate-proposal \
  -H "Content-Type: application/json" \
  -H "Accept: application/json" \
  -d '{
    "proposal_data": {
      "customer_name": "Test Customer",
      "project_title": "Test Project"
    },
    "template_id": null,
    "branding_id": null,
    "header_config": {
      "enabled": true,
      "show_address": true,
      "show_phone": true,
      "show_website": true,
      "show_abn": true
    },
    "filename": "test-proposal-with-header.pdf",
    "download": false
  }'
CURL;

echo $curlCommand . "\n\n";

echo "Expected log entries to look for:\n";
echo "- PDFController::generateProposalPDF - Incoming request data\n";
echo "- PDFController::generateProposalPDF - Validation results\n";
echo "- PDFController::generateProposalPDF - Assembly config\n";
echo "- ProposalAssemblyService::assembleProposal - Incoming config\n";
echo "- ProposalAssemblyService::assembleHTML - Header config extraction\n";
echo "- ProposalAssemblyService::getHTMLHeader - Called\n";
echo "- ProposalAssemblyService::getHTMLHeader - Body class generation\n";
echo "- ProposalAssemblyService::generateProposalHeader - Called\n";
echo "- PDFController::generateProposalPDF - Header check\n\n";

echo "Common issues to check:\n";
echo "1. header_config is null or missing 'enabled' => true\n";
echo "2. header_config['enabled'] is false or not set\n";
echo "3. generateProposalHeader returns empty string\n";
echo "4. HTML doesn't contain 'proposal-header' class\n";
echo "5. Body doesn't have 'has-header' class\n\n";

echo "Check your Laravel logs (usually storage/logs/laravel.log) for these log entries.\n";