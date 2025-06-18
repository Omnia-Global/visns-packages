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
    | Dynamic Entity Routes
    |--------------------------------------------------------------------------
    |
    | List of entities that should have dynamic CRUD routes automatically
    | registered by the package. This replaces the need to manually register
    | routes for each entity in your web.php file.
    | 
    | Each entity in this array will get the following routes:
    | - GET /ajax/{entity}
    | - POST /ajax/{entity}
    | - GET /ajax/{entity}/{id}
    | - PUT /ajax/{entity}/{id}
    | - DELETE /ajax/{entity}/{id}
    | - POST /ajax/{entity}/table
    | - POST /ajax/{entity}/dropdown
    | - POST /ajax/{entity}/merge
    | - POST /ajax/{entity}/detect-duplicates
    | - And JSON manipulation routes under /ajax/{entity}/json/
    |
    */
    'dynamic_entities' => [
        // Example entities:
        // 'contacts',
        // 'clients', 
        // 'leads',
        // 'projects',
    ],

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

    /*
    |--------------------------------------------------------------------------
    | Dropdown Field Configuration
    |--------------------------------------------------------------------------
    |
    | Configuration for intelligent field detection and smart label building
    | in dropdown functionality.
    |
    */
    'dropdown_fields' => [
        /*
        |--------------------------------------------------------------------------
        | Label Field Hierarchy
        |--------------------------------------------------------------------------
        |
        | Fields to try in order when building dropdown labels. The system will
        | use the first available field from this list.
        |
        */
        'label_fields' => ['label', 'name', 'title', 'full_name', 'display_name'],

        /*
        |--------------------------------------------------------------------------
        | Name Field Combinations
        |--------------------------------------------------------------------------
        |
        | Combinations of fields to concatenate when building names. The system
        | will try these combinations in order if single label fields aren't available.
        |
        */
        'name_combinations' => [
            ['title', 'firstname', 'lastname'],
            ['title', 'first_name', 'last_name'], 
            ['firstname', 'lastname'],
            ['first_name', 'last_name'],
            ['firstname', 'surname'],
            ['first_name', 'surname'],
        ],

        /*
        |--------------------------------------------------------------------------
        | ID Field Priority
        |--------------------------------------------------------------------------
        |
        | Fields to use as ID in dropdown data, in order of preference.
        |
        */
        'id_fields' => ['id', 'uuid', 'slug', 'code'],

        /*
        |--------------------------------------------------------------------------
        | Default Sort Fields
        |--------------------------------------------------------------------------
        |
        | Fields to use for sorting dropdowns when no explicit sort is specified.
        |
        */
        'sort_fields' => ['label', 'name', 'title', 'firstname', 'created_at'],
    ],

    /*
    |--------------------------------------------------------------------------
    | Report Export Configuration
    |--------------------------------------------------------------------------
    |
    | Configuration options for report export functionality, including PDF
    | generation settings and memory management.
    |
    */
    'report_export' => [
        /*
        |--------------------------------------------------------------------------
        | PDF Row Limits
        |--------------------------------------------------------------------------
        |
        | Maximum number of rows allowed for PDF export. Large datasets can cause
        | memory exhaustion in PDF generation. Set to null to disable the limit.
        |
        */
        'pdf_max_rows' => env('VISNS_PDF_MAX_ROWS', 2000),

        /*
        |--------------------------------------------------------------------------
        | Memory Limit for PDF Generation
        |--------------------------------------------------------------------------
        |
        | Memory limit to set during PDF generation. This helps handle large
        | datasets without running out of memory.
        |
        */
        'pdf_memory_limit' => env('VISNS_PDF_MEMORY_LIMIT', '1G'),

        /*
        |--------------------------------------------------------------------------
        | Auto Switch to CSV
        |--------------------------------------------------------------------------
        |
        | Automatically switch to CSV format when the dataset exceeds PDF limits.
        | If false, an error will be returned instead.
        |
        */
        'auto_switch_to_csv' => env('VISNS_AUTO_SWITCH_TO_CSV', false),

        /*
        |--------------------------------------------------------------------------
        | Simplified Styling Threshold
        |--------------------------------------------------------------------------
        |
        | Row count threshold above which simplified styling is used for PDF
        | generation to reduce memory usage.
        |
        */
        'simplified_styling_threshold' => env('VISNS_SIMPLIFIED_STYLING_THRESHOLD', 1000),

        /*
        |--------------------------------------------------------------------------
        | PDF Generation Engine
        |--------------------------------------------------------------------------
        |
        | Choose the PDF generation engine: 'dompdf', 'tcpdf', or 'chunked'.
        | - dompdf: Default Laravel DomPDF (good for small datasets)
        | - tcpdf: TCPDF library (better memory handling for large datasets)
        | - chunked: Split large datasets into multiple PDF pages/files
        |
        */
        'pdf_engine' => env('VISNS_PDF_ENGINE', 'dompdf'),

        /*
        |--------------------------------------------------------------------------
        | PDF Chunking Configuration
        |--------------------------------------------------------------------------
        |
        | Configuration for chunked PDF generation when dealing with very large
        | datasets that exceed memory limits.
        |
        */
        'pdf_chunk_size' => env('VISNS_PDF_CHUNK_SIZE', 500),
        'pdf_max_chunks' => env('VISNS_PDF_MAX_CHUNKS', 10),

        /*
        |--------------------------------------------------------------------------
        | TCPDF Row Threshold
        |--------------------------------------------------------------------------
        |
        | Row count above which TCPDF engine is automatically used instead of
        | DomPDF for better memory management.
        |
        */
        'tcpdf_threshold' => env('VISNS_TCPDF_THRESHOLD', 1000),

        /*
        |--------------------------------------------------------------------------
        | PDF Formatting Options
        |--------------------------------------------------------------------------
        |
        | Configuration for PDF cell formatting, text wrapping, and content display.
        |
        */
        'pdf_formatting' => [
            'enable_text_wrapping' => env('VISNS_PDF_TEXT_WRAPPING', true),
            'max_json_display_length' => env('VISNS_PDF_JSON_MAX_LENGTH', 100),
            'min_column_width' => env('VISNS_PDF_MIN_COLUMN_WIDTH', 25), // mm
            'max_column_width' => env('VISNS_PDF_MAX_COLUMN_WIDTH', 70), // mm
            'json_formatting_style' => env('VISNS_PDF_JSON_STYLE', 'compact'), // 'compact', 'detailed', 'minimal'
            'max_cell_height' => env('VISNS_PDF_MAX_CELL_HEIGHT', 50), // mm
            'line_height_multiplier' => env('VISNS_PDF_LINE_HEIGHT', 1.2),
        ],
    ],
];
