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

# Update models to use HasRelationshipSorting trait
php artisan visns:update-models-sorting

# Preview changes without applying them
php artisan visns:update-models-sorting --dry-run

# Create backups before modifying files
php artisan visns:update-models-sorting --backup

# Custom model path and namespace
php artisan visns:update-models-sorting --path=app/Models --namespace=App\\Models

# Skip confirmation prompts
php artisan visns:update-models-sorting --force
```

### Model Update Command

The package includes an automated command to update all models in a project to use the `HasRelationshipSorting` trait. This is essential for enabling relationship and JSON field sorting capabilities.

#### Command Overview

```bash
php artisan visns:update-models-sorting
```

**What it does:**

1. **Discovers Models**: Scans the specified path (default: `app/Models`) for all Eloquent model classes
2. **Analyzes Requirements**: Determines which models need the `HasRelationshipSorting` trait
3. **Safe Updates**: Adds trait imports, trait usage, and removes conflicting `scopeCustomOrder` methods
4. **Backup Support**: Creates backup files before making changes (optional)
5. **Dry Run Mode**: Preview changes without applying them

#### Command Options

```bash
# Basic usage
php artisan visns:update-models-sorting

# Preview changes without applying (recommended first run)
php artisan visns:update-models-sorting --dry-run

# Create backup files before modifying
php artisan visns:update-models-sorting --backup

# Skip confirmation prompts
php artisan visns:update-models-sorting --force

# Custom model path and namespace
php artisan visns:update-models-sorting --path=app/Models --namespace=App\\Models

# Combination of options
php artisan visns:update-models-sorting --dry-run --backup --path=custom/Models --namespace=Custom\\Models
```

#### What Gets Updated

For each discovered model, the command:

1. **Adds Trait Import**: 
   ```php
   use Visnsstudio\VisnsPackages\Traits\HasRelationshipSorting;
   ```

2. **Adds Trait Usage**:
   ```php
   class User extends Model
   {
       use HasRelationshipSorting;  // Added to existing traits
   }
   ```

3. **Removes Old Methods**: Removes basic `scopeCustomOrder` implementations that conflict:
   ```php
   // This gets removed:
   public function scopeCustomOrder($query, $orderBy, $order)
   {
       if (isset($orderBy) && isset($order)) {
           $query->orderBy($orderBy, $order);
       }
       return $query;
   }
   ```

#### Example Session

```bash
$ php artisan visns:update-models-sorting --dry-run

🔍 Discovering models in your project...
Found 37 models:
  - App\Models\User (app/Models/User.php)
  - App\Models\Contact (app/Models/Contact.php)
  - App\Models\Client (app/Models/Client.php)
  - App\Models\Lead (app/Models/Lead.php)
  ...

📝 Models that need updating:
  - App\Models\User (needs trait import, needs trait usage)
  - App\Models\Contact (has old scopeCustomOrder method)
  - App\Models\Client (needs trait import, needs trait usage, has old scopeCustomOrder method)
  - App\Models\Lead (has old scopeCustomOrder method)

🔍 Dry run mode - showing what would be changed:

📄 App\Models\User:
  + Add import: use Visnsstudio\VisnsPackages\Traits\HasRelationshipSorting;
  + Add trait usage to class

📄 App\Models\Contact:
  - Remove old scopeCustomOrder method

📄 App\Models\Client:
  + Add import: use Visnsstudio\VisnsPackages\Traits\HasRelationshipSorting;
  + Add trait usage to class
  - Remove old scopeCustomOrder method
```

#### Integration with Package Installation

This command should be run after installing the package in any new project:

```bash
# Standard installation process
composer require visnsstudio/visns-packages
php artisan vendor:publish --tag=visns-packages-migrations
php artisan migrate

# Add relationship sorting to all models
php artisan visns:update-models-sorting --dry-run  # Preview first
php artisan visns:update-models-sorting --backup   # Apply with backups
```

#### Safety Features

- **Backup Creation**: `--backup` flag creates timestamped backup files
- **Dry Run Mode**: `--dry-run` shows what would change without applying
- **Smart Detection**: Only updates models that actually need changes
- **Error Handling**: Graceful handling of models that can't be updated
- **Confirmation Prompts**: Asks for confirmation before making changes (unless `--force`)

This command makes it easy to integrate relationship sorting capabilities into existing projects without manual file modifications.

### Virtual Column Sorting Handling

The `HasRelationshipSorting` trait now includes intelligent detection and handling of virtual/appended columns that can cause SQL errors when users attempt to sort by them.

#### Virtual Column Detection

The trait automatically detects virtual columns using multiple methods:

1. **Appended Attributes**: Fields listed in the model's `$appends` array
2. **Accessor Methods**: Fields with `getXAttribute()` methods 
3. **Common Patterns**: Fields matching common virtual column naming patterns

#### Handling Virtual Column Sorting

When a virtual column sort is attempted, the trait:

1. **Alternative Mapping**: Uses alternative sortable fields defined in `getVirtualColumnAlternatives()`
2. **Custom Handlers**: Calls custom sorting methods defined in `getVirtualColumnHandlers()`
3. **Graceful Fallback**: Returns unsorted query with warning if no handling is defined

#### Model Configuration for Virtual Columns

```php
use Visnsstudio\VisnsPackages\Traits\HasRelationshipSorting;

