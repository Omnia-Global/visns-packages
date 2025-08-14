# OAuth Integration System

## Overview

The OAuth Integration System provides a reusable, configuration-driven framework for integrating third-party services with OAuth 2.0 authentication. This system is designed to be easily extensible and allows projects to add new OAuth providers without writing boilerplate code.

## Features

- **Provider Agnostic**: Easily add new OAuth providers by implementing a simple interface
- **Configuration Driven**: Providers are configured through config files and environment variables
- **Token Management**: Automatic token refresh and secure storage
- **React Components**: Pre-built UI components for OAuth management
- **Error Handling**: Comprehensive error handling and logging
- **Route Isolation**: Non-conflicting routes that don't interfere with existing auth systems

## Architecture

### Backend Components

#### Contracts
- `OAuthProvider`: Interface that all providers must implement
- `TokenStore`: Interface for token storage (currently uses database)

#### Services
- `OAuthManager`: Central orchestrator for all OAuth operations
- `AbstractOAuthProvider`: Base class for OAuth providers
- Provider-specific implementations (e.g., `ZohoDeskProvider`)

#### Models
- `OAuthConnection`: Stores OAuth tokens and metadata

#### Controllers
- `OAuthController`: Handles OAuth flows and API endpoints

### Frontend Components

#### React Components
- `OAuthManager`: Main management interface
- `OAuthProviderCard`: Individual provider cards
- `OAuthConnectionStatus`: Connection status display

## Installation

### 1. Install Package Dependencies

Ensure visns-packages and visns-components are installed in your project.

### 2. Publish Configuration

```bash
php artisan vendor:publish --tag=oauth-providers-config
```

### 3. Run Migrations

```bash
php artisan migrate
```

### 4. Configure Environment Variables

Add OAuth provider configuration to your `.env` file:

```env
# Zoho Desk Example
OAUTH_ZOHO_DESK_ENABLED=true
ZOHO_DESK_CLIENT_ID=your_client_id
ZOHO_DESK_CLIENT_SECRET=your_client_secret
ZOHO_DESK_REDIRECT_URI=https://yourdomain.com/integrations/oauth/zoho_desk/callback
ZOHO_DESK_ORG_ID=your_org_id
ZOHO_DESK_DATA_CENTER=com.au
```

## Configuration

### Provider Configuration

Edit `config/oauth-providers.php` to enable/disable providers:

```php
'zoho_desk' => [
    'provider_class' => \Visnsstudio\VisnsPackages\Services\Providers\ZohoDeskProvider::class,
    'enabled' => env('OAUTH_ZOHO_DESK_ENABLED', false),
    'client_id' => env('ZOHO_DESK_CLIENT_ID'),
    'client_secret' => env('ZOHO_DESK_CLIENT_SECRET'),
    'redirect_uri' => env('ZOHO_DESK_REDIRECT_URI'),
    'org_id' => env('ZOHO_DESK_ORG_ID'),
    'data_center' => env('ZOHO_DESK_DATA_CENTER', 'com'),
    'sync_frequency' => 'hourly',
    'data_mapping' => [
        'contacts' => [
            'enabled' => true,
            'model' => 'App\\Models\\Contact',
            'foreign_key' => 'zoho_desk_id',
            'sync_command' => 'oauth:sync:zoho-desk:contacts',
        ],
        'accounts' => [
            'enabled' => true,
            'model' => 'App\\Models\\Customer',
            'foreign_key' => 'zoho_desk_account_id',
            'sync_command' => 'oauth:sync:zoho-desk:accounts',
        ],
    ],
],
```

## Available Routes

### Public Routes
- `GET /integrations/oauth/{provider}/authorize` - Start OAuth flow
- `GET /integrations/oauth/{provider}/callback` - OAuth callback

### Protected Routes (require authentication)
- `GET /integrations/oauth/providers` - List all available providers
- `GET /integrations/oauth/{provider}/status` - Get connection status
- `POST /integrations/oauth/{provider}/test` - Test connection
- `POST /integrations/oauth/{provider}/disconnect` - Disconnect provider
- `POST /integrations/oauth/{provider}/sync` - Sync data

## Usage

### Frontend Integration

```jsx
import { OAuthManager } from '@visns-studio/visns-components';

function IntegrationsPage() {
    return (
        <OAuthManager
            onAuthComplete={(provider, action) => {
                console.log(`${provider} ${action}`);
            }}
            onSyncComplete={(provider, dataType, result) => {
                console.log(`Synced ${dataType} from ${provider}`, result);
            }}
            onError={(error) => {
                console.error('OAuth error:', error);
            }}
        />
    );
}
```

### Backend Integration

