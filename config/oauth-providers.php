<?php

return [
    /*
    |--------------------------------------------------------------------------
    | OAuth Providers Configuration
    |--------------------------------------------------------------------------
    |
    | This file contains the configuration for OAuth providers available
    | in the visns-packages OAuth system. Each provider should extend
    | the AbstractOAuthProvider class and implement the OAuthProvider interface.
    |
    */

    'zoho_desk' => [
        'provider_class' => \Visnsstudio\VisnsPackages\Services\Providers\ZohoDeskProvider::class,
        'enabled' => env('OAUTH_ZOHO_DESK_ENABLED', false),
        'client_id' => env('ZOHO_DESK_CLIENT_ID'),
        'client_secret' => env('ZOHO_DESK_CLIENT_SECRET'),
        'redirect_uri' => env('ZOHO_DESK_REDIRECT_URI'),
        'org_id' => env('ZOHO_DESK_ORG_ID'),
        'data_center' => env('ZOHO_DESK_DATA_CENTER', 'com'),
        'sync_frequency' => 'hourly',
        'data_mapping' => [
            'contacts' => [
                'enabled' => true,
                'model' => 'App\\Models\\Contact',
                'foreign_key' => 'zoho_desk_id',
                'sync_command' => 'oauth:sync:zoho-desk:contacts',
            ],
            'accounts' => [
                'enabled' => true,
                'model' => 'App\\Models\\Customer',
                'foreign_key' => 'zoho_desk_account_id',
                'sync_command' => 'oauth:sync:zoho-desk:accounts',
            ],
        ],
    ],

    // Example: HubSpot Provider
    'hubspot' => [
        'provider_class' => \Visnsstudio\VisnsPackages\Services\Providers\HubSpotProvider::class,
        'enabled' => env('OAUTH_HUBSPOT_ENABLED', false),
        'client_id' => env('HUBSPOT_CLIENT_ID'),
        'client_secret' => env('HUBSPOT_CLIENT_SECRET'),
        'redirect_uri' => env('HUBSPOT_REDIRECT_URI'),
        'sync_frequency' => 'hourly',
        'data_mapping' => [
            'contacts' => [
                'enabled' => true,
                'model' => 'App\\Models\\Contact',
                'foreign_key' => 'hubspot_id',
                'sync_command' => 'oauth:sync:hubspot:contacts',
            ],
            'companies' => [
                'enabled' => true,
                'model' => 'App\\Models\\Customer',
                'foreign_key' => 'hubspot_company_id',
                'sync_command' => 'oauth:sync:hubspot:companies',
            ],
        ],
    ],

    // Example: Salesforce Provider
    'salesforce' => [
        'provider_class' => \Visnsstudio\VisnsPackages\Services\Providers\SalesforceProvider::class,
        'enabled' => env('OAUTH_SALESFORCE_ENABLED', false),
        'client_id' => env('SALESFORCE_CLIENT_ID'),
        'client_secret' => env('SALESFORCE_CLIENT_SECRET'),
        'redirect_uri' => env('SALESFORCE_REDIRECT_URI'),
        'instance_url' => env('SALESFORCE_INSTANCE_URL'),
        'sync_frequency' => 'hourly',
        'data_mapping' => [
            'contacts' => [
                'enabled' => true,
                'model' => 'App\\Models\\Contact',
                'foreign_key' => 'salesforce_id',
                'sync_command' => 'oauth:sync:salesforce:contacts',
            ],
            'accounts' => [
                'enabled' => true,
                'model' => 'App\\Models\\Customer',
                'foreign_key' => 'salesforce_account_id',
                'sync_command' => 'oauth:sync:salesforce:accounts',
            ],
        ],
    ],
];