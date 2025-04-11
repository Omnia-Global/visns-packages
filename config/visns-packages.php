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
    'api_middleware' => ['api'],

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
    | Timezone Configuration
    |--------------------------------------------------------------------------
    |
    | These options control how the package handles timezone conversions.
    |
    */
    'timezone' => [
        /*
        |--------------------------------------------------------------------------
        | Auto Convert
        |--------------------------------------------------------------------------
        |
        | When true, datetime fields will be automatically converted between
        | storage timezone (UTC) and display timezone when serializing models.
        |
        */
        'auto_convert' => true,

        /*
        |--------------------------------------------------------------------------
        | Display Timezone
        |--------------------------------------------------------------------------
        |
        | The timezone that should be used for displaying dates to users.
        | Defaults to the application timezone (app.timezone) or UTC.
        |
        */
        'display_timezone' => env('DISPLAY_TIMEZONE', null),

        /*
        |--------------------------------------------------------------------------
        | Date Format
        |--------------------------------------------------------------------------
        |
        | The format that should be used when serializing dates.
        | If null, the model's date format will be used.
        |
        */
        'date_format' => null,

        /*
        |--------------------------------------------------------------------------
        | Preserve Timezone
        |--------------------------------------------------------------------------
        |
        | When true, dates will be serialized with the application timezone
        | information instead of being converted to UTC in JSON responses.
        | This ensures dates appear in the local timezone in API responses.
        |
        */
        'preserve_timezone' => true,
    ],
];