```php
// Get OAuth manager
$oauthManager = app(OAuthManager::class);

// Check if provider is connected
$isConnected = $oauthManager->isProviderConnected('zoho_desk');

// Get connection status
$status = $oauthManager->getConnectionStatus('zoho_desk');

// Sync data
$result = $oauthManager->syncData('zoho_desk', 'contacts');
```

## Creating New Providers

### 1. Create Provider Class

```php
<?php

namespace App\Services\OAuth;

use Visnsstudio\VisnsPackages\Services\Providers\AbstractOAuthProvider;

class CustomProvider extends AbstractOAuthProvider
{
    protected function getProviderName(): string
    {
        return 'custom_provider';
    }

    protected function getProviderDisplayName(): string
    {
        return 'Custom Provider';
    }

    protected function getBaseApiUrl(): string
    {
        return 'https://api.customprovider.com/v1';
    }

    protected function getAuthUrl(): string
    {
        return 'https://customprovider.com/oauth/authorize';
    }

    protected function getTokenUrl(): string
    {
        return 'https://customprovider.com/oauth/token';
    }

    public function getScopes(): array
    {
        return ['read:contacts', 'read:companies'];
    }

    public function getSyncableDataTypes(): array
    {
        return [
            'contacts' => [
                'name' => 'Contacts',
                'description' => 'Sync contacts from Custom Provider',
                'model' => 'Contact',
            ],
        ];
    }

    public function getAuthorizationUrl(string $state): string
    {
        // Implementation
    }

    public function exchangeCodeForTokens(string $code): ?array
    {
        // Implementation
    }

    public function refreshToken(string $refreshToken): ?array
    {
        // Implementation
    }

    public function syncData(string $dataType, array $options = []): array
    {
        // Implementation
    }
}
```

### 2. Add to Configuration

Add your provider to `config/oauth-providers.php`:

```php
'custom_provider' => [
    'provider_class' => App\Services\OAuth\CustomProvider::class,
    'enabled' => env('OAUTH_CUSTOM_ENABLED', false),
    'client_id' => env('CUSTOM_CLIENT_ID'),
    'client_secret' => env('CUSTOM_CLIENT_SECRET'),
    'redirect_uri' => env('CUSTOM_REDIRECT_URI'),
    // ... other config
],
```

### 3. Add Environment Variables

```env
OAUTH_CUSTOM_ENABLED=true
CUSTOM_CLIENT_ID=your_client_id
CUSTOM_CLIENT_SECRET=your_client_secret
CUSTOM_REDIRECT_URI=https://yourdomain.com/integrations/oauth/custom_provider/callback
```

## Available Providers

### Zoho Desk
- **Data Types**: Contacts, Accounts
- **Scopes**: `Desk.contacts.READ`, `Desk.basic.READ`, `Desk.search.READ`
- **Configuration**: Requires `org_id` and `data_center`

### HubSpot (Example)
- **Data Types**: Contacts, Companies
- **Scopes**: TBD
- **Configuration**: Standard OAuth 2.0

### Salesforce (Example)
- **Data Types**: Contacts, Accounts
- **Scopes**: TBD
- **Configuration**: Requires `instance_url`

## API Response Format

### Success Response
```json
{
    "success": true,
    "data": {
        // Response data
    },
    "message": "Operation completed successfully"
}
```

### Error Response
```json
{
    "success": false,
    "message": "Error description",
    "error_code": "OPTIONAL_ERROR_CODE"
}
```

## Security Considerations

1. **Token Storage**: Tokens are encrypted in the database
2. **State Validation**: CSRF protection via state parameter
3. **Route Protection**: API routes require authentication
4. **Token Refresh**: Automatic token refresh prevents exposure
5. **Error Handling**: Sensitive information is not exposed in error messages

## Troubleshooting

### Common Issues

1. **Provider Not Found**: Ensure the provider is enabled in config and environment variables are set
2. **Token Expired**: The system automatically refreshes tokens, but manual refresh may be needed
3. **Callback URL Mismatch**: Ensure redirect URI matches exactly between OAuth app and config
4. **Missing Scopes**: Verify the OAuth app has required permissions

### Debug Commands

```bash
# Check OAuth connections
php artisan tinker
>>> app(\Visnsstudio\VisnsPackages\Services\OAuthManager::class)->getAvailableProviders()

# Check database connections
>>> \Visnsstudio\VisnsPackages\Models\OAuthConnection::all()
```

## Contributing

When adding new providers:

1. Follow the `AbstractOAuthProvider` pattern
2. Add comprehensive error handling
3. Include proper scopes and permissions
4. Document configuration requirements
5. Add examples and tests

## Support

For issues related to the OAuth integration system:

1. Check logs for detailed error messages
2. Verify configuration and environment variables
3. Test OAuth flow manually in browser
4. Check provider-specific documentation