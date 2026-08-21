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
    -   [Auth Platform Modules](#auth-platform-modules)
        -   [Login extension points](#login-extension-points)
        -   [Password reset: resolver, link and hooks](#password-reset-resolver-link-and-hooks)
        -   [Sessions and CSRF across a login](#sessions-and-csrf-across-a-login)
        -   [Remember me](#remember-me)
        -   [Two-factor: the code channel](#two-factor-the-code-channel)
        -   [Passwordless OTP login](#passwordless-otp-login)
        -   [Staff impersonation](#staff-impersonation)
        -   [Zoom Phone call queue pop](#zoom-phone-call-queue-pop)
        -   [Vault (staff password manager)](#vault-staff-password-manager)
        -   [Middleware aliases](#middleware-aliases)
        -   [Testing](#testing)
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
        -   [Generating Proposal PDFs with Headers](#generating-proposal-pdfs-with-headers)
    -   [Proposal System \& Branding Profiles](#proposal-system--branding-profiles)
        -   [Branding Profiles](#branding-profiles)
        -   [Proposal Assembly Service](#proposal-assembly-service)
        -   [PDF Controller Enhancements](#pdf-controller-enhancements)
        -   [Styling Optimizations](#styling-optimizations)
        -   [Frontend Integration](#frontend-integration)
        -   [Database Migrations](#database-migrations)
        -   [Configuration Options](#configuration-options)
        -   [API Endpoints](#api-endpoints)
        -   [Development Commands](#development-commands)
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

-   **Auth Platform Modules** (opt-in, 4.3.0)

    -   Pluggable user resolver, pre-login gates and post-login hooks
    -   Second two-factor driver: single-use numeric codes over your own channel
    -   Passwordless OTP login
    -   Staff impersonation of a client account, with an audit log
    -   Zoom Phone call queue pop (webhook, live state, broadcast, settings)

-   **Vault** (opt-in, 4.4.0)

    -   Staff password manager: encrypted passwords, TOTP seeds and notes
    -   Shared and private entries, gated by two permissions
    -   Password re-confirmation before any secret is revealed
    -   Access log, plus key-rotation and log-prune commands

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

-   **Proposal System & Branding Profiles**

    -   Professional proposal PDF generation with headers
    -   Company branding profiles with logo, colors, and fonts
    -   Configurable header content (phone, email, website, address, ABN)
    -   S3 logo support with automatic local caching for PDF compatibility
    -   Template-based proposal assembly with dynamic variables
    -   Multi-page support with headers on every page except cover
    -   React components for branding profile management

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

## Auth Platform Modules

Four modules added in 4.3.0. **Everything here is opt-in.** The authentication
changes default to exactly what the package did before, and the three new
modules ship disabled — upgrading without touching your config changes nothing.

All of it is driven from `config/visns-packages.php`; publish it with

```bash
php artisan vendor:publish --tag=visns-packages-config
```

A note that applies to every module: nothing in this package reads `env()` at
runtime any more. `env()` returns `null` once `php artisan config:cache` has
run, which is the state production runs in — so a value read that way works in
development and silently evaporates on deploy. Everything comes from config,
which reads env once, at load. If you have been setting `FRONT_END_URL`,
`MAIL_TO_DEV`, `ALLOW_MULTIPLE_SESSIONS`, `DEFAULT_USER_ROLE` or `FRONTEND_URL`
in `.env`, they still work; they are now read through `visns-packages.auth.*`.

### Login extension points

The login flow can be bent to an application's rules without forking the
controller.

| Config key | What it takes | Default |
| --- | --- | --- |
| `auth.user_resolver` | invokable `($identifier, $request): ?User` | email-or-username lookup |
| `auth.pre_login_gates` | array of invokables `($user, $request): ?Response` | none |
| `auth.post_login_hooks` | array of invokables `($user, $request): void` | none |
| `auth.run_gates_before_credential_check` | bool | `false` |
| `auth.filter_previous` | bool | `true` |
| `auth.messages.*` | string map | the package's current strings |
| `auth.logout_response` | array | `['message' => 'Successfully logged out']` |
| `auth.user_model` | class name | `visns-packages.user_model` |

A **gate** runs once the account has been found and returns either `null` ("carry
on") or a Response, which goes to the client untouched — status code, body and
all. Gates run *after* the password check by default, so a gate cannot be used
to probe which accounts exist; set `run_gates_before_credential_check` if your
rule has to apply before the password is looked at.

```php
// config/visns-packages.php
'auth' => [
    'user_resolver' => \App\Auth\PortalUserResolver::class,
    'pre_login_gates' => [\App\Auth\RejectInactiveClients::class],
    'post_login_hooks' => [\App\Auth\StampLastLogin::class],
],
```

```php
class RejectInactiveClients
{
    public function __invoke($user, Request $request)
    {
        return $user->contact?->isInactive
            ? response()->json(['error' => 'Your account is currently inactive.'], 403)
            : null;
    }
}
```

**Password reset mail.** `forgot()` has always built the application's
`GenericMail` as `($content, $fromAddress, $subject)` — the from-address in the
title slot. That call is preserved as the default, because applications have
built their mailable around it. To use a sane signature, configure either:

```php
'auth' => [
    // new $mailable($content, $subject, [])
    'reset_mailable' => \App\Mail\GenericMail::class,
    'reset_subject'  => 'Acme - Password Reset Request',

    // or, for any signature at all:
    'reset_mail_factory' => fn ($content, $subject) => new \App\Mail\Reset($subject, $content),
],
```

### Password reset: resolver, link and hooks

`forgot()` and `reset()` originally assumed one account, one address, one link
shape. Four keys lift each of those assumptions; all four default to exactly what
the endpoints did before.

| Config key | What it takes | Default |
| --- | --- | --- |
| `auth.reset_user_resolver` | invokable `($email, $request): ?User` | match on the `email` column |
| `auth.reset_key_by_resolved_email` | bool | `false` (store the typed address) |
| `auth.reset_url_builder` | invokable `($user, $token, $request): string` | `{app_url\|front_end_url}/verify/{token}` |
| `auth.after_reset_hooks` | array of invokables `($user, $plainPassword)` | none |

The **resolver** is used by *both* halves: `forgot()` asks it which account a
typed address belongs to, and `reset()` asks it which account the address stored
on the token row belongs to. Using one resolver for both is what stops the two
halves of a single reset disagreeing about whose password is being changed.

> **If you set a resolver, set `reset_key_by_resolved_email` too.** The row is
> keyed on the typed address by default. That is only correct while the typed
> address *is* the account's address — the moment a resolver looks past it, the
> row records something `reset()` cannot match an account back to, and every
> token it issues is dead on arrival. The default stays `false` purely so
> existing installs are untouched.

The **URL builder** owns the whole link, so a token can be a query parameter, a
different host, anything:

```php
'auth' => [
    'reset_user_resolver' => \App\Auth\ContactEmailResolver::class,
    'reset_key_by_resolved_email' => true,
    'reset_url_builder' => \App\Auth\PortalResetUrl::class,
    'after_reset_hooks' => [\App\Auth\MirrorPasswordToContact::class],
],
```

```php
class PortalResetUrl
{
    public function __invoke($user, string $token, Request $request): string
    {
        $base = rtrim(config('portal.url'), '/') . '/verify/';

        return $request->input('portal') === 'true'
            ? $base . '?code=' . $token   // portal reads it from the query string
            : $base . $token;             // CRM reads it from the path
    }
}
```

**After-reset hooks** run once the new password is saved, for mirroring it onto a
second record. They are handed the **plaintext** password, because a mirror
usually has to hash it its own way — which makes a hook one of the few places a
plaintext credential exists. Never log, return or persist it unhashed.

Two related fixes while you are here: a token row whose stored address no longer
resolves to an account now answers the same "token is no longer valid" message
instead of dereferencing null and 500-ing; and the spent row is deleted by the
**row's** address rather than the resolved account's, so a token cannot survive
its own use when the two differ.

### Sessions and CSRF across a login

**Every stateful auth response carries the live, post-login CSRF token. Resync
your meta tag from it — this is the contract, not a convenience.**

| Response | Carries |
| --- | --- |
| `POST /login/authenticate` — success, failure, and `requires_two_factor` | `csrf_token` |
| `POST /login/two-factor-challenge` — code driver | `csrf_token` |

The API endpoints do not: they are stateless and have no session.

**Why you have to.** Laravel rotates the session on login, and what it rotates
changed between majors:

| | `SessionGuard::updateSession()` | Rotates |
| --- | --- | --- |
| Laravel ≤ 11 | `session->migrate(true)` | session id |
| Laravel ≥ 12 | `session->regenerate(true)` | session id **and CSRF token** |

This package supports `>=11` and fights neither. The Laravel 12 change is
deliberate framework security — a privilege change should not leave the old CSRF
token valid — so suppressing it would be trading a real protection for
convenience.

The consequence is real, and it is how this was first reported: a single-page app
that shows the 2FA prompt **without reloading** still holds the
`<meta csrf-token>` it rendered before the challenge. On Laravel 12 that token is
dead the moment the challenge completes, so the page's next burst of POSTs all
return **419 CSRF token mismatch** — while its GETs carry on working, which is
what makes it look intermittent rather than broken.

So the frontend must do one of:

```js
// after POST /login/authenticate and POST /login/two-factor-challenge
if (data.csrf_token) {
    document
        .querySelector('meta[name="csrf-token"]')
        ?.setAttribute('content', data.csrf_token);
    // and whatever your HTTP client caches, e.g.
    axios.defaults.headers.common['X-CSRF-TOKEN'] = data.csrf_token;
}
```

…or hard-reload after login. Resyncing is cheaper and is what the field is for.
Nothing is disclosed by returning it: the token is already rendered into the page
being answered.

**Session fixation is unaffected.** The session id rotates at every privilege
change — the plain login, and again when a 2FA challenge completes — so an id an
attacker planted beforehand never survives. This package adds no rotation of its
own on top of the framework's, and `logout()` still calls `regenerateToken()`
explicitly, because there a fresh token is the entire point.

### Remember me

The login screens have always sent `remember`, and these endpoints have always
accepted it — and then dropped it. Nothing reached the session guard, so no
recaller cookie was ever issued and the tick box did nothing at all.

```php
'auth' => ['remember_enabled' => true],
```

Off by default. Switching it on lengthens how long a session survives on every
machine a user ticks the box on — a security posture decision, not a bug fix,
and not something that should happen to an application merely because it
upgraded. Needs the standard Laravel `remember_token` column on the users table.

**One source of truth: the original login.** The choice is read from the request
that proved the password, stashed in the session under
`auth.two_factor.remember`, and applied when the login actually completes:

| Path | Where `remember` is read |
| --- | --- |
| plain login (no 2FA) | the `authenticate()` request |
| code-driver challenge | the session stash from `authenticate()` |
| TOTP challenge | the session stash from `authenticate()` |
| API login / API challenge | not applied — token clients have no recaller |

A challenge request's own `remember` field is **ignored** for this. By the time
the challenge is answered the caller is only half authenticated, so its body must
not be able to extend the session's lifetime; the stash is what makes a tampered
challenge POST unable to widen it. The stash is consumed on use and dropped if
the challenge is abandoned.

> **`remember` means two different things on the challenge endpoint.** This
> feature (recaller cookie, `remember_enabled`) reads the *session*.
> Remember-this-device (skipping the 2FA challenge next time,
> `two_factor.remember_device`, stored in the package's own table) reads the
> *challenge request*, and always has — that one is legitimately about the
> browser answering the challenge, so it can only be decided there. Turning on
> `remember_enabled` does not change it.

Interplay handled, with tests pinning each:

- **`logoutOtherDevices()`** rehashes the password, and the recaller embeds a
  slice of that hash. The login happens first so the recaller is queued, then the
  rehash re-queues it off the new hash — otherwise "remember me" would silently
  stop working on the next visit.
- **`session()->regenerate()`** in the 2FA completion rotates the session id; the
  recaller lives on the cookie jar and is unaffected.
- **`logout()`** clears it — Laravel queues a forget-cookie *and* cycles the
  stored token, so a copy taken off the wire is worthless too.
- **A model that cannot store a remember token** (missing migration, or an
  `Authenticatable` opting out) logs a warning and logs in without a recaller. A
  missing column costs the tick box, not the login.

### Two-factor: the code channel

Alongside the existing authenticator-app flow there is now a driver that sends a
one-time numeric code through a channel your application owns.

```php
'auth' => ['two_factor' => [
    'driver'  => 'code',            // 'totp' (default) | 'code'
    'trigger' => 'ip_change',       // 'always' (default) | 'ip_change' | 'never'
    'expiry_minutes' => 15,
    'message_template' => 'Your Acme verification code is: {code}',
    'sender' => \App\Auth\SmsCodeSender::class,
]],
```

`trigger` is **only ever evaluated in production** — outside it 2FA is skipped,
which is what both the TOTP flow and the SMS flow it replaces have always done.
`ip_change` compares the request IP to `two_factor.ip_column`
(`last_logged_ip_address` by default), which is why the post-login IP hook
matters: without something writing that column, every login looks like a new
address.

Bind a sender — the package generates, stores, verifies and expires the code;
how it reaches the human is yours:

```php
use Visnsstudio\VisnsPackages\Contracts\TwoFactorCodeSender;

class SmsCodeSender implements TwoFactorCodeSender
{
    public function send(object $user, string $code, string $message): void
    {
        // $message is the rendered template plus the "\n\n@host #code"
        // autofill trailer that lets iOS/Android offer the code.
        $this->gateway->send($user->mobile, $message);
    }
}
```

Flow:

| Step | Endpoint | Body |
| --- | --- | --- |
| 1 | `POST /login/authenticate` | answers `{error:'', user:null, requires_two_factor:true}` and sends the code; **does not log in** |
| 2 | `POST /login/two-factor-challenge` | `{code, previous_url, remember}` → completes the login |
| 3 | `POST /login/two-factor-resend` | re-issues and re-sends, invalidating the previous code |

The code is **consumed on use** — nulled the moment it works — so an intercepted
SMS cannot be replayed. A code that could not be delivered refuses the login
rather than letting it through. Remember-this-device is off for this driver
unless `two_factor.remember_device` is set: an SMS code is tied to the phone,
not the browser.

The TOTP path, including `TwoFactorRememberToken`, is untouched.

### Passwordless OTP login

A contact detail is exchanged for a one-time code, and the code for a Sanctum
token. Two unauthenticated endpoints, so the module is off until you ask:

```php
'otp' => [
    'enabled' => true,
    'contact_resolver' => \App\Auth\CompanyContactResolver::class,
    'sender' => \App\Auth\PortalOtpSender::class,
    'token_name' => 'portal-token',
    'user_foreign_key' => 'company_contact_id',
],
```

Endpoints (URIs configurable): `POST /api/auth/request-otp` and
`POST /api/auth/login-otp`.

Rules, all configurable: 6 digits from the CSPRNG, stored bcrypt-hashed, 5-minute
expiry, 3 attempts per code, 2-minute resend cooldown. Outside production the
code comes back in the response as `dev_otp` so staging needs no SMS gateway —
turn that off with `otp.expose_code_outside_production`.

Two things you supply. A **contact resolver**, because which record a contact
string maps to is application knowledge (`Visnsstudio\VisnsPackages\Contracts\OtpContactResolver`
— `__invoke`, `matchedMethod`, `maskedContact`); the bundled default searches the
user table on email/username/mobile. And an **OtpSender** (`send($contact,
$method, $code)`), which decides from the matched method whether that means an
email or an SMS.

`minimal_response: true` on the login call returns the whitelisted payload from
`auth.minimal_user` instead of the model — for callers keeping the user in a
cookie, which has a 4KB limit the full model does not fit inside.

> **Set `otp.consume_on_success => true` unless you have a reason not to.**
> With the default `false` — which is what the controller this was ported from
> does — a code keeps working until it expires, *including after it has already
> logged someone in*. Anyone who saw it in the meantime, over a shoulder or in a
> lock-screen SMS preview, can log in behind the user for the rest of the
> window. `true` clears the code the moment it is spent: one code, one login.
> It ships `false` only so that adopting this module cannot silently change how
> an existing deployment behaves. The attempt counter and any lock are reset on
> success either way.

> **Wart, faithfully preserved.** `login-otp` validates inside its own
> catch-all, so a wrong-length code answers **500 with the generic failure
> message**, not Laravel's 422 field error. That is what the front end this was
> ported from reads in production today. If you are adopting fresh and do not
> need that, override `otp.messages.login_failed` — or fix it upstream and
> retest both sides together.

### Staff impersonation

Issue a short-lived token for a client's own account and redirect into the
portal holding it.

```php
'impersonation' => [
    'enabled' => true,
    'permission' => 'Impersonate Client',
    'target_column' => 'company_contact_id',
    'expires_minutes' => 60,
    'log_model' => \App\Models\ImpersonationLog::class, // or false to log nothing
],
```

- `POST /ajax/impersonateClient` `{id}` → `{url}` (session + permission gated)
- `POST /api/validateImpersonationToken` `{token}` → the whitelisted user payload

The redirect URL is built from `config('portal.url')` (override with
`impersonation.portal_url`), guarding against a doubled `/portal` segment.

The security shape is the point:

- only tokens named `impersonation-token%` are revoked when a new one is issued —
  the client's own login tokens survive, or staff opening an account would sign
  the client out of their portal;
- the validate endpoint verifies the plaintext against the stored hash
  (`PersonalAccessToken::findToken`) rather than trusting an id, and rejects any
  token that is not an impersonation token, so a stolen portal token cannot be
  laundered into a session through it;
- it answers with a **whitelisted** payload only. It is unauthenticated and its
  token travels in a URL; serializing the model would hand whoever holds that URL
  the client's live OTP hash and portal data.

The acting staff id is encoded in the token name, because `Auth::user()` during
an impersonated request is the *client*:

```php
use Visnsstudio\VisnsPackages\Support\ImpersonationActor;

ImpersonationActor::id();              // the real human, or null
ImpersonationActor::isImpersonating(); // bool
```

Publish and run the audit-log migration
(`vendor:publish --tag=visns-packages-migrations`), or point `log_model` at your
own class. The migration no-ops if the table already exists.

### Zoom Phone call queue pop

Receives Zoom Phone events, keeps a table of what is ringing right now, and
broadcasts it to every monitoring browser.

```php
'call_queue' => [
    'enabled' => true,
    'webhook_secret_token' => env('ZOOM_WEBHOOK_SECRET_TOKEN'),
    'append_env_suffix' => true,     // dev and prod sharing one Pusher app
    'caller_enrichment' => \App\Helpers\CallerClientPreview::class,
    'api' => [/* ZOOM_ACCOUNT_ID, ZOOM_CLIENT_ID, ZOOM_CLIENT_SECRET */],
],
```

| Method | URI | Guard |
| --- | --- | --- |
| POST | `/api/zoom/webhook` | signature only |
| GET | `/ajax/call-queue/live` | `permission:Call Queue Monitor` |
| GET | `/ajax/call-queue/settings` | `permission:Call Queue Settings` |
| PUT | `/ajax/call-queue/settings/{queueId}` | `permission:Call Queue Settings` |

Broadcasts `CallQueueRinging` / `Answered` / `Ended` as `ShouldBroadcastNow` on
the private channel `call_queue.channel` (default `call-queue-monitor`). Set
`append_env_suffix` when environments share one Pusher app, or dev broadcasts
land in production browsers. The package authorizes the channel itself against
the monitor permission; set `register_broadcast_channel => false` to do it
yourself in `routes/channels.php`. The front end should read the channel name
from `/ajax/call-queue/live` rather than hardcoding it.

**Dispatching your own event classes.** An application that already has
`App\Events\CallQueue*` — with listeners and tests written against them — cannot
be reached by dispatching the package's classes. Laravel's `Event::fake()` and
its listener registry key on the **exact** class name, so a subclass, a
`class_alias()` or a container binding is a different key and simply never
matches. Name your classes instead:

```php
'call_queue' => ['events' => [
    'ringing'  => \App\Events\CallQueueRinging::class,
    'answered' => \App\Events\CallQueueAnswered::class,
    'ended'    => \App\Events\CallQueueEnded::class,
]],
```

**Required constructor contract** — a replacement is constructed with exactly the
arguments the package class it replaces takes:

| Key | Constructor |
| --- | --- |
| `ringing` | `__construct(ZoomLiveQueueCall $call)` |
| `answered` | `__construct(string $callId)` |
| `ended` | `__construct(string $callId)` |

> The model handed to `ringing` is
> **`Visnsstudio\VisnsPackages\Models\ZoomLiveQueueCall`**, not your application's
> own model of the same name. If your event type-hints `App\Models\ZoomLiveQueueCall`
> the call is a `TypeError` — widen the hint to the package model, or drop it.
> This is the one edit adopting the module requires in an existing event class.

Each key is independent; anything left unset keeps the package's own event. A
class name that does not load falls back to the package event and logs a warning
— a typo in config should cost the pop its custom listener, not stop the webhook
recording calls at all.

Behaviour worth knowing before you point Zoom at it:

- The webhook answers **200 to everything** bar a bad signature. Zoom retries and
  eventually *disables* endpoints that error or answer slowly.
- An unset `webhook_secret_token` rejects every delivery. The endpoint is inert
  rather than open while the Zoom app does not exist yet.
- Zoom's queue events arrive in two shapes — sometimes the callee *is* the queue,
  sometimes the queue only appears under `forwarded_by`. Both match.
- Caller enrichment runs once, on the webhook thread, not in each watching
  browser. Implement `Visnsstudio\VisnsPackages\Contracts\CallerEnrichment`; a
  hook that throws costs the pop its client block, never the pop.
- Per-queue pickup codes and pop exclusions live in `zoom_call_queue_settings`,
  read through a 60-second cache that is busted on save. Codes are stored bare;
  Zoom fixes a `*99` prefix, so `8781` is dialled `*998781`.

> **Zoom API limit, proven and documented.** Saving a pickup code pushes the
> policy to Zoom first and only stores the code if Zoom took it — but Zoom
> applies the *enable* half and silently drops the digits. The digits still have
> to be typed into the Zoom admin UI; this package's table is the source of truth
> for what the pop dials. The payload carries the code anyway, so the day Zoom
> honours it this starts working with no code change. The full write-up is in
> `src/Services/Zoom/ZoomCallQueueService.php` — read it before re-investigating.

**Substituting your own Zoom client.** The settings page's client is named in
config and resolved through the container:

```php
'call_queue' => [
    'zoom_service' => \App\Helpers\ZoomCallQueueService::class,
],
```

A class **string**, never a closure, so the file survives `config:cache`. Because
the container is asked for whatever class is named, an `instance()` or `bind()`
double for *that* class is honoured — which is how a test suite guarantees no
save reaches the live Zoom tenant:

```php
$this->app->instance(\App\Helpers\ZoomCallQueueService::class, $fake);
```

Required public contract. Your class need not extend anything here — it only has
to answer these:

| Method | Called by | Must return |
| --- | --- | --- |
| `listQueues(): array` | settings page load | `['success' => bool, 'queues' => array<int, array>, 'error' => string?]` — each queue keyed `id`, `name`, `extension_number`, `status`, `phone_numbers[0].number` |
| `setPickupCode(string $queueId, string $code): array` | saving a code | `['success' => bool, 'http_code' => int?, 'error' => string?]`; `$code` is bare digits, no `*` |
| `disablePickupCode(string $queueId): array` | clearing a code | same shape as above |
| `getQueue(string $queueId): array` | — | not called by this package; present on its own client |
| `getPolicies(string $queueId): array` | — | not called by this package; present on its own client |

Only the first three are on the request path. `success => false` on either write
returns 422 with your `error` as the message and stores nothing, so the pop can
never advertise a code your client refused. A `zoom_service` naming a class that
does not load falls back to the package's own client rather than 500-ing the
settings page.

The `call_queue.api.*` credentials are read by the **package's** client only; a
replacement is built by the container and reads its own configuration.

Two tables are needed; publish and run the migrations
(`vendor:publish --tag=visns-packages-migrations`). Both no-op if the table
already exists, so an application that already has them can adopt the module
without a clash.

### Vault (staff password manager)

Added in 4.4.0. A shared credential store for staff: title, username, URL, an
encrypted password, an encrypted TOTP seed and encrypted notes, with an access
log recording who read what.

> **Threat model, stated plainly.** Anyone with `APP_KEY` plus a database dump
> can read the vault. The three secret columns are encrypted with the application
> key, which means the key *is* the protection — keep it out of the repository,
> keep it out of anything that ships to a browser, and rotate it via
> `APP_PREVIOUS_KEYS` + `php artisan vault:reencrypt`. This module raises the bar
> against a stolen database; it does not defend against a compromised
> application server.

```php
'vault' => [
    'enabled' => true,
    'uris' => ['base' => 'ajax/vault'],
    'routes_middleware' => ['web', 'auth'],
    'permissions' => ['access' => 'Vault Access', 'manage' => 'Vault Manage'],
    'require_password_confirmation' => true,   // false: a live session is enough to reveal
    'confirmation_ttl_minutes' => 10,
    'throttle' => ['reveal' => '60,1', 'confirm' => '5,1'],
    'tables' => ['entries' => 'vault_entries', 'access_logs' => 'vault_access_logs'],
    'user_model' => null,           // null = the package-wide user_model
    'search_columns' => ['title', 'username', 'url'],
],
```

Then publish and run the migrations — the module needs `vault_entries` and
`vault_access_logs`:

```bash
php artisan vendor:publish --tag=visns-packages-migrations
php artisan migrate
```

**Permissions are not seeded here.** The application owns its permission table,
so create the two names in your own seeder:

| Permission | Grants |
| --- | --- |
| `Vault Access` | Use the vault at all: list, open, create private entries, reveal and copy. |
| `Vault Manage` | The administrative grant: create and edit **shared** entries, edit or delete anybody's entry, restore, and read the access log. |

Setting either name to `null` in config removes that gate — which for `manage`
means every user with access is treated as an administrator, so do it only if
something else is enforcing it.

#### Endpoints

All relative to `vault.uris.base` (default `ajax/vault`); all carry
`permission:Vault Access` on top of `routes_middleware`.

| Method | URI | Extra guard | What it does |
| --- | --- | --- | --- |
| GET | `{base}` | — | Paginated list. `search`, `page`, `per_page` (≤100), `sort` (`title`\|`username`\|`updated_at`), `direction`, `include_deleted` (manage only). |
| POST | `{base}` | manage, for a **shared** entry | Create. 201 with the detail payload. |
| GET | `{base}/{id}` | — | Detail, including decrypted `notes`. Logs `view`. |
| PUT | `{base}/{id}` | owner or manage; manage for anything **shared** | Update. |
| DELETE | `{base}/{id}` | owner or manage | Soft delete. 204. |
| POST | `{base}/{id}/restore` | manage | Undelete. 200 detail payload. |
| POST | `{base}/confirm-password` | `throttle:5,1` | Re-check the caller's own password. 204, or 422 + a `confirm_failed` log row. |
| POST | `{base}/{id}/reveal` | `vault.confirmed`, `throttle:60,1` | `{ "password": ... }`, `Cache-Control: no-store`. Logs `reveal_password`. |
| GET | `{base}/{id}/otp` | `throttle:60,1` | `{ code, expires_in, period }`, `no-store`. 404 with no seed. Logs `otp`. |
| POST | `{base}/{id}/log` | — | Body `{ "action": "copy_username" }`. 204. |
| GET | `{base}/{id}/log` | manage | That entry's access log, newest first. |
| GET | `{base}/log` | manage | The whole log; filters `user_id`, `action`. |

#### Things a front end has to know

- **No list or detail payload ever contains a password or a TOTP seed.** They are
  absent, not null. `has_totp` tells you whether the OTP endpoint will answer.
- **Reveal answers 423, not 401 or 403**, when the password confirmation has
  lapsed: `{ message, reason: "password_confirmation_required", ttl_minutes }`.
  Match on `reason`, not the message. A 401 would send most SPA interceptors into
  a logout that is not wanted here.
- **An entry you cannot see is a 404**, never a 403 — including on PUT and
  DELETE. A 403 would answer "does an entry with this id exist" for anyone who
  cared to ask.
- **`password` and `totp_secret` are three-state on PUT**: key **absent** leaves
  the stored secret alone, **null or empty** clears it, a **value** replaces it.
  A form that (correctly) never received the current password must omit the key,
  not send an empty string. Every other field is an ordinary partial update, bar
  `title`, which is required.
- `password_rotated_at` moves only when the password itself changes — not on a
  rename, and not on `vault:reencrypt`.
- `expires_in` counts down to the TOTP period boundary, not a full period. Re-ask
  for a code when it hits zero.

#### TOTP seeds

Paste the whole `otpauth://totp/...` URI, not just the secret: it is the only
form that carries `digits`, `period` and `algorithm`, and an 8-digit or
60-second entry stored without them generates confidently wrong codes forever. A
bare base32 secret is accepted too and takes the defaults (6 digits, 30 seconds,
SHA-1). Secrets are proved by generating a code before anything is stored, so a
bad seed is a 422 at save time rather than a failed login weeks later.

#### Rotating the application key

```bash
# 1. New key in APP_KEY, old key still in APP_PREVIOUS_KEYS.
# 2. While the old key is still listed:
php artisan vault:reencrypt
# 3. Only now remove the old key from APP_PREVIOUS_KEYS.
```

Order matters and the command cannot check it: remove the old key first and the
rows are unreadable to everybody. The rewrite goes through the query builder with
values encrypted by hand — Eloquent's dirty check considers an encrypted
attribute unchanged when it decrypts to the same plaintext, which is exactly the
case a rotation has to write — and it deliberately leaves `updated_at` alone.

```bash
php artisan vault:prune-log --days=365   # trim the access log; schedule it
```

#### Auditing

This package does not depend on `owen-it/laravel-auditing`, so `VaultEntry`
implements no auditing contract. An application that audits everything else it
owns should subclass it, add the `Auditable` interface and trait, and set
`$auditExclude = ['password', 'totp_secret', 'notes']` — the audit table is not
encrypted, and an unexcluded change event writes the old and new secret into it
in the clear.

### Middleware aliases

The package fills in the aliases its own routes need — `zoom-webhook` and
`zoom_webhook` (both spellings, so route definitions move across untouched) and,
when Spatie is installed, `permission`. Each is only registered **if the
application has not already claimed that name**: service providers boot after an
application's own middleware registration, so a package that registered
unconditionally would always win and silently change what every route carrying
that name does.

`accept-json` is the exception and is still registered unconditionally — it
predates this rule, and changing it would alter which class an existing
consumer's routes resolve to.

### Testing

```bash
composer test          # the package's own suite, on Testbench + SQLite
```

The older tests under `tests/Unit` and `tests/Feature` extend `Tests\TestCase`
and build `App\Models\User` factories — they only run from inside a consuming
application, and are kept in an opt-in `Legacy` suite.

The dev dependencies pin **Testbench 10 / Laravel 12**, deliberately: the package
supports `>=11`, but session, guard and cookie behaviour differ between the two
majors (see *Sessions and CSRF across a login*), and a suite that resolves the
older major will happily green-light code that is broken for a consumer on the
newer one. Test against the major your consumers run.

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

The package includes a powerful PDF generation system supporting both DomPDF and Spatie Laravel PDF (Chrome-based) for generating PDFs from Laravel views or HTML content with customizable options.

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

### Manual Puppeteer Setup for New Projects

If the automated `php artisan visns:install-chromium` command doesn't work (especially on Laravel Forge servers), you can manually set up Puppeteer:

#### Option 1: Local Project Installation (Recommended for Forge)

```bash
# Navigate to your project directory
cd /path/to/your/laravel/project

# Install Puppeteer locally using Yarn (preferred)
yarn add puppeteer

# Or using npm
npm install puppeteer

# Create cache directory for Puppeteer (if needed)
mkdir -p .cache/puppeteer
```

#### Option 2: System-wide Chrome Installation

For production servers, you can install Chrome system-wide:

```bash
# Ubuntu/Debian systems
wget -q -O - https://dl-ssl.google.com/linux/linux_signing_key.pub | sudo apt-key add -
sudo sh -c 'echo "deb [arch=amd64] http://dl.google.com/linux/chrome/deb/ stable main" >> /etc/apt/sources.list.d/google.list'
sudo apt-get update
sudo apt-get install google-chrome-stable

# Install required dependencies
sudo apt-get install -y \
    libnss3 \
    libnspr4 \
    libatk1.0-0 \
    libatk-bridge2.0-0 \
    libcups2 \
    libdrm2 \
    libdbus-1-3 \
    libatspi2.0-0 \
    libx11-6 \
    libxcomposite1 \
    libxdamage1 \
    libxext6 \
    libxfixes3 \
    libxrandr2 \
    libgbm1 \
    libxcb1 \
    libxkbcommon0 \
    libpango-1.0-0 \
    libcairo2 \
    libasound2
```

#### Option 3: Configure Existing Chromium

If you have Chromium installed (like on your staging server):

```bash
# Add this to your .env file
PUPPETEER_EXECUTABLE_PATH=/usr/bin/chromium-browser

# Or for Chrome
PUPPETEER_EXECUTABLE_PATH=/usr/bin/google-chrome-stable
```

#### Deployment Script Integration

For Laravel Forge, add this to your deployment script:

```bash
# If using local Puppeteer installation
yarn install
# or
npm ci

# Ensure Puppeteer can download Chromium (if needed)
yarn add puppeteer
```

#### Troubleshooting

**If you get permission errors:**
- Use local installation instead of global (`yarn add puppeteer` vs `yarn global add puppeteer`)
- Ensure the web server user has access to the Chrome/Chromium binary
- Check file permissions on the executable

**If Chrome/Chromium isn't found:**
- Verify the executable path: `which google-chrome-stable` or `which chromium-browser`
- Set the correct path in your `.env` file using `PUPPETEER_EXECUTABLE_PATH`
- Test the binary: `/usr/bin/google-chrome-stable --version`

**For Laravel Forge specifically:**
- Use local project installation (`yarn add puppeteer`)
- Avoid global installations which often have permission issues
- The automated command handles this setup but may fail due to PATH issues in PHP

### Spatie Laravel PDF (Chrome-based) - Recommended

The package now includes **Spatie Laravel PDF** support for superior PDF generation using Chrome/Chromium. This provides better CSS support, native header/footer functionality, and more reliable rendering.

#### Installation

Spatie Laravel PDF is automatically installed with the package. For Chromium, run the installation command:

```bash
php artisan visns:install-chromium
```

#### Generating Proposal PDFs with Spatie

```javascript
fetch('/ajax/pdf/generate-proposal-spatie', {
    method: 'POST',
    headers: {
        'Content-Type': 'application/json',
    },
    body: JSON.stringify({
        proposal_data: {
            // Proposal data object
        },
        template_id: 6,
        branding_id: 1,
        header_config: {
            enabled: true,
            show_phone: true,
            show_email: true,
            show_website: true,
            show_address: false,
            show_abn: false
        },
        filename: 'proposal.pdf',
        download: true
    }),
});
```

**Spatie PDF Features:**
- Superior CSS rendering with Chrome engine
- Native browser header and footer support
- Automatic page numbering in footer ("Page X of Y")
- Company logo display in headers with full URL support
- Better image handling (including S3 images)
- Improved font rendering and layout
- Headers appear on every page except the first (cover) page
- Company information is automatically pulled from branding profiles
- Configurable visibility of contact details (phone, email, website, address, ABN)

### Legacy DomPDF Support

The original DomPDF implementation is still available for backward compatibility:

```javascript
fetch('/ajax/pdf/generate-proposal', {
    method: 'POST',
    headers: {
        'Content-Type': 'application/json',
    },
    body: JSON.stringify({
        proposal_data: {
            // Proposal data object
        },
        template_id: 6,
        branding_id: 1,
        header_config: {
            enabled: true,
            show_phone: true,
            show_email: true,
            show_website: true,
            show_address: false,
            show_abn: false
        },
        filename: 'proposal.pdf',
        download: true
    }),
});
```

The same endpoints are also available via the API with authentication required:

-   `POST /api/pdf/generate`
-   `POST /api/pdf/generate-from-html`
-   `POST /api/pdf/generate-custom`
-   `POST /api/pdf/generate-quote`
-   `POST /api/pdf/generate-proposal` (DomPDF)
-   `POST /api/pdf/generate-proposal-spatie` (Spatie PDF - Recommended)

## Proposal System & Branding Profiles

The package includes a comprehensive proposal generation system with branding profile support. This system allows you to create professional proposal PDFs with customizable templates, company branding, and header configurations.

### Branding Profiles

Branding profiles store company information and visual branding that can be applied to proposals and other documents.

#### BrandingProfile Model Features

The `BrandingProfile` model provides:

- **Company Information**: Name, address, phone, email, website, ABN
- **Visual Branding**: Logo upload, color schemes (primary, secondary, accent), fonts
- **PDF Integration**: Automatic header generation with company details
- **Template Association**: Link branding profiles to proposal templates

#### Company Information Management

```php
// Create or update branding profile with company info
$brandingProfile = BrandingProfile::create([
    'name' => 'LKD Fitouts Brand',
    'company_name' => 'LKD Fitouts CRM',
    'company_info' => [
        'address' => 'Suite 101, 123 Business Street, Sydney NSW 2000',
        'phone' => '(02) 1234 5678',
        'email' => 'info@lkdfitouts.com.au',
        'website' => 'www.lkdfitouts.com.au',
        'abn' => '12 345 678 901',
    ],
    'colors' => [
        'primary' => '#2563eb',
        'secondary' => '#64748b',
        'accent' => '#059669',
    ],
]);

// Get formatted company information
$contactInfo = $brandingProfile->getContactInfo();
$formattedAddress = $brandingProfile->getFormattedAddress();
$cssVariables = $brandingProfile->getCSSVariables();
```

#### Logo Management with S3 Support

The system includes intelligent logo handling for PDF generation:

```php
// The ProposalAssemblyService automatically handles S3 logos
private function ensureLocalImageForPDF($file)
{
    // Downloads S3 images to local temp directory for DomPDF compatibility
    $tempDir = storage_path('app/temp/pdf-images');
    $localPath = $tempDir . '/logo_' . $file->id . '.' . $extension;
    
    // Download and cache image locally
    $imageData = file_get_contents($file->file_url);
    file_put_contents($localPath, $imageData);
    
    return $localPath;
}
```

### Proposal Assembly Service

The `ProposalAssemblyService` handles the complex process of building complete proposal HTML from templates, data, and branding.

#### Key Features

- **Template Processing**: Assembles sections from proposal templates
- **Variable Replacement**: Dynamic content substitution using `{{variable}}` syntax
- **Branding Integration**: Applies colors, fonts, and company information
- **Header Generation**: Creates professional headers with company details
- **CSS Optimization**: Optimized styling for PDF rendering

#### Header Configuration

```php
// Header configuration options
$headerConfig = [
    'enabled' => true,
    'show_phone' => true,
    'show_email' => true,
    'show_website' => true,
    'show_address' => false,
    'show_abn' => false,
];

// Headers are automatically generated on every page except cover page
$proposalData = $proposalService->assembleProposal([
    'template_id' => 6,
    'branding_id' => 1,
    'proposal_data' => $data,
    'header_config' => $headerConfig,
]);
```

### PDF Controller Enhancements

The `PDFController` has been enhanced with advanced proposal generation capabilities:

#### DomPDF Page Script Integration

```php
// Page script for headers on every page
$pageScript = '
if ($PAGE_NUM > 1) {
    // Draw header background
    $canvas->rectangle(20, 20, 555, 60, array(0.9, 0.9, 0.9), 2);
    $canvas->line(20, 80, 575, 80, array(0.2, 0.4, 0.8), 2);
    
    // Company name
    $font_bold = $fontMetrics->getFont("Arial", "bold");
    $canvas->text(30, 45, "' . addslashes($companyName) . '", $font_bold, 16, array(0.2, 0.4, 0.8));
    
    // Contact information
    $font_regular = $fontMetrics->getFont("Arial", "normal");
    $canvas->text(30, 65, "' . addslashes($headerText) . '", $font_regular, 10, array(0.3, 0.3, 0.3));
    
    // Page number
    $canvas->text(500, 45, "Page $PAGE_NUM", $font_regular, 12, array(0.4, 0.4, 0.4));
}';

$pdf->getDomPDF()->getCanvas()->page_script($pageScript);
```

#### Enhanced DomPDF Options

```php
$options = [
    'isHtml5ParserEnabled' => true,
    'isRemoteEnabled' => true,
    'isPhpEnabled' => true,
    'defaultFont' => 'sans-serif',
    'dpi' => 150,
    'enable_remote' => true,
    'enable_font_subsetting' => true,
    'enable_css_float' => true,
];
```

### Recent Enhancements (2025)

#### ProposalAssemblyService Improvements

- **Dynamic Section Titles**: Sections now use configurable titles from templates instead of hardcoded values
- **Professional Page Margins**: Consistent 40px/50px margins for all sections except cover page
- **Enhanced Table of Contents**: Dynamic title support with robust string handling for PDF compatibility
- **Branding Color Integration**: Automatic application of branding profile colors (H1=primary, H2=secondary, H3=accent)
- **PDF Layout Optimization**: Fixed text wrapping, cover page styling, and proper content flow

#### Technical Improvements

- **String Handling**: Robust handling of section titles with proper trimming and fallback logic
- **PDF Generation**: Enhanced margin system with cover page preservation
- **Content Assembly**: Improved variable replacement and template processing
- **Error Prevention**: Safeguards against blank page generation during PDF creation

### Styling Optimizations

The proposal system includes optimized CSS for PDF rendering:

#### Font Size Optimizations

```css
/* Optimized font sizes for better space utilization */
h1 { font-size: 24px; color: #2563eb; }
h2 { font-size: 20px; color: #64748b; /* Secondary color */ }
h3 { font-size: 18px; color: #10b981; /* Accent color */ }
p, li { font-size: 14px; line-height: 1.5; }

/* Professional margin system */
@page {
    margin: 0; /* Cover page has no margins */
}

.acceptance-section, 
.pricing-section, 
.terms-conditions-section, 
.change-log-section, 
.overview-section, 
.payment-terms-section, 
.agreement-signature-section, 
.content-section, 
.terms-section {
    margin: 40px 50px; /* Professional spacing for all sections except cover */
}

.omnia-cover-page {
    margin: 0 !important;
    padding: 0 !important; /* Preserve cover page full-page design */
}

/* Header-specific styling */
.proposal-header {
    width: 100%;
    padding: 10px;
    border: 2px solid #2563eb;
    background: #f9f9f9;
}

.proposal-header-content .company-name {
    font-size: 16px;
    font-weight: bold;
    color: #2563eb;
}

.proposal-header-content .company-info {
    font-size: 10px;
    color: #333;
    line-height: 1.3;
}
```

### Frontend Integration

The system includes React components for managing branding profiles:

#### BrandingProfileCompanyInfo Component

```javascript
// Enhanced company information editor
const BrandingProfileCompanyInfo = ({ brandingProfile, onSave, onCancel }) => {
    const [companyInfo, setCompanyInfo] = useState({
        address: '',
        website: '',
        phone: '',
        email: '',
        abn: '',
        ...brandingProfile?.company_info || {}
    });

    const handleSave = async () => {
        const response = await fetch(`/ajax/branding-profiles/${brandingProfile.id}`, {
            method: 'PUT',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                ...brandingProfile,
                company_info: companyInfo
            })
        });
        // Handle response...
    };

    // Render form fields for all company information
};
```

### Database Migrations

The proposal system includes comprehensive database support:

#### Branding Profiles Table

```php
Schema::create('branding_profiles', function (Blueprint $table) {
    $table->id();
    $table->string('name');
    $table->string('company_name');
    $table->string('logo_url')->nullable();
    $table->json('colors')->nullable();
    $table->json('fonts')->nullable();
    $table->json('company_info')->nullable(); // Enhanced with email support
    $table->boolean('is_default')->default(false);
    $table->timestamps();
    $table->softDeletes();
});
```

#### Proposal Templates Table

```php
Schema::create('proposal_templates', function (Blueprint $table) {
    $table->id();
    $table->string('name');
    $table->text('description')->nullable();
    $table->unsignedBigInteger('branding_profile_id')->nullable();
    $table->json('styling')->nullable(); // Includes header configuration
    $table->timestamps();
    $table->softDeletes();
    
    $table->foreign('branding_profile_id')
          ->references('id')
          ->on('branding_profiles')
          ->onDelete('set null');
});
```

### Configuration Options

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

### API Endpoints

The proposal system provides comprehensive API endpoints:

```php
// Branding Profiles
GET    /ajax/branding-profiles                     // List profiles
POST   /ajax/branding-profiles                     // Create profile
GET    /ajax/branding-profiles/{id}                // Get profile
PUT    /ajax/branding-profiles/{id}                // Update profile
DELETE /ajax/branding-profiles/{id}                // Delete profile
POST   /ajax/branding-profiles/table               // Table data
POST   /ajax/branding-profiles/dropdown            // Dropdown data
GET    /ajax/branding-profiles/{id}/css            // Get CSS
POST   /ajax/branding-profiles/{id}/upload-logo    // Upload logo

// Proposal Templates
GET    /ajax/proposal-templates                    // List templates
POST   /ajax/proposal-templates                    // Create template
GET    /ajax/proposal-templates/{id}               // Get template
PUT    /ajax/proposal-templates/{id}               // Update template
DELETE /ajax/proposal-templates/{id}               // Delete template
POST   /ajax/proposal-templates/{id}/preview       // Preview template
POST   /ajax/proposal-templates/{id}/duplicate     // Duplicate template

// PDF Generation
POST   /ajax/pdf/generate-proposal                 // Generate proposal PDF
POST   /ajax/pdf/preview-proposal                  // Preview proposal HTML
```

### Development Commands

```bash
# Install Chromium for Spatie PDF generation (recommended)
php artisan visns:install-chromium

# Seed default proposal templates
php artisan db:seed --class="Visnsstudio\VisnsPackages\Database\Seeders\DefaultProposalTemplateSeeder"

# Publish proposal migrations and seeders
php artisan vendor:publish --tag=visns-packages-migrations
php artisan vendor:publish --tag=visns-packages-seeders

# Run migrations to create proposal tables
php artisan migrate
```

This comprehensive proposal system provides everything needed for professional document generation with full branding integration and advanced header functionality.

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

#### Fast Timeout and Fallback

The package includes optimized timeout handling for Meilisearch to ensure fast response times when the search server is unavailable:

**Health Check Optimization:**
- Health checks use a 2-second timeout instead of default HTTP timeout (60+ seconds)
- Health status is cached for 10 seconds to reduce repeated checks
- Immediate fallback to database search when Meilisearch is detected as unhealthy

**Search Operation Timeout:**
- Search operations have a 3-second timeout to prevent long waits
- Quick detection of server unavailability triggers immediate fallback
- Total timeout reduced from ~60 seconds to ~5 seconds maximum

**Benefits:**
- Faster response times when Meilisearch server is down or slow
- Seamless user experience with automatic database fallback
- No impact on performance when Meilisearch is working correctly

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

    // The middleware to apply to the report builder routes
    // (ajax/reportBuilder/*). These endpoints expose the database schema and
    // execute SELECT queries built from the request payload, so they are
    // registered separately from the routes above and must always require
    // authentication. Every authenticated user may use the report builder -
    // there is no extra permission on top. Apps on a non-default guard should
    // override this, e.g. ['web', 'auth:admin'].
    'report_builder_middleware' => ['web', 'auth'],

    // The semantic model behind report definition v2: the business-language
    // entities/fields/relations the report wizard offers, mapped onto tables
    // and columns. The registry is the allowlist - a column that is not
    // published here cannot be reported on. Empty by default; see
    // docs/report-semantics.md for the full schema and the endpoint contract.
    'report_semantics' => [
        'connection' => null,
        'registrar' => null,
        'entities' => [],
    ],

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
        Route::post('/generate-proposal-spatie', 'generateProposalPDFSpatie'); // New Spatie endpoint
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
        Route::post('/generate-proposal-spatie', 'generateProposalPDFSpatie'); // New Spatie endpoint
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
