# Visns Packages

A comprehensive Laravel package that provides enhanced authentication, file management, two-factor authentication, and report building capabilities for Laravel applications.

## Table of Contents

-   [Visns Packages](#visns-packages)
    -   [Table of Contents](#table-of-contents)
    -   [Installation](#installation)
    -   [Features](#features)
    -   [Database Migrations](#database-migrations)
        -   [User Table Migrations](#user-table-migrations)
        -   [File Table Migrations](#file-table-migrations)
        -   [Report Builder Migrations](#report-builder-migrations)
    -   [Authentication System](#authentication-system)
        -   [Basic Authentication](#basic-authentication)
        -   [Two-Factor Authentication (2FA)](#two-factor-authentication-2fa)
            -   [Setup](#setup)
            -   [Authentication Flow with 2FA](#authentication-flow-with-2fa)
            -   [Remember Me for 2FA](#remember-me-for-2fa)
            -   [Managing 2FA](#managing-2fa)
        -   [Social Authentication](#social-authentication)
        -   [API Authentication](#api-authentication)
    -   [File Management](#file-management)
    -   [User Model](#user-model)
        -   [Using the Package User Model](#using-the-package-user-model)
            -   [Option 1: Use the Package User Model Directly](#option-1-use-the-package-user-model-directly)
            -   [Option 2: Use the UsePackageUser Trait](#option-2-use-the-usepackageuser-trait)
            -   [Dynamic Relationships](#dynamic-relationships)
    -   [User Management](#user-management)
        -   [Disabling Users](#disabling-users)
        -   [User Management API](#user-management-api)
    -   [Role \& Permission Management](#role--permission-management)
        -   [Permission Management](#permission-management)
        -   [Role Management](#role-management)
    -   [Report Builder](#report-builder)
        -   [Database Schema Exploration](#database-schema-exploration)
        -   [Report Configuration](#report-configuration)
        -   [Executing Reports](#executing-reports)
        -   [Exporting Reports](#exporting-reports)
        -   [Saving and Managing Reports](#saving-and-managing-reports)
    -   [PDF Generation](#pdf-generation)
        -   [Generating PDFs from Views](#generating-pdfs-from-views)
        -   [Generating PDFs from HTML](#generating-pdfs-from-html)
        -   [Custom PDF Options](#custom-pdf-options)
    -   [Dynamic Controller](#dynamic-controller)
        -   [Basic Usage](#basic-usage)
        -   [Filtering](#filtering)
            -   [Available Filter Operators](#available-filter-operators)
            -   [OR Conditions with orKey](#or-conditions-with-orkey)
            -   [Relationship Filtering with whereHas](#relationship-filtering-with-wherehas)
            -   [Combining OR Conditions with Relationships](#combining-or-conditions-with-relationships)
        -   [Model Merging](#model-merging)
            -   [Basic Merge](#basic-merge)
            -   [Advanced Merge Options](#advanced-merge-options)
            -   [Relationship Handling](#relationship-handling)
            -   [API Access](#api-access)
    -   [Configuration](#configuration)
        -   [Environment Variables](#environment-variables)
        -   [Package Configuration](#package-configuration)
        -   [Automatic Route Registration](#automatic-route-registration)
            -   [Authentication Routes](#authentication-routes)
            -   [User Routes](#user-routes)
            -   [File Management Routes](#file-management-routes)
            -   [Permission Management Routes](#permission-management-routes)
            -   [Role Management Routes](#role-management-routes)
            -   [Report Builder Routes](#report-builder-routes)
            -   [PDF Generation Routes](#pdf-generation-routes)
            -   [Model Merge Routes](#model-merge-routes)
            -   [Social Authentication Routes](#social-authentication-routes)
            -   [API Routes](#api-routes)
    -   [License](#license)

## Installation

You can install the package via composer:

```bash
composer require visnsstudio/visns-packages
```

### Local Development Setup

For local development, you'll need to set up the package as a local dependency. Add the following to your project's `composer.json` file in the `repositories` section:

```json
{
    "repositories": [
        {
            "type": "path",
            "url": "../visns-packages",
            "options": {
                "symlink": true
            }
        }
    ]
}
```

Then install the package:

```bash
composer require visnsstudio/visns-packages @dev
```

After installation, publish the migrations:

```bash
php artisan visns:publish-migrations
```

Or using Laravel's vendor:publish command:

```bash
php artisan vendor:publish --tag=visns-packages-migrations
```

Optionally, publish the seeders:

```bash
php artisan vendor:publish --tag=visns-packages-seeders
```

Optionally, publish the configuration file:

```bash
php artisan vendor:publish --tag=visns-packages-config
```

Then run the migrations:

```bash
php artisan migrate
```

## Features

-   **Enhanced Authentication System**

    -   Username/email login support
    -   User registration
    -   Password reset functionality
    -   Social authentication integration with Laravel Socialite
    -   Two-factor authentication (2FA)
    -   API token authentication

-   **File Management**

    -   Polymorphic file relationships
    -   File uploads and storage
    -   File metadata management

-   **User Model & Management**

    -   Standardized User model for all projects
    -   User trait for extending functionality
    -   User profiles
    -   Two-factor authentication management
    -   Notification handling

-   **Role & Permission Management**

    -   Role-based access control
    -   Permission management
    -   User role assignment

-   **Report Builder**

    -   Database schema exploration
    -   Custom report creation with table joins and filters
    -   SQL query generation and execution
    -   Export reports to CSV, Excel (XLSX), and PDF formats
    -   Report saving and management

-   **PDF Generation**

    -   Generate PDFs from Laravel views
    -   Generate PDFs from HTML content
    -   Customizable paper size and orientation
    -   Support for custom PDF options

-   **Dynamic Controller**

    -   Automatic model detection from URL
    -   Advanced filtering with multiple operators
    -   OR conditions with `orKey` parameter
    -   Relationship filtering with `whereHas`
    -   **Intelligent Relationship Sorting** - Sort by related model fields using dot notation
    -   **JSON Field Sorting** - Sort by data within JSON columns
    -   Subquery-based sorting that preserves eager loading performance

## Database Migrations

### User Table Migrations

This package includes migrations that add necessary fields to the users table for enhanced authentication and 2FA support. The migrations check if fields exist before adding them, making them safe to run on existing databases.

**Added fields:**

-   `username` - Alternative login identifier
-   `provider`, `provider_id`, `provider_token`, `provider_refresh_token` - For social authentication
-   `two_factor_secret`, `two_factor_recovery_codes`, `two_factor_confirmed_at` - For two-factor authentication
-   `disabled` - Boolean flag to disable user accounts and prevent login

Update your User model's `$fillable` array to include these fields:

```php
protected $fillable = [
    'name',
    'email',
    'password',
    'username',
    'provider',
    'provider_id',
    'provider_token',
    'provider_refresh_token',
    'two_factor_secret',
    'two_factor_recovery_codes',
    'two_factor_confirmed_at',
    'disabled',
];

protected $casts = [
    'email_verified_at' => 'datetime',
    'two_factor_confirmed_at' => 'datetime',
    'disabled' => 'boolean',
];
```

### File Table Migrations

The package also includes migrations for a `files` table that supports polymorphic relationships with any model in your application.

**File table fields:**

-   `fileable_id`, `fileable_type`, `fileable_field` - For polymorphic relationships
-   `file_path`, `file_name`, `file_extension`, `file_size` - File metadata
-   `file_title`, `file_description`, `sort_order` - Additional metadata

If you're using the File model, ensure it has these fields in the `$fillable` array:

```php
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
    'sort_order',
];
```

### Report Builder Migrations

The package includes migrations for a `report_builders` table that stores custom report configurations.

**Report Builder table fields:**

-   `label` - Name of the report
-   `detail` - JSON field storing the report configuration (tables, columns, joins, filters, etc.)
-   `user_id` - User who created the report
-   `is_public` - Whether the report is public or private

The ReportBuilder model has these fields in the `$fillable` array and appropriate casts:

```php
protected $fillable = [
    'label',
    'detail',
    'user_id',
    'is_public',
];

protected $casts = [
    'detail' => 'array',
    'is_public' => 'boolean',
];
```

## Authentication System

### Basic Authentication

The package provides an `AuthController` with methods for handling user authentication:

-   `authenticate` - Handles login with username or email
-   `register` - Registers a new user
-   `logout` - Handles user logout
-   `forgot` - Initiates password reset
-   `reset` - Completes password reset

### Two-Factor Authentication (2FA)

The package provides built-in two-factor authentication using Google Authenticator, Microsoft Authenticator, or any other TOTP-compatible app:

#### Setup

To use two-factor authentication, ensure your User model has the necessary fields:

```php
protected $fillable = [
    // ... other fields
    'two_factor_secret',
    'two_factor_recovery_codes',
    'two_factor_confirmed_at',
];
```

The package uses Google2FA for generating and validating two-factor authentication codes. It provides all the controllers and functionality needed to enable, confirm, and validate 2FA codes without requiring any additional packages. By default, the name that appears in authenticator apps is "Laravel", but you can customize this by setting the `2fa_app_name` option in the configuration file.

#### Authentication Flow with 2FA

The `AuthController` has been enhanced to support 2FA:

-   `authenticate` - Checks if 2FA is required after validating credentials
-   `twoFactorChallenge` - Shows the 2FA challenge page
-   `twoFactorAuthenticate` - Validates the 2FA code and completes login

#### Remember Me for 2FA

The package includes a "Remember Me" feature for two-factor authentication that allows users to skip 2FA prompts for 30 days on trusted devices:

-   When authenticating with 2FA, users can check a "Remember this device" option
-   The system will create a device-specific token that expires after 30 days
-   On subsequent logins from the same device, 2FA verification will be skipped until the token expires

This feature works for both web and API authentication flows.

Add these routes to your application:

```php
// Web routes
Route::post('/login', [
    \Visnsstudio\VisnsPackages\Controllers\AuthController::class,
    'authenticate',
])->name('login');
Route::get('/two-factor-challenge', [
    \Visnsstudio\VisnsPackages\Controllers\AuthController::class,
    'twoFactorChallenge',
])->name('two-factor.challenge');
Route::post('/two-factor-challenge', [
    \Visnsstudio\VisnsPackages\Controllers\AuthController::class,
    'twoFactorAuthenticate',
])->name('two-factor.authenticate');
```

When submitting the 2FA code, include a `remember` parameter to enable the "Remember Me" feature:

```javascript
// Example frontend code (using fetch API)
fetch('/two-factor-challenge', {
    method: 'POST',
    headers: {
        'Content-Type': 'application/json',
        'X-CSRF-TOKEN': csrfToken,
    },
    body: JSON.stringify({
        code: '123456', // The 2FA code from authenticator app
        remember: true, // Enable 30-day remember for this device
    }),
});
```

#### Managing 2FA

The `UserController` provides methods for managing 2FA:

-   `enableTwoFactorAuth` - Enables 2FA and returns QR code and recovery codes
-   `confirmTwoFactorAuth` - Confirms 2FA setup by validating the first code
-   `disableTwoFactorAuth` - Disables 2FA
-   `regenerateRecoveryCodes` - Generates new recovery codes
-   `getTwoFactorStatus` - Gets current 2FA status

Add these routes to your application:

```php
// Web routes (protected by auth middleware)
Route::middleware(['auth'])->group(function () {
    Route::post('/user/two-factor-auth', [
        \Visnsstudio\VisnsPackages\Controllers\UserController::class,
        'enableTwoFactorAuth',
    ])->name('user.two-factor.enable');

    Route::post('/user/two-factor-auth/confirm', [
        \Visnsstudio\VisnsPackages\Controllers\UserController::class,
        'confirmTwoFactorAuth',
    ])->name('user.two-factor.confirm');

    Route::delete('/user/two-factor-auth', [
        \Visnsstudio\VisnsPackages\Controllers\UserController::class,
        'disableTwoFactorAuth',
    ])->name('user.two-factor.disable');

    Route::post('/user/two-factor-auth/recovery-codes', [
        \Visnsstudio\VisnsPackages\Controllers\UserController::class,
        'regenerateRecoveryCodes',
    ])->name('user.two-factor.recovery-codes');

    Route::get('/user/two-factor-auth', [
        \Visnsstudio\VisnsPackages\Controllers\UserController::class,
        'getTwoFactorStatus',
    ])->name('user.two-factor.status');
});
```

### Social Authentication

The package integrates with Laravel Socialite to provide social authentication:

-   `redirectToProvider` - Redirects the user to the OAuth provider (like Google, Facebook, Azure, etc.)
-   `handleProviderCallback` - Handles the callback from the OAuth provider and logs the user in

To use social authentication, you need to install Laravel Socialite and configure your OAuth providers in `config/services.php`. The package automatically registers the necessary routes.

### API Authentication

The package supports API authentication with or without 2FA:

-   `login_api` - Handles API login and returns tokens
-   `register` - Registers a new user and returns a token
-   `twoFactorAuthenticateApi` - Handles 2FA for API requests

Add these routes to your application:

```php
// API routes
Route::post('/api/login', [
    \Visnsstudio\VisnsPackages\Controllers\AuthController::class,
    'login_api',
]);
Route::post('/api/register', [
    \Visnsstudio\VisnsPackages\Controllers\AuthController::class,
    'register',
]);
Route::post('/api/two-factor-challenge', [
    \Visnsstudio\VisnsPackages\Controllers\AuthController::class,
    'twoFactorAuthenticateApi',
]);
```

For user registration via API:

```javascript
// Example API client code for registration
axios
    .post('/api/register', {
        name: 'John Doe',
        email: 'john@example.com',
        username: 'johndoe',
        password: 'password123',
        password_confirmation: 'password123',
    })
    .then((response) => {
        // Response contains the user object and API token
        const token = response.data.id;
        const user = response.data.user;
    });
```

For API authentication with 2FA remember feature:

```javascript
// Example API client code
axios.post('/api/two-factor-challenge', {
    user_id: userId, // The user ID returned from login_api
    code: '123456', // The 2FA code from authenticator app
    remember: true, // Enable 30-day remember for this device
    device_identifier: 'my-mobile-app-unique-id', // Optional device identifier
});
```

The `device_identifier` parameter is optional. If not provided, the system will generate one based on the user agent and IP address.

## File Management

The package includes a `File` model that supports polymorphic relationships, allowing you to attach files to any model in your application.

The `File` model provides methods for:

-   Storing file metadata
-   Generating file URLs
-   Managing file relationships

## User Model

The package provides a User model that can be used in your projects. This allows you to standardize your User model across multiple projects and makes it easier to update functionality in one place.

### Using the Package User Model

There are two ways to use the package's User model in your projects:

#### Option 1: Use the Package User Model Directly

You can configure your application to use the package's User model directly by setting the `user_model` configuration option in `config/visns-packages.php`:

```php
'user_model' => 'Visnsstudio\\VisnsPackages\\Models\\User',
```

Or by setting the `VISNS_USER_MODEL` environment variable:

```
VISNS_USER_MODEL=Visnsstudio\\VisnsPackages\\Models\\User
```

#### Option 2: Use the UsePackageUser Trait

If you need to customize the User model in your project but still want to inherit functionality from the package's User model, you can use the `UsePackageUser` trait:

```php
<?php

namespace App\Models;

use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;
use Spatie\Permission\Traits\HasRoles;
use OwenIt\Auditing\Contracts\Auditable;
use Visnsstudio\VisnsPackages\Traits\UsePackageUser;

class User extends Authenticatable implements Auditable
{
    use \OwenIt\Auditing\Auditable;
    use HasApiTokens, HasFactory, Notifiable, HasRoles;
    use UsePackageUser; // Add this trait to inherit package functionality

    /**
     * The attributes that are mass assignable.
     *
     * @var array
     */
    protected $fillable = [
        'name',
        'email',
        'username',
        'password',
        'dashboard_settings',
        'provider',
        'provider_id',
        'provider_token',
        'provider_refresh_token',
        'two_factor_secret',
        'two_factor_recovery_codes',
        'two_factor_confirmed_at',
        'signature',
        'disabled',
    ];

    // You can override or add methods as needed
}
```

The `UsePackageUser` trait provides the following functionality:

-   `loadableRelations()` - Returns the relations that should be loaded with the user
-   `validationRules()` - Returns validation rules for the user model
-   `settings()` attribute - Returns user settings based on environment variables
-   `scopeCustomOrder()` - Scope for ordering users
-   `scopeCustomSearch()` - Scope for searching users
-   `scopeActive()` - Scope to filter only active (not disabled) users
-   `scopeDisabled()` - Scope to filter only disabled users
-   `twoFactorRememberTokens()` - Relationship to two-factor remember tokens

You can extend the default loadable relations by adding additional relations to the `user_additional_loadable_relations` configuration option in `config/visns-packages.php`:

```php
'user_additional_loadable_relations' => ['profile', 'settings', 'customRelation'],
```

This will merge these additional relations with the default `roles.permissions` relation.

#### Dynamic Relationships

You can also define dynamic relationships for the User model in the configuration file. This allows you to add relationships to the User model without having to create a custom User model class. The package will automatically create the relationship methods based on the configuration.

To define dynamic relationships, add them to the `user_dynamic_relationships` configuration option in `config/visns-packages.php`:

```php
'user_dynamic_relationships' => [
    'profile' => [
        'type' => 'hasOne',
        'model' => 'App\\Models\\Profile',
        'foreign_key' => 'user_id',
        'local_key' => 'id',
    ],
    'posts' => [
        'type' => 'hasMany',
        'model' => 'App\\Models\\Post',
        'foreign_key' => 'user_id',
        'local_key' => 'id',
    ],
    'tags' => [
        'type' => 'belongsToMany',
        'model' => 'App\\Models\\Tag',
        'pivot_table' => 'user_tag',
        'pivot_foreign_key' => 'user_id',
        'pivot_related_key' => 'tag_id',
    ],
],
```

The package supports all Laravel relationship types:

-   `hasOne`
-   `hasMany`
-   `belongsTo`
-   `belongsToMany`
-   `morphOne`
-   `morphMany`
-   `morphToMany`

Each relationship type requires different parameters. See the Laravel documentation for more information on the parameters for each relationship type.

These dynamic relationships will be automatically added to the `loadableRelations` array, so they will be loaded when using the `load()` method on the User model.

## User Management

The package includes functionality to disable user accounts, which prevents users from logging in. When a user is disabled, they will receive an error message when attempting to log in.

### Disabling Users

You can disable a user by setting the `disabled` field to `true`:

```php
$user = User::find(1);
$user->disabled = true;
$user->save();
```

You can also use the provided scopes to filter users:

```php
// Get only active (not disabled) users
$activeUsers = User::active()->get();

// Get only disabled users
$disabledUsers = User::disabled()->get();
```

### User Management API

The `UserController` provides methods for managing user profiles and notifications:

-   `profile` - Gets the user's profile
-   `notifications` - Gets the user's unread notifications
-   `notificationTable` - Gets paginated notifications
-   `markAsRead` - Marks notifications as read

## Role & Permission Management

The package integrates with Spatie's Laravel Permission package to provide role and permission management:

### Permission Management

The `PermissionController` provides methods for managing permissions:

-   `index` - Lists all permissions
-   `store` - Creates a new permission
-   `show` - Gets a specific permission
-   `update` - Updates a permission
-   `destroy` - Deletes a permission
-   `table` - Gets paginated permissions for tables
-   `dropdown` - Gets permissions for dropdown lists

### Role Management

The `RoleController` provides methods for managing roles:

-   `index` - Lists all roles
-   `store` - Creates a new role
-   `show` - Gets a specific role
-   `update` - Updates a role
-   `destroy` - Deletes a role
-   `table` - Gets paginated roles for tables
-   `dropdown` - Gets roles for dropdown lists

## Report Builder

The package includes a powerful report builder system that allows users to create custom reports by exploring the database schema, selecting tables and columns, configuring joins and filters, and executing SQL queries. Reports can be exported to CSV, Excel (XLSX), and PDF formats.

### Database Schema Exploration

The `ReportBuilderController` provides methods for exploring the database schema:

-   `getTables` - Gets all tables in the database
-   `getTablesSimple` - Gets simplified list of tables
-   `getTableColumns` - Gets columns for a specific table
-   `getAllTablesAndColumns` - Gets all tables and their columns
-   `getTableRelationships` - Gets relationships for a specific table
-   `getColumnTypeInfo` - Gets detailed type information for a specific column
-   `getSuggestedJoins` - Gets AI-powered join suggestions between tables
-   `getJsonFieldKeys` - Extracts keys from JSON columns in your data

### Report Configuration

Users can create custom reports by:

1. Selecting a main table
2. Adding columns from the main table and joined tables
3. Configuring table joins (INNER, LEFT, RIGHT)
4. Adding filters with various operators
5. Setting up sorting and grouping

The report configuration is stored as a JSON object in the `detail` field of the `report_builders` table.

### Executing Reports

The `executeQuery` method in the `ReportBuilderController` allows executing a report by:

1. Accepting a report configuration or a saved report ID
2. Building a dynamic SQL query based on the configuration
3. Executing the query and returning the results

Example request to execute a report:

```javascript
fetch('/ajax/reportBuilder/execute', {
    method: 'POST',
    headers: {
        'Content-Type': 'application/json',
    },
    body: JSON.stringify({
        query: {
            mainTable: 'users',
            columns: [
                { table: 'users', column: 'id' },
                { table: 'users', column: 'name' },
                { table: 'users', column: 'email' },
            ],
            filters: [
                {
                    table: 'users',
                    column: 'created_at',
                    operator: '>',
                    value: '2023-01-01',
                },
            ],
            sorting: [{ table: 'users', column: 'name', direction: 'asc' }],
        },
        limit: 50,
        offset: 0,
    }),
});
```

### Exporting Reports

The Report Builder includes functionality to export report data in CSV or Excel (XLSX) format. The `exportReport` method in the `ReportBuilderController` allows exporting a report by:

1. Accepting a report configuration or a saved report ID
2. Building a dynamic SQL query based on the configuration
3. Executing the query and generating a downloadable file in the requested format

Example request to export a report:

```javascript
// Create a form to handle the file download
const form = document.createElement('form');
form.method = 'POST';
form.action = '/ajax/reportBuilder/export';

// Add CSRF token if needed
const csrfToken = document
    .querySelector('meta[name="csrf-token"]')
    .getAttribute('content');
const csrfInput = document.createElement('input');
csrfInput.type = 'hidden';
csrfInput.name = '_token';
csrfInput.value = csrfToken;
form.appendChild(csrfInput);

// Add report configuration
const queryInput = document.createElement('input');
queryInput.type = 'hidden';
queryInput.name = 'query';
queryInput.value = JSON.stringify({
    mainTable: 'users',
    columns: [
        { table: 'users', column: 'id' },
        { table: 'users', column: 'name' },
        { table: 'users', column: 'email' },
    ],
    filters: [
        {
            table: 'users',
            column: 'created_at',
            operator: '>',
            value: '2023-01-01',
        },
    ],
    sorting: [{ table: 'users', column: 'name', direction: 'asc' }],
});
form.appendChild(queryInput);

// Add format (csv or xlsx)
const formatInput = document.createElement('input');
formatInput.type = 'hidden';
formatInput.name = 'format';
formatInput.value = 'xlsx'; // or 'csv'
form.appendChild(formatInput);

// Submit the form to trigger the download
document.body.appendChild(form);
form.submit();
document.body.removeChild(form);
```

Alternatively, you can export a saved report by providing its ID:

```javascript
const reportIdInput = document.createElement('input');
reportIdInput.type = 'hidden';
reportIdInput.name = 'report_id';
reportIdInput.value = '123'; // The ID of the saved report
form.appendChild(reportIdInput);
```

The exported file will be named with the format `YYYYMMDD_ReportName.format` (e.g., `20230101_UserReport.xlsx`).

### Saving and Managing Reports

The `ReportBuilderController` also provides methods for saving and managing reports:

-   `getReports` - Gets all reports accessible to the user
-   `getReport` - Gets a specific report by ID
-   `saveReport` - Saves a new report
-   `updateReport` - Updates an existing report
-   `deleteReport` - Deletes a report

Users can save their report configurations for later use and share them with other users by making them public.

## PDF Generation

The package includes a powerful PDF generation system using the Laravel-DomPDF package. This allows you to generate PDFs from Laravel views or HTML content with customizable options.

### Generating PDFs from Views

The `PDFController` provides a `generatePDF` method that allows you to generate a PDF from a Laravel view:

```javascript
fetch('/ajax/pdf/generate', {
    method: 'POST',
    headers: {
        'Content-Type': 'application/json',
    },
    body: JSON.stringify({
        view: 'pdf.invoice',
        data: {
            invoice: {
                /* invoice data */
            },
            customer: {
                /* customer data */
            },
        },
        filename: 'invoice-123.pdf',
        paper: 'a4',
        orientation: 'portrait',
        download: true,
    }),
});
```

### Generating PDFs from HTML

The `PDFController` also provides a `generatePDFFromHTML` method that allows you to generate a PDF from raw HTML content:

```javascript
fetch('/ajax/pdf/generate-from-html', {
    method: 'POST',
    headers: {
        'Content-Type': 'application/json',
    },
    body: JSON.stringify({
        html: '<html><body><h1>Hello World</h1></body></html>',
        filename: 'hello.pdf',
        paper: 'a4',
        orientation: 'portrait',
        download: true,
    }),
});
```

### Custom PDF Options

For more advanced use cases, the `PDFController` provides a `generateCustomPDF` method that allows you to specify custom options for the PDF generation:

```javascript
fetch('/ajax/pdf/generate-custom', {
    method: 'POST',
    headers: {
        'Content-Type': 'application/json',
    },
    body: JSON.stringify({
        view: 'pdf.report',
        data: {
            /* report data */
        },
        filename: 'report.pdf',
        options: {
            isRemoteEnabled: true,
            isHtml5ParserEnabled: true,
        },
        download: true,
    }),
});
```

### Generating Quote PDFs

The package also provides a specialized method for generating quote PDFs:

```javascript
fetch('/ajax/pdf/generate-quote', {
    method: 'POST',
    headers: {
        'Content-Type': 'application/json',
    },
    body: JSON.stringify({
        quote_id: 123,
        template: 'quotes.default',
        download: true
    }),
});
```

The same endpoints are also available via the API with authentication required:

-   `POST /api/pdf/generate`
-   `POST /api/pdf/generate-from-html`
-   `POST /api/pdf/generate-custom`
-   `POST /api/pdf/generate-quote`

## Dynamic Controller

The package includes a `DynamicController` that provides a flexible way to interact with any model in your application. It automatically determines the model based on the URL path and provides standard CRUD operations and filtering capabilities.

## Intelligent Relationship Sorting

The package includes a powerful `HasRelationshipSorting` trait that enables sorting by related model fields and JSON data without breaking eager loading performance. This trait should be added to all models that need advanced sorting capabilities.

### Using the HasRelationshipSorting Trait

Add the trait to your models:

```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Visnsstudio\VisnsPackages\Traits\HasRelationshipSorting;

class User extends Model
{
    use HasRelationshipSorting;
    
    // Your model code here...
}
```

### Relationship Sorting

Sort by related model fields using dot notation:

```bash
# Sort users by their profile name
GET /ajax/users/table?orderBy=profile.name&order=asc

# Sort orders by customer company name
GET /ajax/orders/table?orderBy=customer.company&order=desc

# Sort posts by author's last name
GET /ajax/posts/table?orderBy=author.last_name&order=asc
```

### JSON Field Sorting

Sort by data within JSON columns:

```bash
# Sort by a specific key in a JSON field
GET /ajax/users/table?orderBy=settings.theme&order=asc

# Sort by nested JSON data
GET /ajax/products/table?orderBy=metadata.specifications.weight&order=desc
```

### Supported Relationship Types

The trait supports all Laravel relationship types:

- **BelongsTo**: `user.profile.name`
- **HasOne**: `order.shipping_address.city`
- **HasMany**: `category.products.count` (aggregated)
- **BelongsToMany**: `user.roles.name` (through pivot)

### Implementation Details

The trait uses subqueries instead of joins to maintain performance:

- Preserves eager loading functionality
- Prevents duplicate rows from joins
- Optimized for large datasets
- Supports complex relationship chains

### Model Requirements

Models using this trait should implement:

1. **Relationship methods**: Standard Laravel relationships
2. **JSON field detection**: Automatic detection of JSON columns
3. **Fallback handling**: Graceful fallback to standard sorting

Example model with relationship:

```php
class User extends Model
{
    use HasRelationshipSorting;
    
    protected $casts = [
        'settings' => 'array', // JSON field
        'metadata' => 'array', // JSON field
    ];
    
    public function profile()
    {
        return $this->hasOne(Profile::class);
    }
    
    public function orders()
    {
        return $this->hasMany(Order::class);
    }
    
    public function roles()
    {
        return $this->belongsToMany(Role::class);
    }
}
```

### Automatic Model Update Command

The package includes an Artisan command that automatically discovers and updates all models in your project to use the `HasRelationshipSorting` trait:

```bash
# Update all models automatically
php artisan visns:update-models-sorting

# Preview changes without applying them (recommended first run)
php artisan visns:update-models-sorting --dry-run

# Create backup files before modifying
php artisan visns:update-models-sorting --backup

# Skip confirmation prompts
php artisan visns:update-models-sorting --force

# Custom model path and namespace
php artisan visns:update-models-sorting --path=app/Models --namespace=App\\Models
```

### Command Usage Guide

**Basic Usage:**
```bash
# Step 1: Always preview changes first (recommended)
php artisan visns:update-models-sorting --dry-run

# Step 2: Apply changes with backup (safest option)
php artisan visns:update-models-sorting --backup

# Alternative: Apply changes without backup (if you have version control)
php artisan visns:update-models-sorting
```

**Advanced Options:**
```bash
# Skip confirmation prompts (useful for automation)
php artisan visns:update-models-sorting --force

# Custom model location (if not using standard app/Models)
php artisan visns:update-models-sorting --path=src/Models --namespace=MyApp\\Models

# Combine options for CI/CD environments
php artisan visns:update-models-sorting --dry-run --path=app/Models --namespace=App\\Models
```

**Integration Workflow for New Projects:**
```bash
# Standard installation process
composer require visnsstudio/visns-packages
php artisan vendor:publish --tag=visns-packages-migrations
php artisan migrate

# Add relationship sorting to all models
php artisan visns:update-models-sorting --dry-run    # Preview changes
php artisan visns:update-models-sorting --backup     # Apply with backups
```

**Troubleshooting:**
If the command is not available after installation:
```bash
# Clear Laravel caches
php artisan cache:clear
php artisan config:clear
php artisan route:clear

# Refresh Composer autoloader
composer dump-autoload

# Verify package installation
composer show visnsstudio/visns-packages
```

**What the command does:**

1. **Model Discovery**: Automatically finds all Eloquent models in your project
2. **Trait Integration**: Adds the `HasRelationshipSorting` trait import and usage
3. **Legacy Cleanup**: Removes old basic `scopeCustomOrder` methods that conflict with the trait
4. **Safe Updates**: Creates backups and provides dry-run mode for safety
5. **Smart Detection**: Only updates models that actually need changes

**Example output:**

```
🔍 Discovering models in your project...
Found 25 models:
  - App\Models\User (app/Models/User.php)
  - App\Models\Order (app/Models/Order.php)
  - App\Models\Product (app/Models/Product.php)
  ...

📝 Models that need updating:
  - App\Models\User (needs trait import, needs trait usage)
  - App\Models\Order (has old scopeCustomOrder method)
  - App\Models\Product (needs trait import, needs trait usage, has old scopeCustomOrder method)

🚀 Updating models...
  ✅ Updated App\Models\User
  ✅ Updated App\Models\Order
  ✅ Updated App\Models\Product

📊 Summary:
  ✅ Successfully updated: 15
  🎉 Models have been updated to use HasRelationshipSorting trait!
```

This command is perfect for integrating the relationship sorting functionality into existing projects without manual file modifications.

### Search Integration

The Dynamic Controller supports advanced search functionality with automatic Meilisearch integration when available.

#### Meilisearch Support

The Dynamic Controller will automatically use Meilisearch for searching when:
1. Laravel Scout is installed and configured
2. The Scout driver is set to 'meilisearch'
3. The model uses the `Laravel\Scout\Searchable` trait
4. The Meilisearch server is healthy and accessible

If any of these conditions are not met, the controller will gracefully fall back to the default database search using the model's `customSearch` scope.

#### Configuring Meilisearch

To enable Meilisearch for your models:

1. Install Laravel Scout and Meilisearch:
```bash
composer require laravel/scout meilisearch/meilisearch-php
```

2. Configure Scout in your `.env`:
```env
SCOUT_DRIVER=meilisearch
MEILISEARCH_HOST=http://localhost:7700
MEILISEARCH_KEY=your-master-key
```

3. Add the `Searchable` trait to your model:
```php
use Laravel\Scout\Searchable;

class Product extends Model
{
    use Searchable;
    
    // Define searchable fields
    public function toSearchableArray()
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'description' => $this->description,
            'sku' => $this->sku,
        ];
    }
}
```

4. Index your existing data:
```bash
php artisan scout:import "App\Models\Product"
```

#### Disabling Meilisearch

You can force disable Meilisearch integration by setting the configuration option:

```php
// In config/visns-packages.php
'search' => [
    'force_disable_meilisearch' => true,
],
```

Or via environment variable:
```env
VISNS_DISABLE_MEILISEARCH=true
```

#### Meilisearch Management Commands

The package includes three helpful Artisan commands for managing and debugging Meilisearch:

**Configure Meilisearch Index Settings**
```bash
# Show current configuration for all indexes
php artisan meilisearch:configure --show

# Apply configuration from config/scout.php to all indexes
php artisan meilisearch:configure --apply

# Configure only a specific model's index
php artisan meilisearch:configure --apply --model=User
```

**Debug Meilisearch Connection and Indexes**
```bash
# Auto-discover and debug searchable models
php artisan meilisearch:debug

# Debug a specific model
php artisan meilisearch:debug --model=Customer
```

**Test Meilisearch Search Functionality**
```bash
# Test search with default User model
php artisan meilisearch:test "search query"

# Test search on specific model
php artisan meilisearch:test "John" --model=Customer

# Test search with filters and custom limit
php artisan meilisearch:test "active" --filters="status = active" --limit=20
```

**Sync Models with Meilisearch**
```bash
# Sync all searchable models
php artisan meilisearch:sync

# Sync specific model
php artisan meilisearch:sync --model=Customer

# Flush index before syncing (removes all existing data)
php artisan meilisearch:sync --flush

# Sync with custom batch size
php artisan meilisearch:sync --chunk=50

# Skip confirmation prompt
php artisan meilisearch:sync --force

# Sync models from custom namespace or path
php artisan meilisearch:sync --namespace="MyPackage\Models"
php artisan meilisearch:sync --path="/path/to/models"
```

These commands are particularly useful during development and deployment to ensure your Meilisearch integration is working correctly and to troubleshoot search issues.

#### Search Usage

Search functionality works automatically with the table and dropdown endpoints:

```javascript
// Search in table view
fetch('/ajax/products/table?search=laptop')

// Search in dropdown with async loading
fetch('/ajax/products/dropdown', {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({
        where: [
            { id: 'async', value: 'laptop' }
        ]
    })
})
```

The search will use Meilisearch if available, providing:
- Full-text search capabilities
- Typo tolerance
- Faster search performance for large datasets
- Relevance-based ranking

If Meilisearch is not available or fails, the search will automatically fall back to SQL LIKE queries using the model's `customSearch` scope.

### Basic Usage

The `DynamicController` is accessed through URLs like:

```
/ajax/{model_name}/{action}
```

For example:

-   `/ajax/users/table` - Get a paginated list of users
-   `/ajax/products/dropdown` - Get products for a dropdown list
-   `/ajax/orders/show/123` - Get a specific order by ID
-   `/ajax/users/merge` - Merge two user models

### Available Actions

The `DynamicController` provides the following actions:

-   `/ajax/{model}/table` - Get a paginated list
-   `/ajax/{model}/list` - Get a non-paginated list
-   `/ajax/{model}/dropdown` - Get data for dropdown lists
-   `/ajax/{model}/dropdownWithGroups` - Get dropdown data with parent-child grouping
-   `/ajax/{model}/show/{id}` - Get a specific record
-   `/ajax/{model}/store` - Create a new record (POST)
-   `/ajax/{model}/update/{id}` - Update an existing record (PUT)
-   `/ajax/{model}/destroy/{id}` - Delete a record (DELETE)
-   `/ajax/{model}/clone/{id}` - Clone a record
-   `/ajax/{model}/merge` - Merge two models (POST)
-   `/ajax/{model}/sort_list` - Get sortable list
-   `/ajax/{model}/sort_update` - Update sort order (POST)
-   `/ajax/{model}/template_sort/{id}` - Sort template details (POST)
-   `/ajax/{model}/gallery/{id}` - Update gallery images (POST)

### Filtering

The `DynamicController` supports advanced filtering through the `where` parameter:

```

/ajax/users/table?where[0][id]=name&where[0][value]=John&where[0][operator]=contains

```

This will filter users where the name contains "John".

#### Available Filter Operators

-   `=` (default) - Exact match
-   `contains` - Contains the value (like %value%)
-   `not_contains` - Does not contain the value
-   `gt` - Greater than
-   `gte` - Greater than or equal to
-   `lt` - Less than
-   `lte` - Less than or equal to
-   `inlist` - Value is in a list
-   `notinlist` - Value is not in a list
-   `inrange` - Value is in a range
-   `is_null` - Field is null

#### OR Conditions with orKey

You can create OR conditions in your filters using the `orKey` parameter:

```

/ajax/users/table?where[0][id]=name&where[0][value]=John&where[0][orKey]=email

```

This will filter users where `name = 'John' OR email = 'John'`.

#### Relationship Filtering with whereHas

You can filter based on relationships using the `whereHas` parameter:

```

/ajax/users/table?where[0][id]=name&where[0][value]=John&where[0][whereHas]=posts

```

This will filter users who have posts AND whose name is "John".

#### Combining OR Conditions with Relationships

You can combine `orKey` and `whereHas` to create complex filters:

```
/ajax/users/table?where[0][id]=name&where[0][value]=John&where[0][orKey]=email&where[0][whereHas]=posts
```

This will filter users who have posts AND (`name = 'John' OR email = 'John'`).

### Model Merging

The `DynamicController` provides a powerful model merging functionality that allows you to combine attributes and relationships from two models. This is useful for consolidating duplicate records, creating templates, or migrating data.

#### Basic Merge

To merge two models, send a POST request to:

```
/ajax/{model_name}/merge
```

With the following parameters:

```json
{
    "target_id": 1,
    "source_id": 2
}
```

This will merge the attributes from the source model (ID 2) into the target model (ID 1). The target model will be updated in the database, while the source model remains unchanged.

#### Advanced Merge Options

You can customize the merge behavior with additional parameters:

```json
{
    "target_id": 1,
    "source_id": 2,
    "relationships": ["profile", "roles"],
    "attributes": ["name", "email", "settings"],
    "exclude": ["id", "created_at", "updated_at", "deleted_at"],
    "overwriteWithNull": false,
    "mergeTimestamps": false
}
```

-   `relationships`: Array of relationship names to merge
-   `attributes`: Array of specific attributes to merge (if empty, all attributes are merged)
-   `exclude`: Array of attributes to exclude from merging
-   `overwriteWithNull`: Whether to overwrite non-null values with null values (default: false)
-   `mergeTimestamps`: Whether to merge timestamp fields (default: false)
-   `prioritizeSource`: Whether to prioritize source model attributes over target (default: false)

#### Relationship Handling

The merge function handles different types of relationships:

-   **HasOne/BelongsTo**: The related model is cloned and attached to the target model
-   **HasMany/BelongsToMany**: Each related model is cloned and attached to the target model

#### API Access

The merge functionality is also available via the API:

```
POST /api/{model_name}/merge
```

This endpoint requires authentication with a valid API token.

### Advanced Features

#### Nested Object Support

The Dynamic Controller supports creating and updating related models through nested objects in your request data:

```javascript
// Create a user with a profile
fetch('/ajax/users/store', {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({
        name: 'John Doe',
        email: 'john@example.com',
        profile: {
            bio: 'Software Developer',
            website: 'https://example.com'
        }
    })
});
```

#### File Upload Handling

The controller automatically handles file uploads for models with polymorphic file relationships:

```javascript
const formData = new FormData();
formData.append('name', 'Product Name');
formData.append('images[]', file1);
formData.append('images[]', file2);

fetch('/ajax/products/store', {
    method: 'POST',
    body: formData
});
```

#### Sort Order Management

Update the sort order of multiple models:

```javascript
fetch('/ajax/products/sort_update', {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({
        sort_order: [3, 1, 4, 2] // New order for product IDs
    })
});
```

#### Model Cloning

Clone an existing model:

```javascript
fetch('/ajax/products/clone/123', {
    method: 'GET'
});
// Creates a new product with "(Clone)" appended to the name
```

## Dynamic JSON Controller

The package includes a `DynamicJsonController` that allows you to manage JSON data stored within model fields. This is useful for managing arrays of structured data without creating separate database tables.

### JSON Data Management

The controller works with models that have JSON/array fields and provides CRUD operations for the data within those fields.

### Available JSON Actions

-   `/ajax/{model_name}/json/{field}/sort_list` - Get sortable list of JSON data
-   `/ajax/{model_name}/json/{field}/sort_update` - Update sort order
-   `/ajax/{model_name}/json/{field}/get/{json_id}` - Get specific JSON item
-   `/ajax/{model_name}/json/{field}/table` - Get paginated table of JSON data
-   `/ajax/{model_name}/json/{field}/store` - Add new JSON item
-   `/ajax/{model_name}/json/{field}/update/{json_id}` - Update JSON item
-   `/ajax/{model_name}/json/{field}/delete/{json_id}` - Delete JSON item

Example usage:

```javascript
// Add a new item to a JSON array field
fetch('/ajax/settings/json/menu_items/store', {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({
        model_id: 1,
        data: {
            title: 'New Menu Item',
            url: '/new-page',
            icon: 'home'
        }
    })
});
```

## Audit System

The package includes a comprehensive audit system that tracks all changes to your models using the Laravel Auditing package.

### Audit Logging

Models that use auditing will automatically track:
- Create, update, and delete events
- Old and new values for each change
- User who made the change
- IP address and user agent
- URL where the change was made

### Viewing Audit History

The `AuditController` provides methods to view audit history:

```javascript
// Get audit details for a specific audit record
fetch('/ajax/audits/123')
    .then(response => response.json())
    .then(audit => {
        console.log(audit.event); // created, updated, deleted
        console.log(audit.old_values);
        console.log(audit.new_values);
        console.log(audit.user);
    });

// Get paginated audit table
fetch('/ajax/audits/table', {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({
        page: 1,
        per_page: 20,
        where: [
            { id: 'auditable_type', value: 'App\\Models\\User' },
            { id: 'event', value: 'updated' }
        ]
    })
});
```

## Middleware

### AcceptJson Middleware

The package includes an `AcceptJson` middleware that automatically sets the `Accept: application/json` header for all incoming requests. This ensures consistent JSON responses from your API endpoints.

The middleware is automatically registered with the alias `accept-json` and is applied to all package API routes.

## Exception Handling

### JsonValidationException

The package provides a custom `JsonValidationException` that formats validation errors in a consistent JSON structure:

```php
use Visnsstudio\VisnsPackages\Exceptions\JsonValidationException;

// In your controller
$validator = Validator::make($request->all(), [
    'email' => 'required|email',
    'name' => 'required|string'
]);

if ($validator->fails()) {
    throw new JsonValidationException($validator);
}
```

This returns a properly formatted JSON response:

```json
{
    "message": "The given data was invalid.",
    "errors": {
        "email": ["The email field is required."],
        "name": ["The name field is required."]
    }
}
```

## Configuration

### Environment Variables

The package works with your existing Laravel configuration. Key environment variables used:

-   `APP_URL` - For generating URLs
-   `FRONT_END_URL` - For frontend redirects
-   `MAIL_FROM_ADDRESS` - For sending emails
-   `ALLOW_MULTIPLE_SESSIONS` - Controls multiple session behavior
-   `APP_NAME` - Used for the name in 2FA authenticator apps (can be overridden in config)
-   `VISNS_USER_MODEL` - Specify the User model class to use
-   `VISNS_COMPONENT_HEADER_STYLE_BACKGROUND` - Background color for component headers
-   `VISNS_COMPONENT_HEADER_STYLE_COLOR` - Text color for component headers
-   `VISNS_COMPONENT_SELECT_STYLE_HEIGHT` - Height for select components
-   `VISNS_COMPONENT_TINY_MCE_API_KEY` - TinyMCE API key for rich text editing
-   `VISNS_DISABLE_MEILISEARCH` - Force disable Meilisearch integration for search

### Package Configuration

The package provides a configuration file that can be published to your application:

```bash
php artisan vendor:publish --tag=visns-packages-config
```

This will create a `config/visns-packages.php` file with the following options:

```php
return [
    // Whether to automatically register the package routes
    'register_routes' => true,

    // The middleware to apply to the package routes
    'routes_middleware' => ['web'],

    // The prefix to apply to all package routes (leave empty for no prefix)
    'routes_prefix' => '',

    // The middleware to apply to the package API routes
    'api_middleware' => ['api', 'accept-json'],

    // The prefix to apply to all package API routes (default is 'api')
    'api_prefix' => 'api',

    // The User model class to be used by the package
    'user_model' => env('VISNS_USER_MODEL', 'App\\Models\\User'),

    // The name that appears in authenticator apps for 2FA
    '2fa_app_name' => env('APP_NAME', 'Your App Name'),

    // Additional relations to load with the User model
    'user_additional_loadable_relations' => [],

    // Dynamic relationships for the User model
    'user_dynamic_relationships' => [
        // Example:
        // 'profile' => [
        //     'type' => 'hasOne',
        //     'model' => 'App\\Models\\Profile',
        //     'foreign_key' => 'user_id',
        //     'local_key' => 'id',
        // ],
    ],

    // Search configuration
    'search' => [
        // Force disable Meilisearch integration
        'force_disable_meilisearch' => env('VISNS_DISABLE_MEILISEARCH', false),
    ],
];
```

### Automatic Route Registration

By default, the package automatically registers the following routes:

#### Authentication Routes

```php
// Login routes
Route::post('/login/authenticate', [AuthController::class, 'authenticate']);
Route::post('/login/two-factor-challenge', [
    AuthController::class,
    'twoFactorAuthenticate',
]);

// Password routes
Route::post('/password/forgot', [AuthController::class, 'forgot']);
Route::post('/password/reset', [AuthController::class, 'reset']);

// Logout route
Route::get('/logout', [AuthController::class, 'logout']);
```

#### User Routes

```php
// User notification routes
Route::post('/ajax/user/notifications/table', [
    UserController::class,
    'notificationTable',
]);
Route::post('/ajax/user/notifications', [
    UserController::class,
    'notifications',
]);
Route::post('/ajax/user/notification/markasread', [
    UserController::class,
    'markAsRead',
]);
Route::get('/ajax/user/profile', [UserController::class, 'profile']);

// Two-factor authentication management routes
Route::post('/ajax/user/two-factor-auth/enable', [
    UserController::class,
    'enableTwoFactorAuth',
]);
Route::post('/ajax/user/two-factor-auth/confirm', [
    UserController::class,
    'confirmTwoFactorAuth',
]);
Route::post('/ajax/user/two-factor-auth/disable', [
    UserController::class,
    'disableTwoFactorAuth',
]);
Route::post('/ajax/user/two-factor-auth/recovery-codes', [
    UserController::class,
    'regenerateRecoveryCodes',
]);
Route::get('/ajax/user/two-factor-auth', [
    UserController::class,
    'getTwoFactorStatus',
]);
```

#### File Management Routes

```php
// File management routes
Route::get('/ajax/files/{id}', [FileController::class, 'show']);
Route::put('/ajax/files/{id}', [FileController::class, 'update']);
Route::post('/ajax/files/delete', [FileController::class, 'delete']);
Route::get('/ajax/files/download/{id}/{folder?}', [
    FileController::class,
    'download',
]);
Route::get('/ajax/files/downloadContent/{id}', [
    FileController::class,
    'downloadContent',
]);
Route::post('/ajax/files/sort_update', [FileController::class, 'sort_update']);
```

#### Permission Management Routes

```php
// Permission management routes
Route::get('/ajax/permissions', [PermissionController::class, 'index']);
Route::post('/ajax/permissions', [PermissionController::class, 'store']);
Route::get('/ajax/permissions/{id}', [PermissionController::class, 'show']);
Route::put('/ajax/permissions/{id}', [PermissionController::class, 'update']);
Route::delete('/ajax/permissions/{id}', [
    PermissionController::class,
    'destroy',
]);
Route::post('/ajax/permissions/table', [PermissionController::class, 'table']);
Route::post('/ajax/permissions/dropdown', [
    PermissionController::class,
    'dropdown',
]);
```

#### Role Management Routes

```php
// Role management routes
Route::get('/ajax/roles', [RoleController::class, 'index']);
Route::post('/ajax/roles', [RoleController::class, 'store']);
Route::get('/ajax/roles/{id}', [RoleController::class, 'show']);
Route::put('/ajax/roles/{id}', [RoleController::class, 'update']);
Route::delete('/ajax/roles/{id}', [RoleController::class, 'destroy']);
Route::post('/ajax/roles/table', [RoleController::class, 'table']);
Route::post('/ajax/roles/dropdown', [RoleController::class, 'dropdown']);
```

#### Audit Routes

```php
// Audit routes
Route::get('/ajax/audits/{id}', [AuditController::class, 'show']);
Route::post('/ajax/audits/table', [AuditController::class, 'table']);
```

#### Report Builder Routes

```php
// Report Builder routes
Route::prefix('ajax')
    ->controller(ReportBuilderController::class)
    ->group(function () {
        // Database schema exploration
        Route::post('/reportBuilder/getTables', 'getTables');
        Route::post('/reportBuilder/getTableColumns', 'getTableColumns');
        Route::post(
            '/reportBuilder/getAllTablesAndColumns',
            'getAllTablesAndColumns'
        );
        Route::post(
            '/reportBuilder/getTableRelationships',
            'getTableRelationships'
        );
        Route::post('/reportBuilder/getColumnTypeInfo', 'getColumnTypeInfo');
        Route::post('/reportBuilder/getSuggestedJoins', 'getSuggestedJoins');
        Route::post('/reportBuilder/getTablesSimple', 'getTablesSimple');
        Route::post('/reportBuilder/getJsonFieldKeys', 'getJsonFieldKeys');

        // Report management
        Route::get('/reportBuilder/reports', 'getReports');
        Route::get('/reportBuilder/reports/{id}', 'getReport');
        Route::post('/reportBuilder/reports', 'saveReport');
        Route::put('/reportBuilder/reports/{id}', 'updateReport');
        Route::delete('/reportBuilder/reports/{id}', 'deleteReport');

        // Execute report query
        Route::post('/reportBuilder/execute', 'executeQuery');

        // Export report
        Route::post('/reportBuilder/export', 'exportReport');
    });
```

#### PDF Generation Routes

```php
// PDF Generation routes
Route::prefix('ajax/pdf')
    ->controller(PDFController::class)
    ->group(function () {
        Route::post('/generate', 'generatePDF');
        Route::post('/generate-from-html', 'generatePDFFromHTML');
        Route::post('/generate-custom', 'generateCustomPDF');
        Route::post('/generate-quote', 'generateQuotePDF');
        Route::post('/generate-proposal', 'generateProposalPDF');
        Route::post('/preview-proposal', 'previewProposalHTML');
    });

// PDF API routes
Route::prefix('api/pdf')
    ->controller(PDFController::class)
    ->middleware('auth:sanctum')
    ->group(function () {
        Route::post('/generate', 'generatePDF');
        Route::post('/generate-from-html', 'generatePDFFromHTML');
        Route::post('/generate-custom', 'generateCustomPDF');
        Route::post('/generate-quote', 'generateQuotePDF');
        Route::post('/generate-proposal', 'generateProposalPDF');
        Route::post('/preview-proposal', 'previewProposalHTML');
    });
```

#### Model Merge Routes

```php
// Dynamic model merge route
Route::post(
    'ajax/{model}/merge',
    'Visnsstudio\\VisnsPackages\\Controllers\\DynamicController@mergeModels'
);

// Dynamic model merge API route
Route::middleware('auth:sanctum')->post(
    'api/{model}/merge',
    'Visnsstudio\\VisnsPackages\\Controllers\\DynamicController@mergeModels'
);
```

#### Dynamic JSON Controller Routes

```php
// Dynamic JSON Controller routes
Route::prefix('ajax/{model}/json/{field}')
    ->controller(DynamicJsonController::class)
    ->group(function () {
        Route::get('/sort_list', 'jsonSortList');
        Route::post('/sort_update', 'jsonSortUpdate');
        Route::get('/get/{json_id}', 'jsonGet');
        Route::post('/table', 'jsonTable');
        Route::post('/store', 'jsonStore');
        Route::put('/update/{json_id}', 'jsonUpdate');
        Route::delete('/delete/{json_id}', 'jsonDelete');
    });
```

#### Social Authentication Routes

```php
// Socialite routes
Route::get('/auth/{provider}', [
    SocialiteController::class,
    'redirectToProvider',
]);
Route::get('/auth/{provider}/callback', [
    SocialiteController::class,
    'handleProviderCallback',
]);
```

#### API Routes

```php
// API Authentication routes
Route::post('/api/login', [AuthController::class, 'login_api']);
Route::post('/api/register', [AuthController::class, 'register']);
Route::post('/api/two-factor-challenge', [
    AuthController::class,
    'twoFactorAuthenticateApi',
]);
Route::post('/api/logout', [AuthController::class, 'logout_api']);

// API Socialite routes
Route::get('/api/auth/providers', [SocialiteController::class, 'getProviders']);
Route::get('/api/auth/status', [AuthController::class, 'status']);

// API User routes (require authentication)
Route::middleware('auth:sanctum')->group(function () {
    Route::get('/api/profile', [UserController::class, 'profile']);
});

// Dynamic model merge API route
Route::middleware('auth:sanctum')->post(
    '/api/{model}/merge',
    'Visnsstudio\\VisnsPackages\\Controllers\\DynamicController@mergeModels'
);
```

You can disable automatic route registration by setting `register_routes` to `false` in the configuration file, or customize the middleware and prefix applied to these routes.

## License

The MIT License (MIT). Please see [License File](LICENSE.md) for more information.
