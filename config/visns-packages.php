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

    /*
    |--------------------------------------------------------------------------
    | User Dynamic Relationships
    |--------------------------------------------------------------------------
    |
    | This option allows you to define additional relationships for the User model.
    | Each key is the relationship name, and the value is an array with:
    | - type: The relationship type (hasOne, hasMany, belongsTo, belongsToMany, etc.)
    | - model: The related model class
    | - foreign_key: (Optional) The foreign key
    | - local_key: (Optional) The local key
    | - pivot_table: (Required for belongsToMany) The pivot table name
    | - pivot_foreign_key: (Optional for belongsToMany) The pivot foreign key
    | - pivot_related_key: (Optional for belongsToMany) The pivot related key
    |
    */
    'user_dynamic_relationships' => [
        // Example:
        // 'profile' => [
        //     'type' => 'hasOne',
        //     'model' => 'App\\Models\\Profile',
        //     'foreign_key' => 'user_id',
        //     'local_key' => 'id',
        // ],
        // 'posts' => [
        //     'type' => 'hasMany',
        //     'model' => 'App\\Models\\Post',
        //     'foreign_key' => 'user_id',
        //     'local_key' => 'id',
        // ],
        // 'tags' => [
        //     'type' => 'belongsToMany',
        //     'model' => 'App\\Models\\Tag',
        //     'pivot_table' => 'user_tag',
        //     'pivot_foreign_key' => 'user_id',
        //     'pivot_related_key' => 'tag_id',
        // ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Model Discovery Configuration
    |--------------------------------------------------------------------------
    |
    | These options control how the package discovers models for operations
    | like Meilisearch syncing and debugging.
    |
    */
    'model_paths' => [
        // Additional paths to search for model files
        // Example: base_path('packages/my-package/src/Models'),
    ],

    'model_namespaces' => [
        // Additional namespaces to search for models
        // Example: 'MyPackage\\Models\\',
    ],

    /*
    |--------------------------------------------------------------------------
    | Search Configuration
    |--------------------------------------------------------------------------
    |
    | Configuration options for search functionality within the package.
    |
    */
    'search' => [
        /*
        |--------------------------------------------------------------------------
        | Force Disable Meilisearch
        |--------------------------------------------------------------------------
        |
        | This option allows you to force disable Meilisearch integration even if
        | it's properly configured in your application. This can be useful for
        | debugging or when you want to use the default database search.
        |
        */
        'force_disable_meilisearch' => env('VISNS_DISABLE_MEILISEARCH', false),
    ],
];
