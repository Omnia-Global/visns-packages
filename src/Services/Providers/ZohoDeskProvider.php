<?php

namespace Visnsstudio\VisnsPackages\Services\Providers;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class ZohoDeskProvider extends AbstractOAuthProvider
{
    protected function getProviderName(): string
    {
        return 'zoho_desk';
    }

    protected function getProviderDisplayName(): string
    {
        return 'Zoho Desk';
    }

    protected function getBaseApiUrl(): string
    {
        $dataCenter = $this->getConfig('data_center', 'com');
        return "https://desk.zoho.{$dataCenter}/api/v1";
    }

    protected function getAuthUrl(): string
    {
        $dataCenter = $this->getConfig('data_center', 'com');
        return "https://accounts.zoho.{$dataCenter}/oauth/v2/auth";
    }

    protected function getTokenUrl(): string
    {
        $dataCenter = $this->getConfig('data_center', 'com');
        return "https://accounts.zoho.{$dataCenter}/oauth/v2/token";
    }

    public function getScopes(): array
    {
        return [
            'Desk.contacts.READ',
            'Desk.basic.READ',
            'Desk.search.READ',
        ];
    }



    public function getAuthorizationUrl(string $state): string
    {
        $params = [
            'scope' => implode(',', $this->getScopes()),
            'client_id' => $this->getClientId(),
            'response_type' => 'code',
            'redirect_uri' => $this->getRedirectUri(),
            'access_type' => 'offline',
            'state' => $state,
        ];

        return $this->getAuthUrl() . '?' . http_build_query($params);
    }

