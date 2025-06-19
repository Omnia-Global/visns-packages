# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Project Overview

This is `visns-packages`, a comprehensive Laravel package that provides enhanced authentication, file management, two-factor authentication, report building, and proposal generation capabilities for Laravel applications. It's developed by Omnia Global and serves as a shared library across multiple Laravel projects.

**Important**: This is a Laravel package designed to be installed into existing Laravel applications, not a standalone Laravel project. When working with this codebase, remember that it will be consumed by other Laravel applications via Composer.

## Architecture

### Core Components

- **Controllers**: Dynamic and specialized controllers for different functionalities
  - `DynamicController`: Provides flexible CRUD operations for any model with automatic URL-based model detection
  - `DynamicJsonController`: Manages JSON data within model fields
  - `AuthController`: Handles authentication, 2FA, password reset, and social auth
  - `UserController`: User management, notifications, and 2FA setup
  - `ReportBuilderController`: Database schema exploration and custom report generation
  - `PDFController`: PDF generation from views or HTML
  - `ProposalTemplateController`: Proposal template management
  - `BrandingProfileController`: Company branding and styling
  - `FileController`: Polymorphic file management
  - `PermissionController`, `RoleController`: Role-based access control
  - `AuditController`: Change tracking and audit logs

- **Models**: Core data models with enhanced functionality
  - `User`: Enhanced user model with 2FA, social auth, and dynamic relationships
  - `File`: Polymorphic file attachments to any model
  - `ReportBuilder`: Custom report configurations
  - `Audit`: Change tracking records
  - `TwoFactorRememberToken`: Device-specific 2FA bypass tokens
  - `ProposalTemplate`: Proposal template configurations
  - `ProposalTemplateSection`: Individual sections within templates
  - `BrandingProfile`: Company branding and styling profiles

- **Services**: Business logic and processing
  - `ProposalAssemblyService`: Assembles complete proposals from templates and data

- **Traits**: Reusable functionality
  - `UsePackageUser`: Adds package functionality to existing User models

### Key Features

1. **Dynamic Model Operations**: The `DynamicController` automatically detects models from URLs (`/ajax/{model}/action`) and provides standard CRUD operations
2. **Two-Factor Authentication**: Complete 2FA system with QR codes, recovery codes, and device remembering
3. **Report Builder**: Visual query builder with table joins, filters, and multi-format exports
4. **File Management**: Polymorphic file relationships supporting any model
5. **Search Integration**: Automatic Meilisearch integration with graceful database fallback
6. **Model Merging**: Advanced functionality to merge attributes and relationships between models
7. **Social Authentication**: Integration with Laravel Socialite for OAuth providers
8. **Audit System**: Comprehensive change tracking using Laravel Auditing
9. **Proposal System**: Professional proposal generation with templates, branding, and dynamic content

## Development Commands

### Meilisearch Commands

The package includes four helpful Meilisearch commands for debugging, configuration, and data synchronization:

```bash
# Configure Meilisearch index settings
php artisan meilisearch:configure --show                    # Show current configuration
php artisan meilisearch:configure --apply                   # Apply configuration from config
php artisan meilisearch:configure --apply --model=User      # Apply for specific model

# Debug Meilisearch connection and indexes  
php artisan meilisearch:debug                               # Auto-discover and debug searchable models
php artisan meilisearch:debug --model=Customer              # Debug specific model

# Test Meilisearch search functionality
php artisan meilisearch:test "search query"                 # Test search (defaults to User model)
php artisan meilisearch:test "John" --model=Customer        # Test search on specific model
php artisan meilisearch:test "active" --filters="status = active" --limit=20

# Sync models with Meilisearch
php artisan meilisearch:sync                                # Sync all searchable models
php artisan meilisearch:sync --model=Customer               # Sync specific model
php artisan meilisearch:sync --flush --force                # Flush and sync without confirmation
php artisan meilisearch:sync --chunk=50                     # Custom batch size
php artisan meilisearch:sync --namespace="MyPackage\Models" # Sync from custom namespace
```

