<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Routes Configuration
    |--------------------------------------------------------------------------
    |
    | This option controls whether the package routes should be registered
    | automatically. Set this to false if you want to manually register
    | the routes in your application.
    |
    */
    'register_routes' => true,

    /*
    |--------------------------------------------------------------------------
    | Routes Middleware
    |--------------------------------------------------------------------------
    |
    | The middleware that should be applied to the package routes.
    |
    */
    'routes_middleware' => ['web'],

    /*
    |--------------------------------------------------------------------------
    | Routes Prefix
    |--------------------------------------------------------------------------
    |
    | The prefix that should be applied to all package routes.
    | Leave empty for no prefix.
    |
    */
    'routes_prefix' => '',

    /*
    |--------------------------------------------------------------------------
    | API Routes Middleware
    |--------------------------------------------------------------------------
    |
    | The middleware that should be applied to the package API routes.
    |
    */
    'api_middleware' => ['api', 'accept-json'],

    /*
    |--------------------------------------------------------------------------
    | API Routes Prefix
    |--------------------------------------------------------------------------
    |
    | The prefix that should be applied to all package API routes.
    | Default is 'api'.
    |
    */
    'api_prefix' => 'api',

    /*
    |--------------------------------------------------------------------------
    | User Model
    |--------------------------------------------------------------------------
    |
    | This option allows you to specify the User model class to be used by the package.
    | By default, it uses the App\Models\User model, but you can override this
    | with your own custom User model if needed.
    |
    */
    'user_model' => env('VISNS_USER_MODEL', 'App\\Models\\User'),

    /*
    |--------------------------------------------------------------------------
    | Two-Factor Authentication App Name
    |--------------------------------------------------------------------------
    |
    | This option controls the name that appears in authenticator apps like
    | Microsoft Authenticator when users set up two-factor authentication.
    | If not set, it will use the APP_NAME environment variable or the
    | application name from config/app.php.
    |
    */
    '2fa_app_name' => env('APP_NAME', 'Omnia Global Framework'),

    /*
    |--------------------------------------------------------------------------
    | Additional User Loadable Relations
    |--------------------------------------------------------------------------
    |
    | This option allows you to specify additional relations that should be
    | loaded with the User model. These will be merged with the default
    | loadable relations defined in the User model.
    |
    */
    'user_additional_loadable_relations' => [],
];
