# Visns Packages

A comprehensive Laravel package that provides enhanced authentication, file management, and two-factor authentication capabilities for Laravel applications.

## Table of Contents

-   [Visns Packages](#visns-packages)

    -   [Table of Contents](#table-of-contents)
    -   [Installation](#installation)
    -   [Features](#features)
    -   [Database Migrations](#database-migrations)
        -   [User Table Migrations](#user-table-migrations)
        -   [File Table Migrations](#file-table-migrations)
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
    -   [User Management](#user-management)
    -   [Role \& Permission Management](#role--permission-management)
        -   [Permission Management](#permission-management)
        -   [Role Management](#role-management)
    -   [Dynamic Controller](#dynamic-controller)
        -   [Basic Usage](#basic-usage)
        -   [Filtering](#filtering)
            -   [Available Filter Operators](#available-filter-operators)
            -   [OR Conditions with orKey](#or-conditions-with-orkey)
            -   [Relationship Filtering with whereHas](#relationship-filtering-with-wherehas)
            -   [Combining OR Conditions with Relationships](#combining-or-conditions-with-relationships)
    -   [Configuration](#configuration)

        -   [Environment Variables](#environment-variables)
        -   [Package Configuration](#package-configuration)
        -   [Automatic Route Registration](#automatic-route-registration)
            -   [Authentication Routes](#authentication-routes)
            -   [User Routes](#user-routes)
            -   [File Management Routes](#file-management-routes)
            -   [Permission Management Routes](#permission-management-routes)
            -   [Role Management Routes](#role-management-routes)
            -   [Social Authentication Routes](#social-authentication-routes)

    -   [License](#license)

## Installation

You can install the package via composer:

```bash
composer require visnsstudio/visns-packages
```

After installation, publish the migrations:

```bash
php artisan visns:publish-migrations
```

Or using Laravel's vendor:publish command:

```bash
php artisan vendor:publish --tag=visns-packages-migrations
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
    -   Password reset functionality
    -   Social authentication integration with Laravel Socialite
    -   Two-factor authentication (2FA)
    -   API token authentication

-   **File Management**

    -   Polymorphic file relationships
    -   File uploads and storage
    -   File metadata management

-   **User Management**

    -   User profiles
    -   Two-factor authentication management
    -   Notification handling

-   **Role & Permission Management**

    -   Role-based access control
    -   Permission management
    -   User role assignment

-   **Dynamic Controller**

    -   Automatic model detection from URL
    -   Advanced filtering with multiple operators
    -   OR conditions with `orKey` parameter
    -   Relationship filtering with `whereHas`

## Database Migrations

### User Table Migrations

This package includes migrations that add necessary fields to the users table for enhanced authentication and 2FA support. The migrations check if fields exist before adding them, making them safe to run on existing databases.

**Added fields:**

-   `username` - Alternative login identifier
-   `provider`, `provider_id`, `provider_token`, `provider_refresh_token` - For social authentication
-   `two_factor_secret`, `two_factor_recovery_codes`, `two_factor_confirmed_at` - For two-factor authentication

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

## Authentication System

### Basic Authentication

The package provides an `AuthController` with methods for handling user authentication:

-   `authenticate` - Handles login with username or email
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
-   `twoFactorAuthenticateApi` - Handles 2FA for API requests

Add these routes to your application:

```php
// API routes
Route::post('/api/login', [
    \Visnsstudio\VisnsPackages\Controllers\AuthController::class,
    'login_api',
]);
Route::post('/api/two-factor-challenge', [
    \Visnsstudio\VisnsPackages\Controllers\AuthController::class,
    'twoFactorAuthenticateApi',
]);
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

## User Management

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

## Dynamic Controller

The package includes a `DynamicController` that provides a flexible way to interact with any model in your application. It automatically determines the model based on the URL path and provides standard CRUD operations and filtering capabilities.

### Basic Usage

The `DynamicController` is accessed through URLs like:

```
/ajax/{model_name}/{action}
```

For example:

-   `/ajax/users/table` - Get a paginated list of users
-   `/ajax/products/dropdown` - Get products for a dropdown list
-   `/ajax/orders/show/123` - Get a specific order by ID

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

## Configuration

### Environment Variables

The package works with your existing Laravel configuration. Key environment variables used:

-   `APP_URL` - For generating URLs
-   `FRONT_END_URL` - For frontend redirects
-   `MAIL_FROM_ADDRESS` - For sending emails
-   `ALLOW_MULTIPLE_SESSIONS` - Controls multiple session behavior
-   `APP_NAME` - Used for the name in 2FA authenticator apps (can be overridden in config)

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

    // The name that appears in authenticator apps for 2FA
    '2fa_app_name' => 'Your App Name',
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

You can disable automatic route registration by setting `register_routes` to `false` in the configuration file, or customize the middleware and prefix applied to these routes.

## License

The MIT License (MIT). Please see [License File](LICENSE.md) for more information.