### Proposal System Commands

The package includes commands for managing the proposal system:

```bash
# Create default proposal template with sections
php artisan db:seed --class="Visnsstudio\VisnsPackages\Database\Seeders\DefaultProposalTemplateSeeder"

# Publish proposal migrations and seeders
php artisan vendor:publish --tag=visns-packages-migrations
php artisan vendor:publish --tag=visns-packages-seeders

# Run migrations to create proposal tables
php artisan migrate
```

### Testing

This package is designed to be tested within a host Laravel application. Common testing commands:

```bash
# Run all tests in host Laravel app
php artisan test

# Run specific test files
vendor/bin/phpunit tests/Unit/AuthControllerTest.php
vendor/bin/phpunit tests/Feature/DynamicControllerNestedObjectTest.php

# Run tests with coverage
vendor/bin/phpunit --coverage-html coverage
```

### Code Formatting

```bash
# Install formatting dependencies
yarn install

# Format PHP code with Prettier
yarn prettier --write "**/*.php"
```

### Package Development

```bash
# Publish migrations when developing
php artisan visns:publish-migrations

# Or using standard Laravel command
php artisan vendor:publish --tag=visns-packages-migrations

# Publish seeders
php artisan vendor:publish --tag=visns-packages-seeders

# Publish configuration
php artisan vendor:publish --tag=visns-packages-config
```

## Configuration

### User Model Configuration

The package can work with existing User models in two ways:

1. **Direct Usage**: Set `VISNS_USER_MODEL=Visnsstudio\\VisnsPackages\\Models\\User`
2. **Trait Usage**: Add `UsePackageUser` trait to existing User model

### Dynamic Relationships

Configure dynamic relationships in `config/visns-packages.php`:

```php
'user_dynamic_relationships' => [
    'profile' => [
        'type' => 'hasOne',
        'model' => 'App\\Models\\Profile',
        'foreign_key' => 'user_id',
    ],
    'posts' => [
        'type' => 'hasMany',
        'model' => 'App\\Models\\Post',
        'foreign_key' => 'user_id',
    ],
],
```

### Dropdown Field Configuration

Configure intelligent field detection for dropdown functionality:

```php
'dropdown_fields' => [
    'label_fields' => ['label', 'name', 'title', 'full_name', 'display_name'],
    'name_combinations' => [
        ['title', 'firstname', 'lastname'],
        ['firstname', 'lastname'],
        ['first_name', 'last_name'],
        ['firstname', 'surname'],
    ],
    'id_fields' => ['id', 'uuid', 'slug', 'code'],
    'sort_fields' => ['label', 'name', 'title', 'firstname', 'created_at'],
],
```

The dropdown function now automatically:
- Detects the best ID field (id, uuid, slug, code)
- Finds available label fields (label, name, title, etc.)
- Concatenates name components (firstname + lastname, etc.)
- Intelligently sorts by the most appropriate field
- Caches field detection for performance

### Search Configuration

Configure Meilisearch integration:

```env
SCOUT_DRIVER=meilisearch
MEILISEARCH_HOST=http://localhost:7700
VISNS_DISABLE_MEILISEARCH=false  # Set to true to force disable
```

### Proposal System Configuration

Configure proposal generation features:

```php
// In config/visns-packages.php
'proposal' => [
    'features' => [
        'enable_proposal_mode' => true,
        'enable_template_builder' => true,
        'enable_branding_profiles' => true,
        'enable_dynamic_variables' => true,
    ],
    'templates' => [
        'default_template_name' => 'Default Business Proposal',
        'auto_generate_toc' => true,
        'variable_prefix' => '{{',
        'variable_suffix' => '}}',
    ],
    'pdf' => [
        'default_paper' => 'a4',
        'default_orientation' => 'portrait',
        'default_margins' => '40px',
        'enable_page_numbers' => true,
    ],
    'branding' => [
        'logo_max_size' => '2MB',
        'logo_allowed_types' => ['jpg', 'jpeg', 'png', 'svg'],
        'default_colors' => [
            'primary' => '#2563eb',
            'secondary' => '#64748b', 
            'accent' => '#059669',
        ],
    ],
    'sections' => [
        'allow_custom_sections' => true,
        'required_sections' => ['cover_page', 'toc', 'overview', 'quote_items'],
        'static_sections' => ['terms_conditions', 'agreement_signature'],
    ],
],
```

