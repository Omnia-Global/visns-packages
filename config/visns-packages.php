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
    | Report Builder Routes Middleware
    |--------------------------------------------------------------------------
    |
    | The middleware applied to the report builder routes (ajax/reportBuilder/*).
    |
    | These endpoints expose the database schema and can execute arbitrary
    | SELECT queries built from the request payload, so they are registered
    | with their own middleware stack and MUST require authentication. The
    | report builder itself is open to every authenticated user - no extra
    | permission is required - so 'auth' is the only guard applied on top of
    | the standard 'web' stack.
    |
    | Applications using a non-default guard should override this, e.g.
    | ['web', 'auth:admin'].
    |
    */
    'report_builder_middleware' => ['web', 'auth'],

    /*
    |--------------------------------------------------------------------------
    | Report Semantics (report definition v2)
    |--------------------------------------------------------------------------
    |
    | The semantic model: the business-language schema the report wizard is
    | built on. It maps user-facing entity / field / relation ids onto tables,
    | columns and joins, and it is the ONLY way the compiler can reach the
    | database - a column that is not published here cannot be selected,
    | filtered or sorted on, no matter what the request payload says.
    |
    | Nothing in here is guessed from the schema. Publishing a field is a
    | deliberate act, which is what makes this safe to expose to every
    | authenticated user.
    |
    | Table and column names never leave the server: the /semanticModel
    | endpoint returns labels, types and ids only.
    |
    | Full documentation, including the definition v2 payload and every
    | behavioural decision, lives in docs/report-semantics.md.
    |
    | ---------------------------------------------------------------------
    | ENTITY
    | ---------------------------------------------------------------------
    | 'clients' => [                    // entity id - opaque handle, [A-Za-z_][A-Za-z0-9_]*
    |     'label'        => 'Client',   // singular, shown in the wizard. Default: humanised id
    |     'plural'       => 'Clients',  // Default: the label
    |     'description'  => '...',      // one line of help text. Optional
    |     'table'        => 'clients',  // Default: the entity id
    |     'primary_key'  => 'id',       // Default: 'id'
    |     'soft_deletes' => null,       // null = look for a deleted_at column (cached);
    |                                   // true/false = authoritative, skips introspection
    |     'hidden'       => false,      // true = usable as a relation target but not offered
    |                                   // as a reporting root
    |     'fields'       => [...],
    |     'relations'    => [...],
    | ]
    |
    | ---------------------------------------------------------------------
    | FIELD
    | ---------------------------------------------------------------------
    | Types: text | number | money | percent | date | datetime | boolean | enum
    |
    | 'fee_amount' => [
    |     'label'          => 'Fee amount',
    |     'column'         => 'fee_amount',  // Default: the field id
    |     'type'           => 'money',
    |     'summable'       => true,          // may be summed/averaged. Default: true for
    |                                        // number/money/percent, false otherwise
    |     'description'    => '...',         // optional help text
    |     'null_sentinels' => ['1970-01-01'],// values that MEAN null: filtered as empty
    |                                        // and returned as null
    |     'values'         => [1 => 'Active'],// enum only: stored value => label
    |     'hidden'         => false,         // resolvable but not advertised to the wizard
    |     'json'           => [              // instead of 'column': a value inside a JSON
    |         'column' => 'home_address',    // document column
    |         'path'   => '$.suburb',        // $.a, $.a.b, $.a[0].b - validated at load
    |     ],
    | ]
    |
    | ---------------------------------------------------------------------
    | RELATION
    | ---------------------------------------------------------------------
    | Types: belongs_to (fk here) | has_one | has_many (fk on the other table)
    |
    | 'adviser' => [
    |     'label'        => 'Their adviser',
    |     'entity'       => 'users',      // must be another published entity
    |     'type'         => 'belongs_to', // Default: belongs_to
    |     'foreign_key'  => 'user_id',    // required
    |     'owner_key'    => 'id',         // belongs_to: key on the target. Default: 'id'
    |     'local_key'    => 'id',         // has_one/has_many: key here. Default: 'id'
    |     'zero_is_null' => true,         // a 0 foreign key means "none" - do not join to
    |                                     // whatever row happens to have id 0
    |     'hidden'       => false,
    | ]
    |
    | Relations become LEFT JOINs aliased per relation PATH, so the same
    | entity can be reached twice by different routes (adviser.name and
    | referrer.name both hit `users` without colliding), and chained hops
    | (adviser.team.name) work as long as each hop is declared.
    |
    | ---------------------------------------------------------------------
    | REGISTRAR
    | ---------------------------------------------------------------------
    | 'registrar' => \App\Reporting\ReportSemantics::class
    |
    | Optional. A class the container can build, exposing entities(): array
    | (or invokable) and returning the same structure. Merged over the config
    | entities one entity at a time, so the config array stays the primary
    | path and code is there for the parts that need to be computed.
    |
    */
    'report_semantics' => [
        // Connection the compiled report runs on. null = application default.
        'connection' => env('VISNS_REPORT_SEMANTICS_CONNECTION'),

        // Optional class returning the same entities array - see above.
        'registrar' => null,

        // Nothing is published by default: an application opts in field by
        // field. An empty registry makes /semanticModel answer with
        // {"entities": {}}, which the wizard renders as "not configured".
        'entities' => [
            /*
            'clients' => [
                'label' => 'Client',
                'plural' => 'Clients',
                'description' => 'People the practice advises',
                'table' => 'clients',

                'fields' => [
                    'firstname' => [
                        'label' => 'First name',
                        'column' => 'firstname',
                        'type' => 'text',
                    ],
                    'surname' => [
                        'label' => 'Surname',
                        'column' => 'surname',
                        'type' => 'text',
                    ],
                    'fee_amount' => [
                        'label' => 'Fee amount',
                        'column' => 'fee_amount',
                        'type' => 'money',
                        'summable' => true,
                    ],
                    // A value living inside a JSON document column.
                    'home_suburb' => [
                        'label' => 'Home suburb',
                        'json' => ['column' => 'home_address', 'path' => '$.suburb'],
                        'type' => 'text',
                    ],
                    // 1970-01-01 is this table's "never set" marker: it is
                    // filtered as empty and comes back as null.
                    'fds_due_date' => [
                        'label' => 'FDS due date',
                        'column' => 'fds_due_date',
                        'type' => 'date',
                        'null_sentinels' => ['1970-01-01'],
                    ],
                    'status' => [
                        'label' => 'Status',
                        'column' => 'status_id',
                        'type' => 'enum',
                        'values' => [1 => 'Active', 0 => 'Inactive'],
                    ],
                ],

                'relations' => [
                    'adviser' => [
                        'label' => 'Their adviser',
                        'entity' => 'users',
                        'type' => 'belongs_to',
                        'foreign_key' => 'user_id',
                        'owner_key' => 'id',
                        'zero_is_null' => true,
                    ],
                    'notes' => [
                        'label' => 'Their notes',
                        'entity' => 'client_notes',
                        'type' => 'has_many',
                        'foreign_key' => 'client_id',
                        'local_key' => 'id',
                    ],
                ],
            ],

            'users' => [
                'label' => 'Adviser',
                'plural' => 'Advisers',
                'table' => 'users',
                'fields' => [
                    'name' => ['label' => 'Name', 'column' => 'name', 'type' => 'text'],
                    'email' => ['label' => 'Email', 'column' => 'email', 'type' => 'text'],
                ],
                'relations' => [],
            ],
            */
        ],
    ],

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
        
        // Proposal system entities (backward compatible)
        'proposalTemplates',
        'proposalTemplateSections',
        'brandingProfiles',
    ],

    /*
    |--------------------------------------------------------------------------
    | Entity Configuration
    |--------------------------------------------------------------------------
    |
    | Advanced configuration for each dynamic entity, allowing custom controllers,
    | middleware, and permissions to be specified for individual entities.
    |
    */
    'entity_config' => [
        'proposalTemplates' => [
            'middleware' => ['auth', 'permission:Settings'],
            'model' => \Visnsstudio\VisnsPackages\Models\ProposalTemplate::class,
            'table_endpoint' => true,
            'dropdown_endpoint' => true,
            'soft_deletes' => true,
            // Custom endpoints still handled by ProposalTemplateController
            'custom_routes' => [
                'variables/available' => ['get' => 'getAvailableVariables'],
                'variables/intelligent' => ['get' => 'getIntelligentVariables'],
                '{id}/preview' => ['get' => 'preview'],
                '{id}/duplicate' => ['post' => 'duplicate'],
                '{id}/pdf' => ['post' => 'generatePDF'],
                '{id}/sections' => ['get' => 'getSections', 'post' => 'addSection'],
                '{id}/sections/{sectionId}' => ['put' => 'updateSection', 'delete' => 'deleteSection'],
                '{id}/sections/reorder' => ['post' => 'reorderSections'],
                '{id}/agreement-signature' => ['get' => 'getAgreementSignature', 'post' => 'saveAgreementSignature'],
            ],
        ],
        'brandingProfiles' => [
            'middleware' => ['auth', 'permission:Settings'],
            'model' => \Visnsstudio\VisnsPackages\Models\BrandingProfile::class,
            'table_endpoint' => true,
            'dropdown_endpoint' => true,
            'file_upload' => true,
            'soft_deletes' => true,
            // Custom endpoints still handled by BrandingProfileController
            'custom_routes' => [
                'default' => ['get' => 'getDefault'],
                'apply-branding' => ['post' => 'applyBranding'],
                '{id}/preview' => ['get' => 'preview'],
                '{id}/duplicate' => ['post' => 'duplicate'],
            ],
        ],
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
    | Authentication
    |--------------------------------------------------------------------------
    |
    | Everything the auth controller used to read straight out of env() lives
    | here, plus the extension points an application needs to bend the login
    | flow to its own rules without forking the controller.
    |
    | EVERY default in this section reproduces the behaviour the package had
    | before the section existed, so an application that publishes nothing keeps
    | working exactly as it did.
    |
    */
    'auth' => [
        // Falls back to visns-packages.user_model when null. Every auth code
        // path resolves the model through this - nothing hardcodes App\Models\User.
        'user_model' => null,

        // Values the controller used to read with env(), which returns null
        // once `php artisan config:cache` has run.
        'app_name' => env('APP_NAME'),
        'app_url' => env('APP_URL'),
        'front_end_url' => env('FRONT_END_URL'),
        'mail_to_dev' => env('MAIL_TO_DEV'),
        'mail_from_address' => env('MAIL_FROM_ADDRESS'),
        'allow_multiple_sessions' => env('ALLOW_MULTIPLE_SESSIONS', false),
        'default_user_role' => env('DEFAULT_USER_ROLE'),

        /*
        | Password reset mail.
        |
        | Resolution order:
        |   1. `reset_mail_factory` - any callable/invokable class:
        |          fn (string $content, string $subject): Mailable
        |      Use this when the application's mailable has a signature of its
        |      own; nothing else here has to match.
        |   2. `reset_mailable` - built as `new $mailable($content, $subject, [])`.
        |   3. Neither set - the historical call is preserved verbatim:
        |          new \App\Mail\GenericMail($content, $mailFromAddress, $subject)
        |      (yes, the second argument is the from-address; that is what this
        |      package has always sent, and applications relying on it must not
        |      break on upgrade.)
        |
        | `reset_subject` defaults to "{app_name} - Password Reset Request".
        */
        'reset_mail_factory' => null,
        'reset_mailable' => null,
        'reset_subject' => null,

        /*
        | Shape of the JSON body returned by logout_api(). The historical shape
        | is kept as the default; an application wanting the CRM's `{"error":""}`
        | sets it here.
        */
        'logout_response' => ['message' => 'Successfully logged out'],

        /*
        | Pluggable user lookup. An invokable class (or callable) receiving the
        | login identifier and the request, returning a user model or null.
        |
        |     'user_resolver' => \App\Auth\PortalUserResolver::class,
        |
        | Null keeps the built-in resolver: an identifier that validates as an
        | email address is looked up on `email`, anything else on `username`.
        */
        'user_resolver' => null,

        /*
        | Login lifecycle hooks.
        |
        | `pre_login_gates`: invokable classes run once the account has been
        | found, each called as ($user, $request) and returning either null
        | ("carry on") or a Response, which is returned to the client as-is.
        | Use for things like an inactive-client 403 or a role lockout.
        |
        | `post_login_hooks`: invokable classes run as ($user, $request) after a
        | successful login - stamping last-login columns, purging tokens, etc.
        |
        | `run_gates_before_credential_check`: by default gates run AFTER the
        | password has been verified, so a gate can never be used to probe which
        | accounts exist. Applications that must reject (say) an inactive
        | account before the password is even checked - which is what the
        | Throughlife CRM's portal login does - set this true.
        */
        'pre_login_gates' => [],
        'post_login_hooks' => [],
        'run_gates_before_credential_check' => false,

        /*
        | authenticate() has always blanked a `location` of '/' or '/login' in
        | the `previous` field it echoes back. Set false to echo the location
        | untouched.
        */
        'filter_previous' => true,

        /*
        | Every user-facing string the auth controller can emit. Defaults are
        | the strings the package has always returned.
        */
        'messages' => [
            'account_disabled' =>
                'Your account has been disabled. Please contact the administrator.',
            'login_failed' => 'Login unsuccessful, please try again.',
            'email_not_found' =>
                'The email address is not found, please try again.',
            'invalid_reset_token' =>
                'The token is no longer valid, please start the password request process again.',
            'unauthenticated' => 'Unauthenticated',
            'registration_failed' => 'An error occurred during registration.',
            'invalid_data' => 'The given data was invalid.',
            'invalid_two_factor_session' =>
                'Invalid two-factor authentication session.',
            'user_not_found' => 'User not found.',
            'invalid_two_factor_code' =>
                'The provided two-factor authentication code was invalid.',
            'two_factor_code_expired' =>
                'The verification code has expired, please request a new one.',
            'two_factor_code_missing' =>
                'No verification code has been sent, please start again.',
            'two_factor_send_failed' =>
                'The verification code could not be sent, please try again.',
        ],

        /*
        |----------------------------------------------------------------------
        | Two-factor authentication
        |----------------------------------------------------------------------
        |
        | 'driver':
        |   'totp' (default) - the existing authenticator-app flow, unchanged.
        |   'code'           - a one-time numeric code the application delivers
        |                      itself (SMS, email, whatever) through a
        |                      TwoFactorCodeSender binding.
        |
        | 'trigger' decides WHEN 2FA is demanded, and is only ever evaluated in
        | the production environment - outside production 2FA is skipped, which
        | is what both the package's TOTP flow and the CRM's code flow have
        | always done:
        |   'always'    (default) - every login that has 2FA available
        |   'ip_change'           - only when the request IP differs from the
        |                           value in `ip_column`, which is why the
        |                           post-login IP hook matters
        |   'never'               - 2FA is never demanded
        |
        | The code driver stores the live code in `code_column` and the time it
        | was sent in `code_sent_at_column`; both are nulled the moment a code
        | is used, so a code is good for exactly one login.
        |
        | 'sender' names a class implementing
        | Visnsstudio\VisnsPackages\Contracts\TwoFactorCodeSender. When null the
        | container binding for that interface is used, and when nothing is
        | bound the package's logging sender takes it (so a misconfigured
        | application fails loudly in the log rather than silently letting
        | people in).
        |
        | 'message_template' is rendered with `{code}` replaced by the code. When
        | 'append_autofill_suffix' is true the SMS autofill trailer
        | "\n\n@{host} #{code}" is appended, host coming from config('app.url').
        */
        'two_factor' => [
            'driver' => 'totp',
            'trigger' => 'always',
            'ip_column' => 'last_logged_ip_address',
            'code_column' => 'two_factor_token',
            'code_sent_at_column' => 'two_factor_token_sent_at',
            'expiry_minutes' => 15,
            'sender' => null,
            'message_template' => 'Your verification code is: {code}',
            'append_autofill_suffix' => true,
            // Remember-this-device is a TOTP feature; the code driver only
            // honours it when this is switched on.
            'remember_device' => false,
        ],

        /*
        | The whitelisted user payload returned by endpoints that must not
        | serialize a whole user model (impersonation validation, OTP login with
        | minimal_response). Relations are loaded and reduced to the named
        | fields; a missing relation becomes null.
        */
        'minimal_user' => [
            'fields' => [
                'id',
                'firstname',
                'surname',
                'email',
                'company_contact_id',
                'dateLastLogged',
            ],
            'relations' => [
                'company_contact' => [
                    'fields' => ['id', 'company_id', 'firstname', 'surname'],
                ],
            ],
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Passwordless OTP login
    |--------------------------------------------------------------------------
    |
    | A contact (email address or mobile number) is exchanged for a one-time
    | code, and the code for an API token. Off by default: enabling it publishes
    | two unauthenticated endpoints.
    |
    | 'contact_resolver' is how a raw contact string becomes the record the code
    | is stored against - an invokable class implementing
    | Visnsstudio\VisnsPackages\Contracts\OtpContactResolver. The bundled
    | default searches the user table; an application with its own contact table
    | (the CRM searches company_contact) registers its own.
    |
    | 'sender' names a Visnsstudio\VisnsPackages\Contracts\OtpSender
    | implementation; when null the container binding is used, falling back to
    | the package's logging sender.
    |
    */
    'otp' => [
        'enabled' => false,

        'uris' => [
            'request' => 'auth/request-otp',
            'login' => 'auth/login-otp',
        ],

        // Null = the package's api_middleware.
        'middleware' => null,

        'contact_resolver' => null,
        'sender' => null,

        // How the resolved contact record is joined to a user account.
        'user_model' => null,
        'user_foreign_key' => 'company_contact_id',
        'user_relations' => ['company_contact', 'roles.permissions'],

        // Name given to the Sanctum token minted on a successful OTP login.
        'token_name' => 'auth_token',

        'code_length' => 6,
        'expiry_minutes' => 5,
        'max_attempts' => 3,
        // A second request inside this window is refused with 429.
        'resend_cooldown_minutes' => 2,
        'rate_limit_window_minutes' => 15,

        // Columns on the contact record holding the live code.
        'columns' => [
            'code' => 'otp_code',
            'sent_at' => 'otp_sent_at',
            'contact_method' => 'otp_contact_method',
            'attempts' => 'otp_attempts',
            'locked_until' => 'otp_locked_until',
        ],

        // Outside production the generated code is echoed back in `dev_otp` so
        // a staging login is possible without a live SMS gateway.
        'expose_code_outside_production' => true,

        // Null = inherit auth.minimal_user.
        'minimal_user' => null,

        'messages' => [
            'contact_not_found' =>
                'Email or mobile number not found. Please contact the Throughlife team to verify your contact details.',
            'no_portal_access' =>
                'No portal access is set up for this contact. Please contact the Throughlife team to activate your portal access.',
            'no_portal_access_login' =>
                'No portal access available. Please contact the Throughlife team to activate your portal access.',
            'rate_limited' =>
                'Too many OTP requests. Please try again later or contact the Throughlife team for assistance.',
            'invalid_code' =>
                'Invalid or expired OTP. Please request a new code or contact the Throughlife team for assistance.',
            'request_failed' =>
                'An error occurred. Please contact the Throughlife team for assistance.',
            'login_failed' =>
                'An error occurred during login. Please contact the Throughlife team for assistance.',
            'sent' => 'OTP sent successfully',
            'generated' => 'OTP generated for testing',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Staff impersonation
    |--------------------------------------------------------------------------
    |
    | Staff issue a short-lived Sanctum token for a client's own account and are
    | redirected into the client-facing portal holding it. Off by default.
    |
    | The token is named "{token_prefix}:{actingStaffId}" so audit records can
    | attribute impersonated writes to the real human -
    | Visnsstudio\VisnsPackages\Support\ImpersonationActor::id() recovers it.
    |
    */
    'impersonation' => [
        'enabled' => false,

        'uris' => [
            // Issued from the CRM (session-authenticated, permission gated).
            'issue' => 'ajax/impersonateClient',
            // Consumed by the portal (unauthenticated, token only).
            'validate' => 'validateImpersonationToken',
        ],

        'issue_middleware' => null, // null = the package's routes_middleware + auth
        'validate_middleware' => null, // null = the package's api_middleware

        'permission' => 'Impersonate Client',

        'user_model' => null,
        // Column on the user table holding the id posted as `id`.
        'target_column' => 'company_contact_id',

        'token_prefix' => 'impersonation-token',
        'expires_minutes' => 60,

        // Audit record. Set to false to write no log at all.
        'log_model' => \Visnsstudio\VisnsPackages\Models\ImpersonationLog::class,

        // Base URL of the portal. Null = config('portal.url').
        'portal_url' => null,
        'portal_path' => '/portal',
        'redirect_path' => '/impersonate',

        'user_relations' => ['company_contact', 'roles.permissions'],

        // Null = inherit auth.minimal_user.
        'minimal_user' => null,

        'messages' => [
            'no_portal_account' =>
                'This client does not have a portal account yet. Please set a username and password for the client before accessing the portal.',
            'invalid_token' => 'Invalid token',
            'expired_token' => 'Token has expired',
            'user_not_found' => 'User not found',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Zoom Phone call queue pop
    |--------------------------------------------------------------------------
    |
    | Receives Zoom Phone events, keeps a table of what is ringing right now in
    | the account's call queues, and broadcasts it to every monitoring browser.
    | Off by default: enabling it publishes a webhook endpoint and expects the
    | two state tables to exist.
    |
    | The webhook always answers 200 (bar a signature failure): Zoom retries and
    | eventually disables endpoints that error or answer slowly.
    |
    */
    'call_queue' => [
        'enabled' => false,

        'uris' => [
            'webhook' => 'api/zoom/webhook',
            'live' => 'ajax/call-queue/live',
            'settings' => 'ajax/call-queue/settings',
        ],

        // The webhook is registered outside the api group so its URI is
        // absolute; only the signature middleware guards it.
        'webhook_middleware' => [],
        // Null = the package's routes_middleware.
        'routes_middleware' => null,

        'permissions' => [
            'monitor' => 'Call Queue Monitor',
            'settings' => 'Call Queue Settings',
        ],

        // Zoom's webhook signing secret. While unset the endpoint 401s
        // everything, so the feature is inert rather than open.
        'webhook_secret_token' => env('ZOOM_WEBHOOK_SECRET_TOKEN'),
        'max_clock_skew_seconds' => 300,

        'tables' => [
            'live_calls' => 'zoom_live_queue_calls',
            'settings' => 'zoom_call_queue_settings',
        ],

        /*
        | Broadcast channel. When `append_env_suffix` is true the current
        | app environment is appended ("call-queue-monitor.production"), which
        | is what any deployment sharing one Pusher app between environments
        | needs - without it dev broadcasts land in production browsers.
        */
        'channel' => 'call-queue-monitor',
        'append_env_suffix' => false,

        /*
        | Caller enrichment: an invokable implementing
        | Visnsstudio\VisnsPackages\Contracts\CallerEnrichment, called once per
        | ringing call with the caller's number and returning the client
        | snapshot to ride along in the broadcast. Null = no enrichment.
        | A throwing hook costs the pop its snapshot, never the pop itself.
        */
        'caller_enrichment' => null,

        // Settings are read on every ringing webhook, and change a few times a
        // year.
        'settings_cache_ttl' => 60,

        // A ringing row older than this is treated as abandoned: Zoom does not
        // guarantee a closing event for every call.
        'stale_after_minutes' => 15,

        /*
        | Zoom prefixes every call queue pickup code with a fixed *99, so the
        | stored code 8781 is dialled *998781. Codes are stored bare.
        */
        'pickup_prefix' => '*99',

        'api' => [
            'account_id' => env('ZOOM_ACCOUNT_ID'),
            'client_id' => env('ZOOM_CLIENT_ID'),
            'client_secret' => env('ZOOM_CLIENT_SECRET'),
            'base_url' => 'https://api.zoom.us/v2',
            'token_url' => 'https://zoom.us/oauth/token',
        ],
    ],

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
        | Export Row Ceiling
        |--------------------------------------------------------------------------
        |
        | Hard limit on the number of rows an export may pull, whatever the
        | format. This is the ceiling the v1 builder has always used; the
        | semantic (definition v2) export path reads it from here.
        |
        */
        'max_rows' => env('VISNS_REPORT_EXPORT_MAX_ROWS', 100000),

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
        | PDF Branding Logo
        |--------------------------------------------------------------------------
        |
        | Absolute path to an image (svg/png/jpg/gif) rendered at the top of PDF
        | report exports. SVG renders vector-crisp in the DomPDF engine; the
        | TCPDF engine rasterises via ImageSVG. Null disables the logo.
        |
        */
        'pdf_logo' => env('VISNS_PDF_LOGO'),
        'pdf_logo_height' => env('VISNS_PDF_LOGO_HEIGHT', 36), // px (DomPDF) / relative mm basis (TCPDF)

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

    /*
    |--------------------------------------------------------------------------
    | Proposal System Configuration
    |--------------------------------------------------------------------------
    |
    | Configuration options for the proposal generation system, including
    | templates, branding, and PDF generation settings.
    |
    */
    'proposal' => [
        /*
        |--------------------------------------------------------------------------
        | Template Configuration
        |--------------------------------------------------------------------------
        |
        | Settings for proposal template management and rendering.
        |
        */
        'templates' => [
            'default_template' => env('VISNS_PROPOSAL_DEFAULT_TEMPLATE', 'Standard Business Proposal'),
            'template_path' => 'proposals',
            'allowed_variables' => [
                '{{customer_name}}' => 'Customer Name',
                '{{customer_address}}' => 'Customer Address',
                '{{quote_number}}' => 'Quote/Proposal Number',
                '{{quote_date}}' => 'Quote Date',
                '{{current_date}}' => 'Current Date',
                '{{total_amount}}' => 'Total Amount',
                '{{company_name}}' => 'Company Name',
                '{{company_address}}' => 'Company Address',
                '{{company_phone}}' => 'Company Phone',
                '{{company_email}}' => 'Company Email',
                '{{project_manager}}' => 'Project Manager',
                '{{due_date}}' => 'Due Date',
                '{{terms_and_conditions}}' => 'Terms and Conditions',
            ],
            'section_types' => [
                'cover_page' => 'Cover Page',
                'toc' => 'Table of Contents',
                'content' => 'Content Section',
                'quote_items' => 'Quote Items',
                'terms' => 'Terms & Conditions',
            ],
        ],

        /*
        |--------------------------------------------------------------------------
        | PDF Generation Settings
        |--------------------------------------------------------------------------
        |
        | Configuration for proposal PDF generation, building on existing
        | PDF infrastructure for enhanced multi-page document support.
        |
        */
        'pdf' => [
            'multi_page_support' => true,
            'toc_generation' => true,
            'section_numbering' => true,
            'page_breaks' => true,
            'default_paper' => env('VISNS_PROPOSAL_PAPER_SIZE', 'a4'),
            'default_orientation' => env('VISNS_PROPOSAL_ORIENTATION', 'portrait'),
            'margins' => [
                'top' => 20,
                'right' => 15,
                'bottom' => 20,
                'left' => 15,
            ],
            'header_footer' => [
                'show_header' => false,
                'show_footer' => true,
                'footer_content' => '{{company_name}} | Page {PAGE_NUM} of {PAGE_COUNT}',
            ],
            
            /*
            |----------------------------------------------------------------------
            | Spatie PDF Settings (Chrome-based PDF generation)
            |----------------------------------------------------------------------
            |
            | Modern PDF generation using Chrome/Chromium for better CSS support
            | and reliable header/footer functionality.
            |
            */
            'spatie' => [
                'enabled' => env('VISNS_SPATIE_PDF_ENABLED', true),
                'default_engine' => env('VISNS_PDF_ENGINE', 'spatie'), // 'dompdf' or 'spatie'
                'chromium_path' => env('VISNS_CHROMIUM_PATH', null), // Auto-detected if null
                'node_path' => env('VISNS_NODE_PATH', null), // Auto-detected if null
                'timeout' => env('VISNS_PDF_TIMEOUT', 60), // seconds
                'options' => [
                    'printBackground' => true,
                    'displayHeaderFooter' => true,
                    'preferCSSPageSize' => true,
                    'generateTaggedPDF' => false,
                    'waitUntil' => 'networkidle0',
                ],
                'margins' => [
                    'top' => '20mm',
                    'right' => '15mm', 
                    'bottom' => '20mm',
                    'left' => '15mm',
                ],
            ],
        ],

        /*
        |--------------------------------------------------------------------------
        | Branding Configuration
        |--------------------------------------------------------------------------
        |
        | Settings for branding profile management and application.
        |
        */
        'branding' => [
            'default_profile' => env('VISNS_PROPOSAL_DEFAULT_BRANDING', 'Default'),
            'logo_max_size' => env('VISNS_PROPOSAL_LOGO_MAX_SIZE', 2048), // KB
            'supported_formats' => ['png', 'jpg', 'jpeg', 'svg'],
            'logo_storage_path' => 'branding/logos',
            'default_colors' => [
                'primary' => '#2563eb',
                'secondary' => '#64748b',
                'accent' => '#059669',
            ],
            'default_fonts' => [
                'heading' => 'Arial, sans-serif',
                'body' => 'Arial, sans-serif',
            ],
            'available_fonts' => [
                'Arial, sans-serif' => 'Arial',
                'Helvetica, sans-serif' => 'Helvetica',
                '"Times New Roman", serif' => 'Times New Roman',
                'Times, serif' => 'Times',
                'Courier, monospace' => 'Courier',
                '"DejaVu Sans", sans-serif' => 'DejaVu Sans',
                '"DejaVu Serif", serif' => 'DejaVu Serif',
                '"DejaVu Sans Mono", monospace' => 'DejaVu Sans Mono',
                'Calibri, sans-serif' => 'Calibri (Aptos alternative)',
                'Georgia, serif' => 'Georgia',
                'Verdana, sans-serif' => 'Verdana',
                'Tahoma, sans-serif' => 'Tahoma',
            ],
        ],

        /*
        |--------------------------------------------------------------------------
        | Custom Variables
        |--------------------------------------------------------------------------
        |
        | Project-specific variables that can be used in proposal templates.
        | These will be merged with the system variables above.
        |
        */
        'custom_variables' => [
            // Example:
            // '{{project_manager}}' => 'user.name',
            // '{{company_abn}}' => 'settings.company_abn',
        ],

        /*
        |--------------------------------------------------------------------------
        | Intelligent Variables Configuration
        |--------------------------------------------------------------------------
        |
        | Configuration for automatically generating variables from Laravel models.
        | The system will introspect these models and their fields to create
        | available variables for proposal templates.
        |
        */
        'intelligent_variables' => [
            'enabled' => env('VISNS_PROPOSAL_INTELLIGENT_VARIABLES', true),
            
            /*
            |--------------------------------------------------------------------------
            | Model Configuration
            |--------------------------------------------------------------------------
            |
            | Each model class can be configured with specific settings for variable
            | generation. The key is the full model class name, and the value is an
            | array of configuration options.
            |
            */
            'models' => [
                // Examples (to be configured per project):
                // 'App\\Models\\Customer' => [
                //     'name' => 'Customer Information',
                //     'icon' => 'User',
                //     'include' => ['name', 'email', 'phone', 'address', 'abn'],
                //     'exclude' => ['password', 'remember_token', 'created_at', 'updated_at'],
                //     'relationships' => [
                //         'primaryContact' => [
                //             'name' => 'Primary Contact',
                //             'include' => ['name', 'email', 'phone'],
                //             'exclude' => []
                //         ]
                //     ]
                // ],
                // 'App\\Models\\Quote' => [
                //     'name' => 'Quote Details',
                //     'icon' => 'FileText',
                //     'include' => ['quote_number', 'total', 'subtotal', 'tax', 'valid_until'],
                //     'exclude' => ['created_at', 'updated_at']
                // ],
                // 'App\\Models\\User' => [
                //     'name' => 'Sales Representative',
                //     'icon' => 'DollarSign',
                //     'include' => ['firstname', 'lastname', 'email', 'phone'],
                //     'exclude' => ['password', 'remember_token', 'email_verified_at']
                // ]
            ],
            
            /*
            |--------------------------------------------------------------------------
            | Global Field Exclusions
            |--------------------------------------------------------------------------
            |
            | Fields that should be excluded from all models by default for security
            | and data privacy reasons.
            |
            */
            'global_exclusions' => [
                'password',
                'remember_token',
                'email_verified_at',
                'two_factor_secret',
                'two_factor_recovery_codes',
                'two_factor_confirmed_at',
                'api_token',
            ],
            
            /*
            |--------------------------------------------------------------------------
            | Variable Naming Convention
            |--------------------------------------------------------------------------
            |
            | Configure how variable names are generated from model fields.
            |
            */
            'naming' => [
                'use_model_prefix' => true,
                'prefix_separator' => '_',
                'convert_to_snake_case' => true,
                'remove_model_suffix' => true, // Remove 'Model' from class names
            ],
            
            /*
            |--------------------------------------------------------------------------
            | Caching Configuration
            |--------------------------------------------------------------------------
            |
            | Cache variable introspection results to improve performance.
            |
            */
            'cache' => [
                'enabled' => env('VISNS_PROPOSAL_CACHE_VARIABLES', true),
                'ttl' => env('VISNS_PROPOSAL_CACHE_TTL', 3600), // 1 hour
                'key_prefix' => 'visns_proposal_variables',
            ]
        ],

        /*
        |--------------------------------------------------------------------------
        | Feature Flags
        |--------------------------------------------------------------------------
        |
        | Enable or disable specific proposal system features for backward
        | compatibility and gradual rollout.
        |
        */
        'features' => [
            'enable_proposal_mode' => env('VISNS_PROPOSAL_ENABLE', true),
            'enable_template_builder' => env('VISNS_PROPOSAL_TEMPLATE_BUILDER', true),
            'enable_branding_profiles' => env('VISNS_PROPOSAL_BRANDING', true),
            'enable_multi_page_pdf' => env('VISNS_PROPOSAL_MULTIPAGE', true),
            'backward_compatible_quotes' => env('VISNS_PROPOSAL_BACKWARD_COMPAT', true),
        ],

        /*
        |--------------------------------------------------------------------------
        | Integration Settings
        |--------------------------------------------------------------------------
        |
        | Settings for integrating the proposal system with existing quote
        | functionality and other system components.
        |
        */
        'integration' => [
            'quote_model' => env('VISNS_QUOTE_MODEL', 'App\\Models\\Quote'),
            'auto_create_proposals' => env('VISNS_AUTO_CREATE_PROPOSALS', false),
            'default_proposal_status' => 'draft',
            'file_attachment_enabled' => true,
            'email_integration_enabled' => false,
        ],
    ],
];
