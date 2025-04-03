# Visns Packages

A comprehensive Laravel package that provides enhanced authentication, file management, and two-factor authentication capabilities for Laravel applications.

## Table of Contents

- [Installation](#installation)
- [Features](#features)
- [Database Migrations](#database-migrations)
  - [User Table Migrations](#user-table-migrations)
  - [File Table Migrations](#file-table-migrations)
- [Authentication System](#authentication-system)
  - [Basic Authentication](#basic-authentication)
  - [Two-Factor Authentication (2FA)](#two-factor-authentication-2fa)
    - [Setup](#setup)
    - [Authentication Flow with 2FA](#authentication-flow-with-2fa)
    - [Managing 2FA](#managing-2fa)
  - [API Authentication](#api-authentication)
- [File Management](#file-management)
- [User Management](#user-management)
- [Configuration](#configuration)
- [License](#license)

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

Then run the migrations:

```bash
php artisan migrate
```

## Features

- **Enhanced Authentication System**

  - Username/email login support
  - Password reset functionality
  - Social authentication integration
  - Two-factor authentication (2FA) with Laravel Fortify
  - API token authentication

- **File Management**

  - Polymorphic file relationships
  - File uploads and storage
  - File metadata management

- **User Management**
  - User profiles
  - Two-factor authentication management
  - Notification handling

## Database Migrations

### User Table Migrations

This package includes migrations that add necessary fields to the users table for enhanced authentication and 2FA support. The migrations check if fields exist before adding them, making them safe to run on existing databases.

**Added fields:**

- `username` - Alternative login identifier
- `provider`, `provider_id`, `provider_token`, `provider_refresh_token` - For social authentication
- `two_factor_secret`, `two_factor_recovery_codes`, `two_factor_confirmed_at` - For two-factor authentication

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

- `fileable_id`, `fileable_type`, `fileable_field` - For polymorphic relationships
- `file_path`, `file_name`, `file_extension`, `file_size` - File metadata
- `file_title`, `file_description`, `sort_order` - Additional metadata

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

- `authenticate` - Handles login with username or email
- `logout` - Handles user logout
- `forgot` - Initiates password reset
- `reset` - Completes password reset

### Two-Factor Authentication (2FA)

The package integrates with Laravel Fortify to provide two-factor authentication:

#### Setup

1. Install Laravel Fortify:

```bash
composer require laravel/fortify
```

2. Publish the Fortify configuration:

```bash
php artisan vendor:publish --provider="Laravel\Fortify\FortifyServiceProvider"
```

3. Enable 2FA in the Fortify configuration (`config/fortify.php`):

```php
use Laravel\Fortify\Features;

'features' => [
    Features::registration(),
    Features::resetPasswords(),
    Features::emailVerification(),
    Features::updateProfileInformation(),
    Features::updatePasswords(),
    Features::twoFactorAuthentication([
        'confirmPassword' => true,
    ]),
],
```

4. Update your User model:

```php
use Laravel\Fortify\TwoFactorAuthenticatable;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

class User extends Authenticatable implements MustVerifyEmail
{
    use HasFactory, Notifiable, TwoFactorAuthenticatable;
    
    // ...
}
```

#### Authentication Flow with 2FA

The `AuthController` has been enhanced to support 2FA:

- `authenticate` - Checks if 2FA is required after validating credentials
- `twoFactorChallenge` - Shows the 2FA challenge page
- `twoFactorAuthenticate` - Validates the 2FA code and completes login

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

#### Managing 2FA

The `UserController` provides methods for managing 2FA:

- `enableTwoFactorAuth` - Enables 2FA and returns QR code and recovery codes
- `confirmTwoFactorAuth` - Confirms 2FA setup by validating the first code
- `disableTwoFactorAuth` - Disables 2FA
- `regenerateRecoveryCodes` - Generates new recovery codes
- `getTwoFactorStatus` - Gets current 2FA status

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

### API Authentication

The package supports API authentication with or without 2FA:

- `login_api` - Handles API login and returns tokens
- `twoFactorAuthenticateApi` - Handles 2FA for API requests

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

## File Management

The package includes a `File` model that supports polymorphic relationships, allowing you to attach files to any model in your application.

The `File` model provides methods for:

- Storing file metadata
- Generating file URLs
- Managing file relationships

## User Management

The `UserController` provides methods for managing user profiles and notifications:

- `profile` - Gets the user's profile
- `notifications` - Gets the user's unread notifications
- `notificationTable` - Gets paginated notifications
- `markAsRead` - Marks notifications as read

## Configuration

The package works with your existing Laravel configuration. Key environment variables used:

- `APP_URL` - For generating URLs
- `FRONT_END_URL` - For frontend redirects
- `MAIL_FROM_ADDRESS` - For sending emails
- `ALLOW_MULTIPLE_SESSIONS` - Controls multiple session behavior

## License

The MIT License (MIT). Please see [License File](LICENSE.md) for more information.