## API Patterns

### Dynamic Controller Usage

```bash
# Table data with filtering
GET /ajax/{model}/table?where[0][id]=name&where[0][value]=John&where[0][operator]=contains

# Dropdown data
POST /ajax/{model}/dropdown

# CRUD operations
GET /ajax/{model}/show/{id}
POST /ajax/{model}/store
PUT /ajax/{model}/update/{id}
DELETE /ajax/{model}/destroy/{id}

# Model merging
POST /ajax/{model}/merge
```

### Report Builder API

```bash
# Schema exploration
POST /ajax/reportBuilder/getTables
POST /ajax/reportBuilder/getTableColumns

# Report execution
POST /ajax/reportBuilder/execute

# Report export
POST /ajax/reportBuilder/export
```

### Proposal System API

```bash
# Template management
GET /ajax/proposal-templates                    # List templates
POST /ajax/proposal-templates                   # Create template
GET /ajax/proposal-templates/{id}               # Get template
PUT /ajax/proposal-templates/{id}               # Update template
DELETE /ajax/proposal-templates/{id}            # Delete template
POST /ajax/proposal-templates/table             # Table data
POST /ajax/proposal-templates/dropdown          # Dropdown data
POST /ajax/proposal-templates/{id}/preview      # Preview template
POST /ajax/proposal-templates/{id}/duplicate    # Duplicate template

# Branding profiles
GET /ajax/branding-profiles                     # List profiles
POST /ajax/branding-profiles                    # Create profile
GET /ajax/branding-profiles/{id}                # Get profile
PUT /ajax/branding-profiles/{id}                # Update profile
DELETE /ajax/branding-profiles/{id}             # Delete profile
POST /ajax/branding-profiles/table              # Table data
POST /ajax/branding-profiles/dropdown           # Dropdown data
GET /ajax/branding-profiles/{id}/css            # Get CSS
POST /ajax/branding-profiles/{id}/upload-logo   # Upload logo

# PDF generation
POST /ajax/pdf/generate-proposal                # Generate proposal PDF
POST /ajax/pdf/preview-proposal                 # Preview proposal HTML
```

## Dependencies

### Required PHP Packages
- `laravel/framework: >=11.0`
- `pragmarx/google2fa: ^8.0` (2FA)
- `bacon/bacon-qr-code: ^3.0` (QR codes)
- `phpoffice/phpspreadsheet: ^1.29` (Excel export)
- `barryvdh/laravel-dompdf: ^3.0` (PDF generation)

### Optional Dependencies
- Laravel Scout + Meilisearch (for enhanced search)
- Laravel Socialite (for social authentication)
- Spatie Laravel Permission (for roles/permissions)
- Laravel Auditing (for change tracking)
- VerumConsilium Browsershot (for advanced PDF generation)
- DOMDocument (for HTML processing in proposals)

## Important Notes

- This package automatically registers routes unless `register_routes` is set to false in config
- All package routes use `/ajax/` prefix for web routes and `/api/` for API routes
- The `DynamicController` provides a powerful abstraction but requires models to follow Laravel conventions
- File uploads are handled automatically for models with polymorphic file relationships
- Search functionality automatically detects and uses Meilisearch when available
- Two-factor authentication includes device remembering for 30 days
- Model merging supports complex relationship handling and attribute prioritization
- Proposal system supports both static and dynamic content sections
- Template sections can be reordered and customized per project
- Branding profiles support logo uploads and custom CSS generation
- Proposal generation integrates seamlessly with existing quote systems