class Contact extends Model
{
    use HasRelationshipSorting;
    
    protected $appends = ['customer_names', 'full_name'];
    
    public function getCustomerNamesAttribute()
    {
        return $this->getAllCustomers()->pluck('name')->join(', ');
    }
    
    // Define alternative sortable fields for virtual columns
    public function getVirtualColumnAlternatives()
    {
        return [
            'customer_names' => 'name',        // Sort by contact name instead
            'full_name' => 'name',             // Sort by name field
            'description' => 'created_at',     // Sort by creation date
        ];
    }
    
    // Define custom handlers for complex virtual column sorting
    public function getVirtualColumnHandlers()
    {
        return [
            'customer_names' => 'sortByCustomerNames',
        ];
    }
    
    // Custom sorting method for customer_names
    public function sortByCustomerNames($query, $orderBy, $order)
    {
        // Complex sorting logic using subqueries or joins
        return $query->leftJoin('contact_customer', 'contacts.id', '=', 'contact_customer.contact_id')
                    ->leftJoin('customers', 'contact_customer.customer_id', '=', 'customers.id')
                    ->orderBy('customers.name', $order)
                    ->select('contacts.*')
                    ->distinct();
    }
}
```

#### Command Enhancement

The `visns:update-models-sorting` command now detects virtual columns and provides warnings:

```bash
$ php artisan visns:update-models-sorting --dry-run

📄 App\Models\Contact:
  ✅ Already has HasRelationshipSorting trait
  ⚠️  Virtual columns detected (may cause sorting issues):
     - customer_names (appended attribute)
     - full_name (appended attribute) 
     - display_name (accessor method)
     💡 Consider defining getVirtualColumnAlternatives() method
```

#### Best Practices for Virtual Columns

1. **Define Alternatives**: Always provide `getVirtualColumnAlternatives()` for virtual columns
2. **Frontend Configuration**: Disable sorting for complex virtual columns in frontend config:
   ```json
   {
     "id": "customer_names",
     "label": "Customers",
     "sortable": false
   }
   ```
3. **Performance Consideration**: For frequently sorted virtual columns, consider:
   - Adding computed/cached columns to the database
   - Using database observers to maintain computed values
   - Implementing efficient custom sorting handlers

4. **Error Prevention**: The enhanced trait prevents SQL errors but consider user experience:
   - Clearly communicate which columns are sortable
   - Provide meaningful alternative sorting options
   - Use loading states for complex sorting operations

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

# Table data with selective column loading (prevents memory allocation errors)
POST /ajax/{model}/table
{
  "columns": ["id", "name", "email", "created_at"]
}
# Or as comma-separated string
POST /ajax/{model}/table
{
  "columns": "id,name,email,created_at"
}

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

### Selective Column Loading Feature

The DynamicController now supports selective column loading to prevent memory allocation errors when dealing with tables containing large JSON/TEXT columns.

**Benefits:**
- **Memory Efficiency**: Prevents "Out of sort memory" errors by avoiding large columns
- **Performance**: Faster queries by loading only needed data
- **Backward Compatible**: Existing code continues to work unchanged
- **Flexible**: Works with both `table()` and `list()` methods

**Usage Examples:**

```javascript
// Frontend usage - avoid loading large JSON columns
fetch('/ajax/visitRequests/table', {
  method: 'POST',
  headers: {'Content-Type': 'application/json'},
  body: JSON.stringify({
    columns: ['id', 'user_id', 'event_date', 'tour_status_id', 'status', 'created_at'],
    take: 50
  })
})

// For tables with many large columns, be selective
fetch('/ajax/proposals/table', {
  method: 'POST',
  headers: {'Content-Type': 'application/json'},
  body: JSON.stringify({
    columns: 'id,title,status,created_at,updated_at', // Exclude 'content', 'metadata' etc.
    take: 100
  })
})
```

**Use Cases:**
1. **Large JSON columns**: Skip columns like `event_detail`, `metadata`, `configuration`, etc.
2. **Memory-constrained queries**: When dealing with many records
3. **Performance optimization**: Load only fields needed by the frontend
4. **Mobile optimization**: Reduce payload size for mobile clients

The feature automatically handles column name validation and supports both array and string input formats.

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