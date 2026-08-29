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
        | Does "remember me" actually remember?
        |
        | The login screens have always sent `remember` and these endpoints have
        | always accepted it - and then dropped it on the floor. Nothing was
        | passed to the session guard, so no recaller cookie was ever issued and
        | the tick box did nothing at all.
        |
        | True wires it up: a login that asked to be remembered is made with
        | Auth::login($user, true), and Laravel issues the long-lived recaller
        | cookie. It defaults to FALSE because switching it on lengthens how
        | long a session survives on every machine a user ticks the box on -
        | that is a security posture decision, not a bug fix, and it must not
        | happen to an application merely because it upgraded.
        |
        | The users table needs the standard `remember_token` column. A model
        | without it degrades to the old behaviour (no recaller) with a warning
        | in the log, rather than failing the login on a missing column.
        |
        | Note this is unrelated to `two_factor.remember_device`, which is about
        | skipping the 2FA challenge on a known device and is stored in the
        | package's own table. The two features share the `remember` request
        | field but nothing else - see the AuthController for how the two are
        | told apart.
        */
        'remember_enabled' => false,

        // Socialite redirects. FRONTEND_URL is a different variable from the
        // FRONT_END_URL above; both exist in the wild, so both are honoured.
        'socialite' => [
            'frontend_url' => env('FRONTEND_URL', '/'),
            'default_role' => env('SOCIALITE_DEFAULT_ROLE'),
        ],

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
        | Password reset extension points.
        |
        | 'reset_user_resolver' - invokable ($email, $request): ?User, used by
        | BOTH forgot() (which account is this address for?) and reset() (which
        | account does this token's stored address belong to?). Null keeps the
        | built-in lookup: a straight match on the `email` column. An
        | application whose accounts can be reached by more than one address -
        | the Throughlife CRM resolves a client's contact email to the login
        | account behind it - supplies its own.
        |
        | 'reset_key_by_resolved_email' - which address the password_resets row
        | is keyed on. False (the default, and what this package has always
        | done) stores the address the user TYPED; true stores the resolved
        | account's own email. The two differ exactly when the resolver looked
        | past the typed address - and when they differ, `false` writes a row
        | that reset() can never match an account back to. Any application
        | setting a resolver almost certainly wants this true.
        |
        | 'reset_url_builder' - invokable ($user, $token, $request): string,
        | returning the whole link. Null reproduces the historical build:
        | front_end_url when the request carries frontend=true, app_url
        | otherwise, plus '/verify/{token}'. Applications needing another shape
        | (a query-string token, a portal host, a per-user path) build it
        | themselves.
        |
        | 'after_reset_hooks' - invokables ($user, $plainPassword) run once the
        | new password is saved, for mirroring it onto a second record (the CRM
        | keeps a copy on the client's contact row). They are handed the
        | PLAINTEXT password, because a mirror generally has to hash it itself -
        | so a hook must never log, transmit or persist it unhashed.
        */
        'reset_user_resolver' => null,
        'reset_key_by_resolved_email' => false,
        'reset_url_builder' => null,
        'after_reset_hooks' => [],

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
            // Environments in which a second factor is ever demanded. The
            // production-only default matches every flow this module replaced.
            'environments' => ['production'],
            'ip_column' => 'last_logged_ip_address',
            'code_column' => 'two_factor_token',
            'code_sent_at_column' => 'two_factor_token_sent_at',
            // Where the user's mobile lives, for the SMS senders that read it
            // (Auth\ZoomSmsTwoFactorCodeSender).
            'mobile_column' => 'mobile',
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
    | Passkeys (WebAuthn)
    |--------------------------------------------------------------------------
    |
    | Signing in with the fingerprint, face or PIN that already unlocks a
    | device. Two ceremonies, either side of the sign-in line:
    |
    |   enrolment (signed in)  options -> browser creates a key pair -> store
    |   sign-in   (guest)      options -> browser signs the challenge -> verify
    |
    | Off by default: enabling it publishes two unauthenticated endpoints, so
    | an existing consumer sees no new surface on upgrade.
    |
    | TWO THINGS THIS MODULE CANNOT DO FOR THE APPLICATION, because both live
    | in files the package does not own. Enabling it without them leaves every
    | ceremony failing:
    |
    |   1. The user model must carry the credential relation:
    |
    |        use Laragear\WebAuthn\Contracts\WebAuthnAuthenticatable;
    |        use Laragear\WebAuthn\WebAuthnAuthentication;
    |
    |        class User extends Authenticatable implements WebAuthnAuthenticatable
    |        {
    |            use WebAuthnAuthentication;
    |        }
    |
    |   2. config/auth.php's `users` provider must use the driver that knows
    |      how to verify an assertion. `password_fallback` keeps every existing
    |      email-and-password sign-in working; false would make passkeys the
    |      only way in and lock out every account without one:
    |
    |        'users' => [
    |            'driver' => 'eloquent-webauthn',
    |            'model' => App\Models\User::class,
    |            'password_fallback' => true,
    |        ],
    |
    | The credentials table is one of the package's publishable migrations -
    | `php artisan vendor:publish --tag=visns-packages-migrations`.
    |
    | The default URIs are not free to change casually: the sign-in pair is
    | what @visns-studio/visns-components' Login screen posts to, and those
    | two paths are baked into the published front end.
    |
    | The relying party and the ceremony's own settings live in the library's
    | config/webauthn.php. Publishing that file is optional - left unpublished
    | `relying_party.id` is empty, and the `webauthn.rp` middleware fills it in
    | from the host of each request, which is what lets one deployment work on
    | whatever host it is served from.
    |
    */
    'passkeys' => [
        'enabled' => false,

        'uris' => [
            // Guest. Fixed by the front end - see above.
            'login_options' => 'login/passkey/options',
            'login' => 'login/passkey',

            // Authenticated management.
            'index' => 'ajax/passkeys',
            'register_options' => 'ajax/passkeys/options',
            'register' => 'ajax/passkeys/register',
            // The credential id is the route parameter. It is base64url, so it
            // is path-safe, but it can run to several hundred characters.
            'destroy' => 'ajax/passkeys/{id}',
        ],

        /*
        | Null = the package default shown in the comment beside each.
        |
        | `webauthn.rp` binds the ceremony to the host being browsed; without
        | it the library falls back to the host of APP_URL and every ceremony
        | on any other origin fails on "Response origin not allowed for this
        | app". The throttle is on the guest pair because those two are the one
        | unauthenticated route pair that can hand out a session.
        */
        'guest_middleware' => null, // ['guest', 'webauthn.rp', 'throttle:20,1']
        'auth_middleware' => null, // ['auth', 'webauthn.rp']

        // A label the person will recognise in a list six months from now.
        'alias_max_length' => 60,

        // Relations loaded onto the user handed back by a successful sign-in.
        // The front end reads permissions off this payload, exactly as it does
        // for the email-and-password response.
        'user_relations' => ['roles.permissions'],

        'messages' => [
            'assertion_rejected' =>
                'That passkey was not accepted. Please try again, or sign in with your email.',
            'not_found' => 'That passkey no longer exists.',
            'disabled' => 'Passkeys are not enabled on this site.',
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

        /*
        | Clear the stored code the moment it is spent.
        |
        | False (the default) is what the controller this was ported from does:
        | a used code keeps working until it expires, so anyone who saw it -
        | over the shoulder, in an SMS preview on a lock screen, in a mail
        | client's notification - can log in again inside that window.
        |
        | True closes the window: one code, one login. It is the right setting
        | for any new adopter; it defaults to false only so that adopting this
        | module cannot silently change how an existing deployment behaves.
        */
        'consume_on_success' => false,

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
            'presence' => 'ajax/call-queue/presence',
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
        |----------------------------------------------------------------------
        | Zoom Phone presence — the live extension roster
        |----------------------------------------------------------------------
        |
        | "Who is on the phone right now": every Zoom Phone extension, who is
        | free, who is ringing, who is talking, to what number, and which client
        | that number resolves to. Served at `uris.presence` and drawn by
        | ZoomPhoneBadge in visns-components.
        |
        | TWO SOURCES, because Zoom offers no single one:
        |
        |   WHO   GET /phone/users, cached for `roster_cache_ttl`. Only the REST
        |         API knows about an extension that has not had a call today, so
        |         a directory built from webhooks alone would start empty and
        |         never list the quiet half of the office. This is the only Zoom
        |         request the feature makes, and it is never made in a browser.
        |   WHAT  The signed webhook this module already receives, recorded per
        |         extension by PhonePresenceRecorder. Zoom Phone exposes no "is
        |         this extension busy" endpoint: /users/{id}/presence_status is
        |         Zoom MEETINGS presence (available / in a meeting / DND) and
        |         says nothing about calls, and it needs a scope a Phone-only
        |         marketplace app does not carry.
        |
        | The consequence to know about: "Available" means "no live call leg for
        | this extension", which is an inference from the webhook subscription
        | being healthy. The popover prints the age of its snapshot next to the
        | status dots rather than letting a row of green stand on its own.
        |
        | EXTRA EVENT SUBSCRIPTIONS beyond the call queue pop's, on the Zoom
        | marketplace app: phone.caller_ringing and phone.caller_connected, which
        | are the outbound legs. The inbound ones are already subscribed.
        |
        | Client names come from `caller_enrichment` below — the same hook the
        | pop uses, so number -> client is resolved in exactly one place.
        |
        | Off by default: it costs an extra table and a wider read of the event
        | stream, and an application running the queue pop alone should not grow
        | either.
        */
        'presence' => [
            'enabled' => false,

            // Null = `permissions.monitor`. Whoever may watch the call pop
            // already sees a live caller's number and the client it resolves
            // to, which is what the roster shows; a separate permission would
            // be a row assigned to nobody, i.e. a header chip that renders for
            // no one until an administrator ticks it.
            'permission' => null,

            'tables' => ['live_calls' => 'zoom_phone_live_calls'],

            // Staff join a few times a year; the popover opens all day.
            'roster_cache_ttl' => 600,

            // Legs Zoom never closed. Two windows because they mean different
            // things: a call can genuinely run for an hour, a phone cannot ring
            // for five minutes.
            'stale_after_minutes' => 240,
            'ringing_stale_after_minutes' => 5,

            // Extension types that are somebody's handset. Call queues and auto
            // receptionists are routing objects and never appear on a roster of
            // people.
            'extension_types' => ['user', 'commonarea'],
        ],

        /*
        | The event classes the webhook dispatches.
        |
        | Configurable because Laravel's Event::fake() keys listeners by EXACT
        | class name: a subclass, a container alias or a class_alias() of the
        | package event is a different key, so an application whose listeners
        | and tests are written against its own App\Events\CallQueue* classes
        | cannot be reached by dispatching the package's. Naming them here lets
        | the module drive an application's existing event classes verbatim.
        |
        | REQUIRED CONSTRUCTOR CONTRACT - a configured class is constructed with
        | exactly the same arguments as the package class it replaces:
        |
        |   ringing            __construct(ZoomLiveQueueCall $call)
        |   answered / ended   __construct(string $callId)
        |
        | The model passed to `ringing` is
        | Visnsstudio\VisnsPackages\Models\ZoomLiveQueueCall, NOT the
        | application's own model of the same name. A replacement class must
        | therefore widen or drop that parameter's type hint, or PHP will refuse
        | the call - see the README.
        */
        'events' => [
            'ringing' => \Visnsstudio\VisnsPackages\Events\CallQueueRinging::class,
            'answered' => \Visnsstudio\VisnsPackages\Events\CallQueueAnswered::class,
            'ended' => \Visnsstudio\VisnsPackages\Events\CallQueueEnded::class,
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
        | Authorize the private channel here, admitting anyone holding the
        | monitor permission. Set false when the application authorizes the
        | channel itself in routes/channels.php - the package registration is a
        | convenience, not a policy.
        */
        'register_broadcast_channel' => true,

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

        /*
        | The Zoom client the settings page talks to.
        |
        | A class STRING, resolved through the container - never a closure, so
        | this file survives `php artisan config:cache`. Because the container
        | is asked for whatever class is named here, an application's
        | `instance()` or `bind()` double for that class is honoured: a test
        | suite can substitute a fake and be certain no save reaches the live
        | Zoom tenant.
        |
        | Point it at your own client when you already have one - its own
        | credentials, base service or retry policy. It must satisfy the public
        | contract in the README ("Substituting your own Zoom client"): the
        | settings page calls listQueues(), setPickupCode() and
        | disablePickupCode(); getQueue() and getPolicies() round out the
        | package's own class but are not called from here.
        |
        | The `api` credentials below are read by the PACKAGE's client only. A
        | replacement is constructed by the container and reads its own.
        */
        'zoom_service' => \Visnsstudio\VisnsPackages\Services\Zoom\ZoomCallQueueService::class,

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
    | Universal search
    |--------------------------------------------------------------------------
    |
    | One search box across the whole application. Every CRM on this package
    | has the same problem: a user knows a name or a ticket number and has to
    | guess which list page it lives on before they can look for it.
    |
    | WHAT IS SEARCHED IS CONFIGURATION. Each entry under `sources` names a
    | model, the columns to match, what to show, and where a hit goes. A source
    | the caller lacks the permission for is never queried at all - not merely
    | hidden from the results - so search cannot become a way to confirm that a
    | record exists.
    |
    | Column names may be `relation.column`; the query goes through whereHas.
    |
    |   'customers' => [
    |       'label'      => 'Customers',
    |       'model'      => \App\Models\Customer::class,
    |       'columns'    => ['name', 'abn'],
    |       'title'      => 'name',                      // one = fallback chain
    |       'subtitle'   => ['abn', 'status'],           // several = composite
    |       'url'        => '/customers/{id}',
    |       'permission' => 'Customer',
    |       'with'       => ['sites'],                   // optional eager load
    |       'where'      => ['status' => 1],             // optional constraint
    |       'scope'      => 'active',                    // optional model scope
    |   ],
    |
    */
    'universal_search' => [
        'enabled' => env('VISNS_UNIVERSAL_SEARCH', true),

        'uris' => ['base' => 'ajax/search'],

        'routes_middleware' => ['web', 'auth'],

        // Below this, searching is more noise than signal.
        'min_length' => 2,

        // Per source. The palette shows a few of each rather than fifty of one.
        'per_source' => 5,

        // Empty by default: a shared package cannot know what a consuming app
        // wants searched, and guessing would expose models nobody asked to
        // publish.
        'sources' => [],
    ],

    /*
    |--------------------------------------------------------------------------
    | Vault (staff password manager)
    |--------------------------------------------------------------------------
    |
    | A shared credential store for staff: a title, a username, a URL, an
    | encrypted password, an encrypted TOTP seed and encrypted notes. Ships
    | disabled - no routes, no tables read, nothing to publish - so upgrading
    | without touching config changes nothing.
    |
    | THREAT MODEL, in one sentence: anyone holding APP_KEY plus a database dump
    | can read the vault. The three secret columns are encrypted with the
    | application key, which means the key is the whole of the protection - keep
    | it out of the repository, rotate it through APP_PREVIOUS_KEYS and
    | `php artisan vault:reencrypt`, and treat the "Vault Manage" permission as
    | the administrative grant it is.
    |
    | Two tables are needed (published with the package's migrations):
    | `vault_entries` and `vault_access_logs`. Permissions are NOT seeded here -
    | the application owns its permission table; create the two names below in
    | your own seeder.
    |
    */
    'vault' => [
        'enabled' => false,

        // Every endpoint hangs off this one base, so an application can move
        // the whole module with a single key.
        'uris' => [
            'base' => 'ajax/vault',
        ],

        // The vault is not something an anonymous session may reach, so 'auth'
        // is part of the default rather than left to the package-wide stack.
        'routes_middleware' => ['web', 'auth'],

        /*
        | `access` is the permission to use the vault at all; `manage` is the
        | administrative grant - shared entries, other people's entries, restore
        | and the access log. Set either to null to gate it some other way.
        */
        'permissions' => [
            'access' => 'Vault Access',
            'manage' => 'Vault Manage',
        ],

        /*
        | Whether revealing a password first asks the signed-in user to type
        | their own CRM password again (POST {base}/confirm-password). Off
        | means being logged in is enough; every reveal is still logged.
        */
        'require_password_confirmation' => true,

        // How long a password confirmation keeps revealing passwords unlocked.
        'confirmation_ttl_minutes' => 10,

        /*
        | Laravel throttle strings, "<max>,<minutes>". `reveal` covers the
        | password reveal and the TOTP code (a code is re-fetched every period,
        | so it has to be generous); `confirm` is deliberately tight - it guards
        | a password check.
        */
        'throttle' => [
            'reveal' => '60,1',
            'confirm' => '5,1',
        ],

        'tables' => [
            'entries' => 'vault_entries',
            'access_logs' => 'vault_access_logs',
            'shares' => 'vault_shares',
        ],

        /*
        | External share links - "send this password to the client's IT
        | contact" without putting it in an email.
        |
        | A link is created from inside the vault, carries an expiry and
        | optionally a view budget, and can be revoked. THE LINK IS THE SECRET:
        | there is no password on the public side, so anyone holding the URL
        | inside those limits can read the fields it was created with. Those
        | three limits are the entire control surface, which is why there is no
        | "never expires" option anywhere above the schema.
        |
        | The public page lives OUTSIDE the auth middleware by definition. It
        | renders nothing sensitive on GET - the reveal is a POST from a button
        | - so that a chat client fetching the URL for a preview card cannot
        | spend a view or cache the secret.
        |
        | Set `enabled` to false to remove the feature entirely: no endpoints,
        | no public route, existing rows untouched and unreachable.
        */
        'share' => [
            'enabled' => true,

            'uris' => [
                // Where the recipient's page lives. NOT under the ajax prefix:
                // this is a URL a person gets sent in a message.
                'public' => 'vault/share',
            ],

            // `web` only - a session for the CSRF token on the reveal form.
            // No auth, no permission; that is the feature.
            'routes_middleware' => ['web'],

            /*
            | Laravel throttle strings, "<max>,<minutes>", keyed by IP on an
            | unauthenticated request. `reveal` is the tight one: it spends a
            | view and decrypts a secret, where `view` serves a static page.
            | `create` guards the staff endpoint that mints links.
            */
            'throttle' => [
                'view' => '30,1',
                'reveal' => '10,1',
                'create' => '20,1',
            ],

            // The longest a link may live. There is no way to exceed this and
            // no way to opt out of expiry.
            'max_days' => 30,

            /*
            | Whether the create endpoint will EMAIL the link for the sender.
            | The mail carries the entry's title, the link and the link's
            | limits - never a username, password, code or note - and goes out
            | on the application's default mailer at the moment the link is
            | minted, which is the only moment the raw URL exists on this side.
            |
            | False removes the field from the dialog (the share list endpoint
            | says so in its `meta`) and refuses a `recipient_email` on create.
            | Worth turning off wherever outbound mail goes somewhere it should
            | not - a staging environment on a catch-all mailbox, say.
            */
            'email_enabled' => true,

            // The Blade view the public page renders. Publish
            // `visns-packages-views` and point this at your own to restyle it.
            'view' => 'visns-packages::vault.share',

            // The Blade view the email above renders. Same deal.
            'mail_view' => 'visns-packages::vault.mail.share-link',
        ],

        // Null = the package-wide `user_model`.
        'user_model' => null,

        /*
        | Columns the list search runs a LIKE over. Whitelisted against the
        | entry table's own non-secret columns at query time, so an unknown or
        | secret column name here is dropped rather than trusted. The tags
        | column is always searched as text in addition to these.
        */
        // `client_label` is included so typing a client's name finds every
        // credential held for them, not just the ones whose title happens to
        // mention it.
        'search_columns' => ['title', 'username', 'url', 'client_label'],

        /*
        | Assigning an entry to a client.
        |
        | Most credentials in a CRM are the CLIENT's - their firewall, their
        | NVR, their vendor portal - not the practice's own. This names the
        | model so the vault can offer a picker and show who each entry
        | belongs to.
        |
        | `null` disables the feature entirely: no picker, no column, and the
        | client columns simply stay empty. A consuming app without a client
        | concept is unaffected.
        */
        'client' => [
            'model' => null,                    // e.g. \App\Models\Customer::class
            'label_column' => 'name',           // what to show for one
            'search_columns' => ['name'],       // what the picker searches
            'url' => null,                      // e.g. '/customers/{id}'
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Messaging (SMS)
    |--------------------------------------------------------------------------
    |
    | A virtual SMS inbox and outbox for staff, hanging off the practice's own
    | phone numbers ("lines"). Threads, per-user unread counts, canned
    | templates, and a live push when a text arrives.
    |
    | THE TRANSPORT IS PLUGGABLE, AND THAT IS THE POINT. This module was built
    | before the provider was available: the practice is waiting on an
    | SMS-capable mobile number for its Zoom Phone account. So it ships fully
    | usable with a dev transport, inert with the null transport, and ready for
    | Zoom the day the number exists - see the README's "Messaging (SMS)".
    |
    |   'null'  nothing leaves; the message is stored `not_connected` and shown
    |           greyed out in the thread. The safe production setting until Zoom
    |           is connected.
    |   'log'   development: logs the send, reports success, and texts back so
    |           the whole loop can be exercised on a laptop. NEVER in production.
    |   'zoom'  the real thing.
    |   or a class-string implementing Contracts\SmsTransport.
    |
    | Ships disabled - no routes, no tables read, nothing published - so
    | upgrading without touching config changes nothing.
    |
    | NAMING: everything here is prefixed `sms_` / `Sms` on purpose. Consuming
    | applications already have a Message model and a /messages route of their
    | own, and a module that collided with those would be unadoptable.
    |
    | Permissions are NOT seeded here - the application owns its permission
    | table; create the two names below in your own seeder.
    |
    */
    'messaging' => [
        'enabled' => false,

        // Every endpoint hangs off this one base, so an application can move the
        // whole module with a single key.
        'uris' => [
            'base' => 'ajax/sms',
        ],

        // An inbox of client conversations is not something an anonymous session
        // may reach, so 'auth' is part of the default rather than left to the
        // package-wide stack.
        'routes_middleware' => ['web', 'auth'],

        /*
        | `access` is the permission to use messaging at all; `manage` is the
        | administrative grant - every line, the line settings, the templates and
        | the inbound simulator. Set either to null to gate it some other way.
        |
        | Visibility for a non-manager is the `sms_line_user` pivot: they see the
        | lines they are attached to and nothing else, and everything else 404s.
        */
        'permissions' => [
            'access' => 'Messaging Access',
            'manage' => 'Messaging Manage',
        ],

        'tables' => [
            'lines' => 'sms_lines',
            'line_user' => 'sms_line_user',
            'threads' => 'sms_threads',
            'messages' => 'sms_messages',
            'thread_reads' => 'sms_thread_reads',
            'templates' => 'sms_templates',
            'system_messages' => 'sms_system_messages',
        ],

        /*
        | 'zoom' | 'log' | 'null' | a class-string implementing
        | Visnsstudio\VisnsPackages\Contracts\SmsTransport.
        |
        | A class-string is built through the container, so an application's
        | own binding (or a test's fake) is honoured.
        */
        'transport' => 'null',

        /*
        | How a number with no country code is read. Everything - a webhook
        | payload, a typed number, a client record - is normalised to E.164
        | before it is stored or compared, because the module's whole routing
        | rule is an equality check between a stored line number and a number
        | the provider sent.
        */
        'default_country' => 'AU',

        /*
        | The E.164 number of the line used for APPLICATION-originated texts -
        | login codes, portal OTPs, reminders. Those never appear in the shared
        | inbox (a staff member with Messaging Access could otherwise read
        | somebody else's login code), so they are sent by
        | Services\Sms\SmsSystemSender and recorded, without their body, in the
        | `system_messages` table.
        |
        | Null = the first active line that has a zoom_user_id, which is the
        | right answer in every deployment with one SMS number. Set it when the
        | practice has several and codes must come from a particular one.
        */
        'system_line' => null,

        /*
        | Broadcast channel. Private, and PER LINE: "sms-line.{lineId}", plus
        | ".{env}" when `append_env_suffix` is true - which is what any
        | deployment sharing one Pusher app between environments needs, or dev
        | broadcasts land in production browsers.
        */
        'channel' => 'sms-line',
        'append_env_suffix' => false,

        /*
        | Authorize the private channel here, admitting anyone attached to the
        | line or holding the manage permission. Set false when the application
        | authorizes the channel itself in routes/channels.php - the package
        | registration is a convenience, not a policy.
        */
        'register_broadcast_channel' => false,

        /*
        | The event classes the module dispatches.
        |
        | Configurable for the same reason the call queue's are: Laravel's
        | Event::fake() keys listeners by EXACT class name, so an application
        | whose listeners and tests are written against its own App\Events\Sms*
        | classes cannot be reached by dispatching the package's.
        |
        | REQUIRED CONSTRUCTOR CONTRACT - a configured class is constructed with
        | exactly the same arguments as the package class it replaces:
        |
        |   received / updated   __construct(SmsThread $thread, SmsMessage $message)
        |
        | Both models are the PACKAGE's (Visnsstudio\VisnsPackages\Models\Sms*),
        | NOT an application's own classes of the same name, so a replacement
        | class must widen or drop those type hints or PHP will refuse the call.
        */
        'events' => [
            'received' => \Visnsstudio\VisnsPackages\Events\SmsReceived::class,
            'updated' => \Visnsstudio\VisnsPackages\Events\SmsMessageUpdated::class,
        ],

        /*
        | Number -> client. An invokable (class name, object or closure) called
        | once, when a thread is first created, with the outside number in E.164
        | and returning ['id' => .., 'name' => .., ...] or null.
        |
        | Deliberately the same contract as the call queue's caller enrichment
        | (Contracts\CallerEnrichment), so an application can pass the SAME
        | implementation to both and a number that pops a client card on an
        | incoming call also names the client on an incoming text.
        |
        | A throwing hook costs the thread its client name, never the message.
        */
        'client_resolver' => null,

        /*
        | Client search, for the "text somebody" box: an invokable taking the
        | typed term and returning
        |
        |   [['id' => 1, 'name' => 'Cleo Client',
        |     'numbers' => [['label' => 'Mobile', 'number' => '+61412345678']]]]
        |
        | Null means the composer simply has no search; the number box still
        | works.
        */
        'client_search' => null,

        /*
        | Client id -> the detail a template needs. An invokable taking the
        | thread's stored client_id and returning
        |
        |   ['first_name' => 'Cleo', 'last_name' => 'Client',
        |    'name' => 'Client, Cleo (Ms)',
        |    'next_event' => ['title' => 'Annual review',
        |                     'date'  => '2026-08-24T14:30:00+08:00']]
        |
        | Merged into the `client` block of ONE opened thread (never the list),
        | so the composer can fill `{first_name}`, `{date}` and `{time}` when a
        | template is inserted. The thread's own id and name stay authoritative
        | - a hook that returns an empty name does not blank the label a human
        | typed.
        |
        | Every key is optional, and a throwing hook costs the placeholders,
        | never the conversation.
        */
        'client_details' => null,

        // Threads per page in the inbox list.
        'page_size' => 50,

        /*
        | The longest body the API accepts. 1600 is ten concatenated SMS
        | segments, which is where every carrier stops being reliable.
        */
        'max_body_length' => 1600,

        'zoom' => [
            /*
            | Null = reuse the call queue's `call_queue.api` credentials, which
            | is right whenever both features live in the same Zoom
            | Server-to-Server app (so far, always). Set an array with the same
            | keys for a messaging-specific app.
            */
            'api' => null,

            /*
            | Zoom's "Send SMS" endpoint. Configurable because it is the one
            | thing in this module that has never been run against a live
            | SMS-enabled account - see Services\Zoom\ZoomSmsClient::sendBody().
            */
            'send_path' => '/phone/sms/messages',
        ],

        // Null = the package-wide `user_model`.
        'user_model' => null,
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
    /*
    |--------------------------------------------------------------------------
    | Integrations
    |--------------------------------------------------------------------------
    |
    | The catalogue behind Settings -> Integrations. Adding an integration is a
    | config block, not a class.
    |
    | Two drivers:
    |
    |   'oauth2'  — a consent redirect and a token exchange. Delegates the flow
    |               to OAuthManager/OAuthController, which already own it.
    |   'api_key' — a set of fields somebody types in. No redirect.
    |
    | Each field may declare `env`, which is the variable it falls back to.
    | CREDENTIALS RESOLVE database -> env -> `default`, in that order: a
    | practice that already has keys in .env keeps working untouched, and a key
    | typed into the UI overrides it from then on. The reverse order would mean
    | a stale .env silently beating what the user just saved.
    |
    | `secret: true` means the value is write-only. It is stored encrypted and
    | the API reports only whether it is SET, never what it is.
    |
    | `test` is an optional callable receiving the resolved credentials and
    | returning a bool or ['success' => bool, 'message' => string]. It is what
    | the "Test connection" button runs for an api_key integration.
    |
    | Nothing ships enabled here — the consuming app declares what it uses.
    |
    */

    'integrations' => [],

    // The permission required to view or change any of the above. Integrations
    // hold the keys to every connected system, so this is deliberately its own
    // gate rather than a general "manage settings", and null disables the
    // check entirely (for an app with no permission system at all).
    'integrations_permission' => env('VISNS_INTEGRATIONS_PERMISSION', 'manage integrations'),
];