    public function exchangeCodeForTokens(string $code): ?array
    {
        try {
            $response = Http::asForm()->post($this->getTokenUrl(), [
                'grant_type' => 'authorization_code',
                'client_id' => $this->getClientId(),
                'client_secret' => $this->getClientSecret(),
                'redirect_uri' => $this->getRedirectUri(),
                'code' => $code,
            ]);

            if ($response->successful()) {
                return $response->json();
            }

            Log::error('Zoho Desk token exchange failed', [
                'status' => $response->status(),
                'response' => $response->body(),
            ]);

            return null;
        } catch (\Exception $e) {
            Log::error('Zoho Desk token exchange exception', [
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    public function refreshToken(string $refreshToken): ?array
    {
        try {
            $response = Http::asForm()->post($this->getTokenUrl(), [
                'refresh_token' => $refreshToken,
                'client_id' => $this->getClientId(),
                'client_secret' => $this->getClientSecret(),
                'grant_type' => 'refresh_token',
            ]);

            if ($response->successful()) {
                return $response->json();
            }

            Log::error('Zoho Desk token refresh failed', [
                'status' => $response->status(),
                'response' => $response->body(),
            ]);

            return null;
        } catch (\Exception $e) {
            Log::error('Zoho Desk token refresh exception', [
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    public function testConnection(): bool
    {
        try {
            $response = $this->makeApiRequest('GET', 'contacts', ['limit' => 1]);
            return isset($response['data']);
        } catch (\Exception $e) {
            return false;
        }
    }

    protected function getAdditionalHeaders(): array
    {
        $orgId = $this->getConfig('org_id');
        return $orgId ? ['orgId' => $orgId] : [];
    }

    public function syncData(string $dataType, array $options = []): array
    {
        // Check if this is a preview request or actual sync
        $isPreview = $options['preview'] ?? false;
        
        if ($isPreview) {
            // Just fetch data for preview
            switch ($dataType) {
                case 'contacts':
                    return $this->syncContacts($options);
                case 'accounts':
                    return $this->syncAccounts($options);
                default:
                    throw new \Exception("Unsupported data type: {$dataType}");
            }
        } else {
            // Perform actual database sync
            $records = $options['records'] ?? [];
            $skippedRecords = $options['skipped_records'] ?? [];
            
            // Filter out skipped records
            $recordsToSync = array_filter($records, function($record) use ($skippedRecords) {
                $recordId = $record['id'] ?? null;
                return $recordId && !in_array($recordId, $skippedRecords);
            });
            
            $result = $this->performSync($dataType, $recordsToSync, $options);
            
            return [
                'success' => $result['success'],
                'message' => sprintf(
                    'Sync completed: %d created, %d updated, %d linked, %d skipped',
                    $result['created'],
                    $result['updated'], 
                    $result['linked'],
                    $result['skipped']
                ),
                'result' => $result,
                'total' => $result['created'] + $result['updated'] + $result['linked'],
            ];
        }
    }

    protected function syncContacts(array $options = []): array
    {
        $limit = $options['limit'] ?? 100;
        $from = $options['from'] ?? 0;
        
        try {
            $response = $this->makeApiRequest('GET', 'contacts', [
                'limit' => $limit,
                'from' => $from,
                'sortBy' => 'modifiedTime',
            ]);

            return [
                'type' => 'contacts',
                'total' => count($response['data'] ?? []),
                'data' => $response['data'] ?? [],
                'has_more' => count($response['data'] ?? []) === $limit,
                'next_from' => $from + $limit,
            ];
        } catch (\Exception $e) {
            throw new \Exception("Failed to sync contacts: {$e->getMessage()}");
        }
    }

    protected function syncAccounts(array $options = []): array
    {
        $limit = $options['limit'] ?? 100;
        $from = $options['from'] ?? 0;
        
        try {
            $response = $this->makeApiRequest('GET', 'accounts', [
                'limit' => $limit,
                'from' => $from,
                'sortBy' => 'modifiedTime',
            ]);

            return [
                'type' => 'accounts',
                'total' => count($response['data'] ?? []),
                'data' => $response['data'] ?? [],
                'has_more' => count($response['data'] ?? []) === $limit,
                'next_from' => $from + $limit,
            ];
        } catch (\Exception $e) {
            throw new \Exception("Failed to sync accounts: {$e->getMessage()}");
        }
    }

    /**
     * Get specific contact by ID
     */
    public function getContact(string $contactId): ?array
    {
        try {
            return $this->makeApiRequest('GET', "contacts/{$contactId}");
        } catch (\Exception $e) {
            Log::warning("Failed to get Zoho Desk contact {$contactId}", [
                'error' => $e->getMessage(),
            ]);
            return null;
        }
    }

    /**
     * Get specific account by ID
     */
    public function getAccount(string $accountId): ?array
    {
        try {
            return $this->makeApiRequest('GET', "accounts/{$accountId}");
        } catch (\Exception $e) {
            Log::warning("Failed to get Zoho Desk account {$accountId}", [
                'error' => $e->getMessage(),
            ]);
            return null;
        }
    }

    /**
     * Search contacts by email
     */
    public function searchContactsByEmail(string $email): ?array
    {
        try {
            return $this->makeApiRequest('GET', 'contacts/search', ['email' => $email]);
        } catch (\Exception $e) {
            Log::warning("Failed to search Zoho Desk contacts by email {$email}", [
                'error' => $e->getMessage(),
            ]);
            return null;
        }
    }

    /**
     * Search accounts by name
     */
    public function searchAccountsByName(string $name): ?array
    {
        try {
            return $this->makeApiRequest('GET', 'accounts/search', ['accountName' => $name]);
        } catch (\Exception $e) {
            Log::warning("Failed to search Zoho Desk accounts by name {$name}", [
                'error' => $e->getMessage(),
            ]);
            return null;
        }
    }

    /**
     * Get syncable data types for this provider
     */
    public function getSyncableDataTypes(): array
    {
        return [
            'contacts' => [
                'name' => 'Contacts',
                'description' => 'Sync contacts from Zoho Desk',
                'sync_direction' => 'pull',
                'validation_required' => true,
                'preview_available' => true,
                'estimated_count' => $this->getEstimatedContactCount(),
            ],
            'accounts' => [
                'name' => 'Accounts',
                'description' => 'Sync accounts/organizations from Zoho Desk',
                'sync_direction' => 'pull',
                'validation_required' => true,
                'preview_available' => true,
                'estimated_count' => $this->getEstimatedAccountCount(),
            ],
        ];
    }

    /**
     * Get configuration requirements for setup
     */
    public function getConfigRequirements(): array
    {
        return [
            'client_id' => [
                'label' => 'Client ID',
                'type' => 'text',
                'required' => true,
                'description' => 'Zoho Desk OAuth Client ID',
            ],
            'client_secret' => [
                'label' => 'Client Secret',
                'type' => 'password',
                'required' => true,
                'description' => 'Zoho Desk OAuth Client Secret',
            ],
            'org_id' => [
                'label' => 'Organization ID',
                'type' => 'text',
                'required' => true,
                'description' => 'Your Zoho Desk Organization ID',
            ],
            'data_center' => [
                'label' => 'Data Center',
                'type' => 'select',
                'required' => true,
                'options' => [
                    'com' => 'United States (zoho.com)',
                    'com.au' => 'Australia (zoho.com.au)',
                    'eu' => 'Europe (zoho.eu)',
                    'in' => 'India (zoho.in)',
                ],
                'default' => 'com.au',
                'description' => 'Your Zoho data center location',
            ],
        ];
    }

    /**
     * Preview data before sync (with validation)
     */
    public function previewData(string $dataType, array $options = []): array
    {
        $limit = min($options['limit'] ?? 50, 100); // Support higher limits for preview
        
        try {
            // Fetch data from Zoho Desk
            switch ($dataType) {
                case 'contacts':
                    $zohoDeskData = $this->fetchContacts(['from' => 0, 'limit' => $limit]);
                    break;
                case 'accounts':
                    $zohoDeskData = $this->fetchAccounts(['from' => 0, 'limit' => $limit]);
                    break;
                default:
                    throw new \Exception("Unknown data type: {$dataType}");
            }

            // Process records and determine sync status
            $records = [];
            $newRecords = 0;
            $updates = 0;
            $skipped = 0;

            foreach ($zohoDeskData['data'] ?? [] as $zData) {
                $record = $this->processRecordForPreview($zData, $dataType);
                $records[] = $record;
                
                // Count by sync status
                switch ($record['sync_status']) {
                    case 'new':
                        $newRecords++;
                        break;
                    case 'update':
                        $updates++;
                        break;
                    case 'skip':
                        $skipped++;
                        break;
                }
            }

            return [
                'success' => true,
                'data_type' => $dataType,
                'records' => $records,
                'summary' => [
                    'new_records' => $newRecords,
                    'updates' => $updates,
                    'skipped' => $skipped,
                    'total_changes' => $newRecords + $updates,
                    'total_estimated' => isset($zohoDeskData['info']['moreRecords']) ? 'Many more...' : count($records)
                ],
                'preview_id' => uniqid('preview_'),
                'validation_issues' => $this->validateData($zohoDeskData['data'] ?? [], $dataType),
            ];
        } catch (\Exception $e) {
            Log::error('Zoho Desk preview failed', [
                'data_type' => $dataType,
                'options' => $options,
                'error' => $e->getMessage(),
            ]);

            return [
                'success' => false,
                'error' => $e->getMessage(),
                'data_type' => $dataType,
            ];
        }
    }

    /**
     * Fetch contacts directly from Zoho Desk API
     */
    private function fetchContacts(array $options = []): array
    {
        $from = $options['from'] ?? 0;
        $limit = $options['limit'] ?? 20;
        
        $response = $this->makeApiRequest('GET', 'contacts', [
            'from' => $from,
            'limit' => $limit,
        ]);

        return [
            'data' => $response['data'] ?? [],
            'info' => $response['info'] ?? [],
        ];
    }

    /**
     * Fetch accounts directly from Zoho Desk API  
     */
    private function fetchAccounts(array $options = []): array
    {
        $from = $options['from'] ?? 0;
        $limit = $options['limit'] ?? 20;
        
        $response = $this->makeApiRequest('GET', 'accounts', [
            'from' => $from,
            'limit' => $limit,
        ]);

        return [
            'data' => $response['data'] ?? [],
            'info' => $response['info'] ?? [],
        ];
    }

    /**
     * Process individual record for preview, determining sync status
     */
    private function processRecordForPreview(array $zohoDeskData, string $dataType): array
    {
        // Check if record already exists in CRM
        $existingRecord = null;
        $syncStatus = 'new';
        $changes = [];

        if ($dataType === 'contacts') {
            // Look for existing contact by Zoho Desk ID first (most reliable), then email
            $email = $zohoDeskData['email'] ?? null;
            $zohoDeskId = $zohoDeskData['id'] ?? null;
            
            // Priority: Zoho ID first (for linked records), then email matching
            if ($zohoDeskId) {
                $existingRecord = \App\Models\Contact::where('zoho_desk_id', $zohoDeskId)->first();
            }
            
            if (!$existingRecord && $email) {
                $existingRecord = \App\Models\Contact::where('email', $email)->first();
            }
                
            if ($existingRecord) {
                $syncStatus = 'update';
                $changes = $this->calculateContactChanges($existingRecord, $zohoDeskData);
                
                // If no significant changes, mark as skip
                if (empty($changes)) {
                    $syncStatus = 'skip';
                }
            }
            
            $displayName = trim(($zohoDeskData['firstName'] ?? '') . ' ' . ($zohoDeskData['lastName'] ?? '')) ?: ($zohoDeskData['email'] ?? 'Contact');
        } elseif ($dataType === 'accounts') {
            // Look for existing account by Zoho Desk ID first (most reliable), then name
            $accountName = $zohoDeskData['accountName'] ?? null;
            $zohoDeskId = $zohoDeskData['id'] ?? null;
            
            // Priority: Zoho ID first (for linked records), then name matching
            if ($zohoDeskId) {
                $existingRecord = \App\Models\Customer::where('zoho_desk_account_id', $zohoDeskId)->first();
            }
            
            if (!$existingRecord && $accountName) {
                $existingRecord = \App\Models\Customer::where('name', $accountName)->first();
            }
                
            if ($existingRecord) {
                $syncStatus = 'update';
                $changes = $this->calculateAccountChanges($existingRecord, $zohoDeskData);
                
                if (empty($changes)) {
                    $syncStatus = 'skip';
                }
            }
            
            $displayName = $zohoDeskData['accountName'] ?? 'Account';
        }

        return [
            'id' => $zohoDeskData['id'] ?? uniqid(),
            'sync_status' => $syncStatus,
            'display_name' => $displayName,
            'data' => $zohoDeskData,
            'existing_data' => $existingRecord ? $existingRecord->toArray() : null,
            'changes' => $changes,
        ];
    }

    /**
     * Calculate changes between existing contact and Zoho Desk data
     */
    private function calculateContactChanges($existingContact, array $zohoDeskData): array
    {
        $changes = [];
        
        // Contact table columns: id, site_id, firstname, surname, title, department, mobile, work_phone, address, bio, exported_at, email, created_at, updated_at, deleted_at, primary, zoho_desk_id
        $fieldMapping = [
            'firstName' => 'firstname',
            'lastName' => 'surname', 
            'email' => 'email',
            'mobile' => 'mobile',
            'phone' => 'work_phone',
        ];

        foreach ($fieldMapping as $zohoDeskField => $crmField) {
            $zohoDeskValue = $zohoDeskData[$zohoDeskField] ?? null;
            $crmValue = $existingContact->$crmField ?? null;
            
            // Skip if both are empty
            if (empty($zohoDeskValue) && empty($crmValue)) {
                continue;
            }
            
            if ($zohoDeskValue !== $crmValue) {
                $changes[] = [
                    'field' => $crmField,
                    'old_value' => $crmValue,
                    'new_value' => $zohoDeskValue,
                ];
            }
        }

        return $changes;
    }

    /**
     * Calculate changes between existing account and Zoho Desk data
     */
    private function calculateAccountChanges($existingAccount, array $zohoDeskData): array
    {
        $changes = [];
        
        $fieldMapping = [
            'accountName' => 'name',
            'email' => 'email',
            'phone' => 'phone',
            'website' => 'website',
        ];

        foreach ($fieldMapping as $zohoDeskField => $crmField) {
            $zohoDeskValue = $zohoDeskData[$zohoDeskField] ?? null;
            $crmValue = $existingAccount->$crmField ?? null;
            
            if (empty($zohoDeskValue) && empty($crmValue)) {
                continue;
            }
            
            if ($zohoDeskValue !== $crmValue) {
                $changes[] = [
                    'field' => $crmField,
                    'old_value' => $crmValue,
                    'new_value' => $zohoDeskValue,
                ];
            }
        }

        return $changes;
    }

    /**
     * Validate data before sync
     */
    private function validateData(array $data, string $dataType): array
    {
        $issues = [];
        
        foreach ($data as $index => $item) {
            switch ($dataType) {
                case 'contacts':
                    if (empty($item['email'])) {
                        $issues[] = "Contact at index {$index} missing email address";
                    }
                    if (empty($item['firstName']) && empty($item['lastName'])) {
                        $issues[] = "Contact at index {$index} missing name";
                    }
                    break;
                    
                case 'accounts':
                    if (empty($item['accountName'])) {
                        $issues[] = "Account at index {$index} missing name";
                    }
                    break;
            }
        }
        
        return $issues;
    }

    /**
     * Get estimated contact count
     */
    private function getEstimatedContactCount(): int
    {
        try {
            $result = $this->syncContacts(['from' => 0, 'limit' => 1]);
            return $result['total'] ?? 0;
        } catch (\Exception $e) {
            return 0;
        }
    }

    /**
     * Get estimated account count
     */
    private function getEstimatedAccountCount(): int
    {
        try {
            $result = $this->syncAccounts(['from' => 0, 'limit' => 1]);
            return $result['total'] ?? 0;
        } catch (\Exception $e) {
            return 0;
        }
    }

    /**
     * Actually perform the sync by saving records to database
     */
    public function performSync(string $dataType, array $records, array $options = []): array
    {
        $results = [
            'success' => true,
            'created' => 0,
            'updated' => 0,
            'skipped' => 0,
            'linked' => 0, // New category for records that were just linked with Zoho ID
            'errors' => [],
        ];

        foreach ($records as $recordData) {
            try {
                if ($dataType === 'contacts') {
                    $result = $this->syncContactToDatabase($recordData);
                } elseif ($dataType === 'accounts') {
                    $result = $this->syncAccountToDatabase($recordData);
                } else {
                    continue;
                }

                $results[$result]++;
            } catch (\Exception $e) {
                $results['errors'][] = [
                    'record' => $recordData['display_name'] ?? 'Unknown',
                    'error' => $e->getMessage(),
                ];
                Log::error('Sync record failed', [
                    'data_type' => $dataType,
                    'record' => $recordData,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return $results;
    }

    /**
     * Sync a contact to the database with Zoho ID linking
     */
    private function syncContactToDatabase(array $recordData): string
    {
        $zohoDeskData = $recordData['data'];
        $zohoDeskId = $zohoDeskData['id'];
        $originalSyncStatus = $recordData['sync_status'] ?? 'new';
        
        // Find existing contact by Zoho ID first, then email
        $existingContact = \App\Models\Contact::where('zoho_desk_id', $zohoDeskId)->first();
        
        if (!$existingContact && !empty($zohoDeskData['email'])) {
            $existingContact = \App\Models\Contact::where('email', $zohoDeskData['email'])->first();
        }

        if ($existingContact) {
            // If this was originally a 'skip' record, just link the Zoho ID without updating other data
            if ($originalSyncStatus === 'skip') {
                if (empty($existingContact->zoho_desk_id)) {
                    $existingContact->update(['zoho_desk_id' => $zohoDeskId]);
                    return 'linked';
                }
                return 'linked'; // Already linked
            }
            
            // For 'update' and 'new' records, update the data
            $contactData = [
                'zoho_desk_id' => $zohoDeskId,
                'firstname' => $zohoDeskData['firstName'] ?? null,
                'surname' => $zohoDeskData['lastName'] ?? null,
                'email' => $zohoDeskData['email'] ?? null,
                'mobile' => $zohoDeskData['mobile'] ?? null,
                'work_phone' => $zohoDeskData['phone'] ?? null,
                // Add other fields as needed
            ];

            // Remove null values to avoid overwriting existing data with nulls
            $contactData = array_filter($contactData, function($value) {
                return $value !== null;
            });
            
            $existingContact->update($contactData);
            return 'updated';
        } else {
            // Create new contact with Zoho ID
            $contactData = [
                'zoho_desk_id' => $zohoDeskId,
                'firstname' => $zohoDeskData['firstName'] ?? null,
                'surname' => $zohoDeskData['lastName'] ?? null,
                'email' => $zohoDeskData['email'] ?? null,
                'mobile' => $zohoDeskData['mobile'] ?? null,
                'work_phone' => $zohoDeskData['phone'] ?? null,
            ];

            // Remove null values
            $contactData = array_filter($contactData, function($value) {
                return $value !== null;
            });
            
            \App\Models\Contact::create($contactData);
            return 'created';
        }
    }

    /**
     * Sync an account to the database with Zoho ID linking
     */
    private function syncAccountToDatabase(array $recordData): string
    {
        $zohoDeskData = $recordData['data'];
        $zohoDeskId = $zohoDeskData['id'];
        $originalSyncStatus = $recordData['sync_status'] ?? 'new';
        
        // Find existing customer by Zoho ID first, then name
        $existingCustomer = \App\Models\Customer::where('zoho_desk_account_id', $zohoDeskId)->first();
        
        if (!$existingCustomer && !empty($zohoDeskData['accountName'])) {
            $existingCustomer = \App\Models\Customer::where('name', $zohoDeskData['accountName'])->first();
        }

        if ($existingCustomer) {
            // If this was originally a 'skip' record, just link the Zoho ID without updating other data
            if ($originalSyncStatus === 'skip') {
                if (empty($existingCustomer->zoho_desk_account_id)) {
                    $existingCustomer->update(['zoho_desk_account_id' => $zohoDeskId]);
                    return 'linked';
                }
                return 'linked'; // Already linked
            }
            
            // For 'update' and 'new' records, update the data
            $customerData = [
                'zoho_desk_account_id' => $zohoDeskId,
                'name' => $zohoDeskData['accountName'] ?? null,
                // Add other fields as needed based on Customer table schema
            ];

            // Remove null values
            $customerData = array_filter($customerData, function($value) {
                return $value !== null;
            });
            
            $existingCustomer->update($customerData);
            return 'updated';
        } else {
            // Create new customer with Zoho ID
            $customerData = [
                'zoho_desk_account_id' => $zohoDeskId,
                'name' => $zohoDeskData['accountName'] ?? null,
            ];

            // Remove null values
            $customerData = array_filter($customerData, function($value) {
                return $value !== null;
            });
            
            \App\Models\Customer::create($customerData);
            return 'created';
        }
    }
}