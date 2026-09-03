<?php

namespace Visnsstudio\VisnsPackages;

use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Route;
use Visnsstudio\VisnsPackages\Commands\PublishMigrationsCommand;
use Visnsstudio\VisnsPackages\Commands\VaultPruneLogCommand;
use Visnsstudio\VisnsPackages\Commands\VaultReencryptCommand;
use Visnsstudio\VisnsPackages\Commands\VaultStripClientTitlesCommand;
use Visnsstudio\VisnsPackages\Commands\SmsPruneCommand;
use Visnsstudio\VisnsPackages\Commands\SmsSimulateInboundCommand;
use Visnsstudio\VisnsPackages\Commands\SmsSyncLinesCommand;
use Visnsstudio\VisnsPackages\Commands\InstallChromiumCommand;
use Visnsstudio\VisnsPackages\Commands\MeilisearchConfigureCommand;
use Visnsstudio\VisnsPackages\Commands\MeilisearchDebugCommand;
use Visnsstudio\VisnsPackages\Commands\MeilisearchTestCommand;
use Visnsstudio\VisnsPackages\Commands\MeilisearchSyncCommand;
use Visnsstudio\VisnsPackages\Console\Commands\UpdateModelsWithRelationshipSorting;
use Visnsstudio\VisnsPackages\Controllers\AuthController;
use Visnsstudio\VisnsPackages\Controllers\UserController;
use Visnsstudio\VisnsPackages\Controllers\FileController;
use Visnsstudio\VisnsPackages\Controllers\PermissionController;
use Visnsstudio\VisnsPackages\Controllers\RoleController;
use Visnsstudio\VisnsPackages\Controllers\SocialiteController;
use Visnsstudio\VisnsPackages\Controllers\ReportBuilderController;
use Visnsstudio\VisnsPackages\Controllers\SemanticModelController;
use Visnsstudio\VisnsPackages\Services\ReportSemantics\QueryCompiler;
use Visnsstudio\VisnsPackages\Services\ReportSemantics\SchemaInspector;
use Visnsstudio\VisnsPackages\Services\ReportSemantics\SemanticModel;
use Visnsstudio\VisnsPackages\Controllers\PDFController;
use Visnsstudio\VisnsPackages\Controllers\ProposalTemplateController;
use Visnsstudio\VisnsPackages\Controllers\BrandingProfileController;
use Visnsstudio\VisnsPackages\Controllers\IntegrationsController;
use Visnsstudio\VisnsPackages\Controllers\OAuthController;
use Visnsstudio\VisnsPackages\Middleware\AcceptJson;
use Visnsstudio\VisnsPackages\Middleware\EnsureVaultPasswordConfirmed;
use Visnsstudio\VisnsPackages\Middleware\ResolveWebAuthnRelyingParty;
use Visnsstudio\VisnsPackages\Middleware\VerifyZoomWebhookSignature;
use Visnsstudio\VisnsPackages\Controllers\PasskeyController;
use Visnsstudio\VisnsPackages\Controllers\OtpController;
use Visnsstudio\VisnsPackages\Controllers\ImpersonationController;
use Visnsstudio\VisnsPackages\Controllers\ZoomWebhookController;
use Visnsstudio\VisnsPackages\Controllers\CallQueueController;
use Visnsstudio\VisnsPackages\Controllers\CallQueueSettingsController;
use Visnsstudio\VisnsPackages\Controllers\PhonePresenceController;
use Visnsstudio\VisnsPackages\Controllers\VaultController;
use Visnsstudio\VisnsPackages\Controllers\VaultPublicShareController;
use Visnsstudio\VisnsPackages\Controllers\VaultShareController;
use Visnsstudio\VisnsPackages\Controllers\SmsController;
use Visnsstudio\VisnsPackages\Controllers\SmsLineSettingsController;
use Visnsstudio\VisnsPackages\Controllers\SmsTemplateController;
use Visnsstudio\VisnsPackages\Services\FilePathResolver;
use Visnsstudio\VisnsPackages\Services\OAuthManager;

class VisnsPackagesServiceProvider extends ServiceProvider
{
    /**
     * Register services.
     *
     * @return void
     */
    public function register()
    {
        // Register basic commands
        $commands = [
            PublishMigrationsCommand::class,
            InstallChromiumCommand::class,
            UpdateModelsWithRelationshipSorting::class,

            // Vault maintenance. Registered unconditionally: a key rotation or
            // a log prune has to be runnable on an application that has just
            // turned the module off, and neither command touches a table it was
            // not asked about.
            VaultReencryptCommand::class,
            VaultPruneLogCommand::class,
            VaultStripClientTitlesCommand::class,

            // Messaging maintenance and dev aids. Registered unconditionally
            // for the same reason: each one checks the module's own state and
            // says so plainly rather than not existing.
            SmsSimulateInboundCommand::class,
            SmsPruneCommand::class,
            SmsSyncLinesCommand::class,
        ];

        // Only register MeiliSearch commands if dependencies are available
        if ($this->meilisearchIsAvailable()) {
            $commands = array_merge($commands, [
                MeilisearchConfigureCommand::class,
                MeilisearchDebugCommand::class,
                MeilisearchTestCommand::class,
                MeilisearchSyncCommand::class,
            ]);

            // Bind MeiliSearch client
            $this->app->singleton(\MeiliSearch\Client::class, function ($app) {
                $config = config('scout.meilisearch', []);
                return new \MeiliSearch\Client(
                    $config['host'] ?? 'http://localhost:7700',
                    $config['key'] ?? null
                );
            });
        }

        $this->commands($commands);

        // Register FilePathResolver as singleton
        $this->app->singleton(FilePathResolver::class, function ($app) {
            return new FilePathResolver();
        });

        // Register OAuth Manager as singleton
        $this->app->singleton(OAuthManager::class, function ($app) {
            $manager = new OAuthManager();
            $this->registerOAuthProviders($manager);
            return $manager;
        });

        // Report semantics (report definition v2).
        //
        // Bound, not singletons: the registry is derived from config, and a
        // test - or an application that swaps the model at runtime - would
        // otherwise be stuck with the first build for the life of the
        // process. Building it is cheap (array normalisation, no I/O).
        $this->app->bind(SemanticModel::class, function ($app) {
            return SemanticModel::fromConfig();
        });

        $this->app->bind(SchemaInspector::class, function ($app) {
            return new SchemaInspector();
        });

        $this->app->bind(QueryCompiler::class, function ($app) {
            return new QueryCompiler(
                $app->make(SemanticModel::class),
                $app->make(SchemaInspector::class)
            );
        });

        // Merge config
        $this->mergeConfigFrom(
            __DIR__ . '/../config/visns-packages.php',
            'visns-packages'
        );

        // Merge OAuth providers config
        $this->mergeConfigFrom(
            __DIR__ . '/../config/oauth-providers.php',
            'oauth-providers'
        );
    }

    /**
     * Bootstrap services.
     *
     * @return void
     */
    public function boot()
    {
        // Publish migrations
        $this->publishes(
            [
                __DIR__ . '/../database/migrations' => database_path(
                    'migrations'
                ),
            ],
            'visns-packages-migrations'
        );

        // Publish seeders
        $this->publishes(
            [
                __DIR__ . '/../database/seeders' => database_path('seeders'),
            ],
            'visns-packages-seeders'
        );

        // Publish config
        $this->publishes(
            [
                __DIR__ . '/../config/visns-packages.php' => config_path(
                    'visns-packages.php'
                ),
            ],
            'visns-packages-config'
        );

        // Publish OAuth providers config
        $this->publishes(
            [
                __DIR__ . '/../config/oauth-providers.php' => config_path(
                    'oauth-providers.php'
                ),
            ],
            'oauth-providers-config'
        );

        // Blade views, under the `visns-packages::` namespace.
        //
        // The package is otherwise entirely JSON-over-HTTP: the consuming
        // applications render React behind auth and have no use for a server
        // side template. The exception is the vault's public share page, which
        // is served to somebody who has no account and must therefore not be
        // handed an SPA bundle at all - see resources/views/vault/share.blade.php.
        //
        // Published as well as loaded, so an application that wants the page in
        // its own house style can override it without forking the package.
        $this->loadViewsFrom(__DIR__ . '/../resources/views', 'visns-packages');

        $this->publishes(
            [
                __DIR__ . '/../resources/views' => resource_path(
                    'views/vendor/visns-packages'
                ),
            ],
            'visns-packages-views'
        );

        // Register middleware
        $router = $this->app['router'];

        // `accept-json` predates this package having a convention about aliases
        // and has always been registered unconditionally; leaving it that way
        // rather than quietly changing which class an existing consumer's
        // routes resolve to.
        $router->aliasMiddleware('accept-json', AcceptJson::class);

        // Everything below only fills a name in when the application has not
        // already claimed it. A package that overwrites an application's alias
        // silently changes what every route carrying that name actually does -
        // and because providers boot after the application's own middleware
        // registration, the package would always win.
        //
        // Both spellings of the Zoom alias: applications in the wild use the
        // underscore form, so route definitions can move across untouched.
        $this->aliasMiddlewareUnlessClaimed(
            $router,
            'zoom-webhook',
            VerifyZoomWebhookSignature::class
        );
        $this->aliasMiddlewareUnlessClaimed(
            $router,
            'zoom_webhook',
            VerifyZoomWebhookSignature::class
        );

        // The vault's "confirm your password again" gate. Named rather than
        // referenced by class so an application can point the name at its own
        // stricter implementation.
        $this->aliasMiddlewareUnlessClaimed(
            $router,
            'vault.confirmed',
            EnsureVaultPasswordConfirmed::class
        );

        // The passkey routes bind the WebAuthn ceremony to the host being
        // browsed. Registered whether or not the module is enabled, so an
        // application can carry its own passkey routes on the same name.
        $this->aliasMiddlewareUnlessClaimed(
            $router,
            'webauthn.rp',
            ResolveWebAuthnRelyingParty::class
        );

        // The opt-in modules gate their routes with `permission:...`, an alias
        // normally declared in the application's own bootstrap.
        if (class_exists(\Spatie\Permission\Middleware\PermissionMiddleware::class)) {
            $this->aliasMiddlewareUnlessClaimed(
                $router,
                'permission',
                \Spatie\Permission\Middleware\PermissionMiddleware::class
            );
        }

        // Register routes
        $this->registerRoutes();

        // Teach the credential model about the column this package's own
        // migration adds to it...
        $this->registerPasskeyCasts();

        // ... and stamp the credential a passkey sign-in used.
        $this->registerPasskeyListeners();

        // Broadcast channel authorization for the call queue pop.
        $this->registerCallQueueChannel();

        // ... and for the per-line messaging channels.
        $this->registerMessagingChannel();
    }

    /**
     * Register a middleware alias, unless the application already defines one
     * under that name.
     *
     * @param  \Illuminate\Routing\Router  $router
     * @return void
     */
    protected function aliasMiddlewareUnlessClaimed(
        $router,
        string $name,
        string $class
    ): void {
        if (array_key_exists($name, $router->getMiddleware())) {
            return;
        }

        $router->aliasMiddleware($name, $class);
    }

    /**
     * Register the package routes.
     *
     * @return void
     */
    protected function registerRoutes()
    {
        // Only register routes if enabled in config
        // Allow routes to be registered when running route:list command
        $runningInConsole = $this->app->runningInConsole();
        $runningRouteList =
            $runningInConsole &&
            isset($_SERVER['argv']) &&
            is_array($_SERVER['argv']) &&
            count($_SERVER['argv']) > 1 &&
            in_array($_SERVER['argv'][1], ['route:list', 'route:cache']);

        // A test suite also runs in the console, and skipping registration
        // there left every package endpoint answering 405 to a feature test -
        // so a consuming application could not test its own report builder,
        // authentication or file routes at all.
        $runningTests = $this->app->runningUnitTests();

        if (
            config('visns-packages.register_routes', true) &&
            (!$runningInConsole || $runningRouteList || $runningTests)
        ) {
            // Get middleware from config
            $middleware = config('visns-packages.routes_middleware', ['web']);

            // Get prefix from config
            $prefix = config('visns-packages.routes_prefix', '');

            Route::middleware($middleware)
                ->prefix($prefix)
                ->group(function () {
                    // Auth routes
                    Route::controller(AuthController::class)->group(
                        function () {
                            // Login and password management
                            Route::prefix('login')->group(function () {
                                Route::post('/authenticate', 'authenticate');
                                Route::post(
                                    '/two-factor-challenge',
                                    'twoFactorAuthenticate'
                                );

                                // Re-send the code for the code-channel 2FA
                                // driver. Inert under the default TOTP driver,
                                // which has no code to re-send.
                                Route::post(
                                    '/two-factor-resend',
                                    'twoFactorResend'
                                );
                            });

                            Route::prefix('password')->group(function () {
                                Route::post('/forgot', 'forgot');
                                Route::post('/reset', 'reset');
                            });

                            Route::get('/logout', 'logout');
                        }
                    );

                    // User-related AJAX routes
                    Route::prefix('ajax/user')->group(function () {
                        // User profile and notifications
                        Route::controller(UserController::class)->group(
                            function () {
                                Route::post(
                                    'notifications/table',
                                    'notificationTable'
                                );
                                Route::post('notifications', 'notifications');
                                Route::post(
                                    'notification/markasread',
                                    'markAsRead'
                                );
                                Route::get('profile', 'profile');
                            }
                        );

                        // Two-factor auth routes
                        Route::controller(UserController::class)
                            ->prefix('two-factor-auth')
                            ->group(function () {
                                Route::post('/enable', 'enableTwoFactorAuth');
                                Route::post('/confirm', 'confirmTwoFactorAuth');
                                Route::post('/disable', 'disableTwoFactorAuth');
                                Route::post(
                                    '/recovery-codes',
                                    'regenerateRecoveryCodes'
                                );
                                Route::get('/', 'getTwoFactorStatus');
                            });
                    });

                    // File-related AJAX routes
                    Route::prefix('ajax/files')
                        ->controller(FileController::class)
                        ->group(function () {
                            Route::get('{id}', 'show');
                            Route::put('{id}', 'update');
                            Route::post('delete', 'delete');
                            Route::get('download/{id}/{folder?}', 'download');
                            Route::get(
                                'downloadContent/{id}',
                                'downloadContent'
                            );
                            Route::post('sort_update', 'sort_update');
                            Route::post(
                                'downloadByPath',
                                'downloadByPath'
                            );
                        });

                    // Permission routes
                    Route::prefix('ajax/permissions')
                        ->controller(PermissionController::class)
                        ->group(function () {
                            Route::get('/', 'index');
                            Route::post('/', 'store');
                            Route::get('/{id}', 'show');
                            Route::put('/{id}', 'update');
                            Route::delete('/{id}', 'destroy');
                            Route::post('/table', 'table');
                            Route::post('/dropdown', 'dropdown');
                        });

                    // Role routes
                    Route::prefix('ajax/roles')
                        ->controller(RoleController::class)
                        ->group(function () {
                            Route::get('/', 'index');
                            Route::post('/', 'store');
                            Route::get('/{id}', 'show');
                            Route::put('/{id}', 'update');
                            Route::delete('/{id}', 'destroy');
                            Route::post('/table', 'table');
                            Route::post('/dropdown', 'dropdown');
                        });

                    // Socialite web routes
                    Route::controller(SocialiteController::class)->group(
                        function () {
                            Route::get(
                                '/auth/{provider}',
                                'redirectToProvider'
                            ); // Route to redirect the user to provider for authentication
                            Route::get(
                                '/auth/{provider}/callback',
                                'handleProviderCallback'
                            ); // Route to handle the callback from provider after authentication
                        }
                    );

                    // PDF Generation routes
                    Route::prefix('ajax/pdf')
                        ->controller(PDFController::class)
                        ->group(function () {
                            Route::post('/generate', 'generatePDF');
                            Route::post(
                                '/generate-from-html',
                                'generatePDFFromHTML'
                            );
                            Route::post(
                                '/generate-custom',
                                'generateCustomPDF'
                            );
                            Route::post('/generate-quote', 'generateQuotePDF');

                            // Proposal PDF generation routes (backward compatible)
                            Route::post(
                                '/generate-proposal',
                                'generateProposalPDF'
                            );
                            Route::post(
                                '/generate-proposal-spatie',
                                'generateProposalPDFSpatie'
                            );
                            Route::post(
                                '/preview-proposal',
                                'previewProposalPDF'
                            );
                            Route::post(
                                '/generate-proposal-html',
                                'generateProposalHTML'
                            );
                        });

                    // OAuth Integration routes (non-conflicting with Socialite)
                    Route::prefix('integrations/oauth')
                        ->controller(OAuthController::class)
                        ->group(function () {
                            // Public OAuth routes
                            Route::get('{provider}/authorize', 'redirectToProvider')
                                ->name('oauth.authorize');
                            Route::get('{provider}/callback', 'callback')
                                ->name('oauth.callback');
                            
                            // Protected OAuth API routes (use web auth instead of sanctum)
                            Route::middleware('auth')->group(function () {
                                Route::get('providers', 'providers')
                                    ->name('oauth.providers');
                                Route::get('{provider}/status', 'status')
                                    ->name('oauth.status');
                                Route::post('{provider}/test', 'test')
                                    ->name('oauth.test');
                                Route::post('{provider}/preview', 'preview')
                                    ->name('oauth.preview');
                                Route::post('{provider}/disconnect', 'disconnect')
                                    ->name('oauth.disconnect');
                                Route::post('{provider}/sync', 'sync')
                                    ->name('oauth.sync');
                            });
                        });

                    Route::controller(AuthController::class)->group(
                        function () {
                            Route::post('/login', 'login_api');
                            Route::post('/register', 'register');
                            Route::post(
                                '/two-factor-challenge',
                                'twoFactorAuthenticateApi'
                            );
                            Route::post('/logout', 'logout_api');
                        }
                    );
                });

            // Report Builder routes
            //
            // Registered in their own group: these endpoints expose the
            // database schema and execute SELECT queries built from the
            // request payload, so they always require authentication and
            // never inherit the (potentially unauthenticated)
            // `routes_middleware` stack used by the routes above.
            $reportBuilderMiddleware = config(
                'visns-packages.report_builder_middleware',
                ['web', 'auth']
            );

            Route::middleware($reportBuilderMiddleware)
                ->prefix($prefix)
                ->group(function () {
                    Route::prefix('ajax')
                        ->controller(ReportBuilderController::class)
                        ->group(function () {
                            Route::post(
                                '/reportBuilder/getTables',
                                'getTables'
                            );
                            Route::post(
                                '/reportBuilder/getTableColumns',
                                'getTableColumns'
                            );
                            Route::post(
                                '/reportBuilder/getAllTablesAndColumns',
                                'getAllTablesAndColumns'
                            );
                            Route::post(
                                '/reportBuilder/getTableRelationships',
                                'getTableRelationships'
                            );
                            Route::post(
                                '/reportBuilder/getSuggestedJoins',
                                'getSuggestedJoins'
                            );
                            Route::post(
                                '/reportBuilder/getColumnTypeInfo',
                                'getColumnTypeInfo'
                            );

                            // Report management endpoints
                            Route::get('/reportBuilder/reports', 'getReports');
                            Route::get(
                                '/reportBuilder/reports/{id}',
                                'getReport'
                            );
                            Route::post('/reportBuilder/reports', 'saveReport');
                            Route::put(
                                '/reportBuilder/reports/{id}',
                                'updateReport'
                            );
                            Route::delete(
                                '/reportBuilder/reports/{id}',
                                'deleteReport'
                            );

                            // Execute report query
                            Route::post(
                                '/reportBuilder/execute',
                                'executeQuery'
                            );

                            // Export report
                            Route::post(
                                '/reportBuilder/export',
                                'exportReport'
                            );

                            // Get JSON field keys
                            Route::post(
                                '/reportBuilder/getJsonFieldKeys',
                                'getJsonFieldKeys'
                            );
                        });

                    // Semantic model (report definition v2). Registered in
                    // the same group as the rest of the builder: it
                    // describes the reportable shape of the database and is
                    // never public.
                    Route::prefix('ajax')
                        ->controller(SemanticModelController::class)
                        ->group(function () {
                            Route::post(
                                '/reportBuilder/semanticModel',
                                'semanticModel'
                            );
                        });
                });

            // Register API routes
            $apiMiddleware = config('visns-packages.api_middleware', [
                'api',
                'accept-json',
            ]);
            $apiPrefix = config('visns-packages.api_prefix', 'api');

            Route::middleware($apiMiddleware)
                ->prefix($apiPrefix)
                ->group(function () {
                    // Auth API routes
                    Route::controller(AuthController::class)->group(
                        function () {
                            Route::post('/login', 'login_api');
                            Route::post('/register', 'register');
                            Route::post(
                                '/two-factor-challenge',
                                'twoFactorAuthenticateApi'
                            );
                            Route::post('/logout', 'logout_api');
                        }
                    );

                    // User API routes
                    Route::controller(UserController::class)->group(
                        function () {
                            Route::middleware('auth:sanctum')->get(
                                '/profile',
                                'profile'
                            );
                        }
                    );

                    // Socialite API routes
                    Route::controller(SocialiteController::class)->group(
                        function () {
                            Route::get('/auth/providers', 'getProviders'); // Get available OAuth providers
                            Route::middleware('auth:sanctum')->get(
                                '/auth/status',
                                'getAuthStatus'
                            ); // Check authentication status
                        }
                    );

                    // PDF API routes
                    Route::prefix('pdf')
                        ->controller(PDFController::class)
                        ->middleware('auth:sanctum')
                        ->group(function () {
                            Route::post('/generate', 'generatePDF');
                            Route::post(
                                '/generate-from-html',
                                'generatePDFFromHTML'
                            );
                            Route::post(
                                '/generate-custom',
                                'generateCustomPDF'
                            );
                            Route::post('/generate-quote', 'generateQuotePDF');

                            // Proposal PDF generation API routes (backward compatible)
                            Route::post(
                                '/generate-proposal',
                                'generateProposalPDF'
                            );
                            Route::post(
                                '/generate-proposal-spatie',
                                'generateProposalPDFSpatie'
                            );
                            Route::post(
                                '/preview-proposal',
                                'previewProposalPDF'
                            );
                            Route::post(
                                '/generate-proposal-html',
                                'generateProposalHTML'
                            );
                        });
                });

            // Opt-in modules. Each ships disabled, so an existing consumer sees
            // no new endpoints on upgrade.
            $this->registerOtpRoutes($apiMiddleware, $apiPrefix);
            $this->registerPasskeyRoutes($middleware, $prefix);
            $this->registerImpersonationRoutes(
                $middleware,
                $prefix,
                $apiMiddleware,
                $apiPrefix
            );
            $this->registerCallQueueRoutes($middleware, $prefix);
            $this->registerVaultRoutes($middleware, $prefix);
            $this->registerUniversalSearchRoutes($middleware, $prefix);
            $this->registerIntegrationRoutes($middleware, $prefix);
            $this->registerMessagingRoutes($middleware, $prefix);

            // Register dynamic entity routes first (they will be more general)
            $this->registerDynamicEntityRoutes($middleware);

            // Register custom routes after (they will override/supplement dynamic routes)
            $this->registerCustomEntityRoutes($middleware);
        }
    }

    /**
     * Passkeys (WebAuthn).
     *
     * Two unauthenticated endpoints, so the module is off until an application
     * says otherwise. They sit under the package's web prefix, which with a
     * stock config puts the sign-in pair at /login/passkey/options and
     * /login/passkey - the two paths @visns-studio/visns-components' Login
     * screen posts to, and therefore not free to move.
     *
     * The guest pair is throttled: they are the one unauthenticated route pair
     * in this module that can hand out a session. The management set is behind
     * `auth` because enrolment is a signed-in act by design - a passkey is
     * added to an account that already proved itself, never used to claim one.
     *
     * @return void
     */
    protected function registerPasskeyRoutes(array $middleware, string $prefix)
    {
        if (!PasskeyController::isEnabled()) {
            return;
        }

        $uris = (array) config('visns-packages.passkeys.uris', []);

        $guestMiddleware = config('visns-packages.passkeys.guest_middleware');

        if (!is_array($guestMiddleware)) {
            $guestMiddleware = array_merge($middleware, [
                'guest',
                'webauthn.rp',
                'throttle:20,1',
            ]);
        }

        $authMiddleware = config('visns-packages.passkeys.auth_middleware');

        if (!is_array($authMiddleware)) {
            $authMiddleware = array_merge($middleware, ['auth', 'webauthn.rp']);
        }

        Route::middleware($guestMiddleware)
            ->prefix($prefix)
            ->controller(PasskeyController::class)
            ->group(function () use ($uris) {
                Route::post(
                    $uris['login_options'] ?? 'login/passkey/options',
                    'loginOptions'
                );
                Route::post($uris['login'] ?? 'login/passkey', 'login');
            });

        Route::middleware($authMiddleware)
            ->prefix($prefix)
            ->controller(PasskeyController::class)
            ->group(function () use ($uris) {
                Route::get($uris['index'] ?? 'ajax/passkeys', 'index');
                Route::post(
                    $uris['register_options'] ?? 'ajax/passkeys/options',
                    'registerOptions'
                );
                Route::post(
                    $uris['register'] ?? 'ajax/passkeys/register',
                    'register'
                );
                Route::delete(
                    $uris['destroy'] ?? 'ajax/passkeys/{id}',
                    'destroy'
                );
            });
    }

    /**
     * Cast `last_used_at` on laragear's credential model.
     *
     * The column is this package's, added by the publishable
     * create_webauthn_credentials_table migration; the library's model knows
     * nothing about it, so without this it comes back from the database as a
     * raw string and PasskeyController::present()'s `optional(...)
     * ->toIso8601String()` silently answers null on every credential that has
     * ever been used. `customize()` is the library's own hook for exactly
     * this.
     *
     * Deliberately NOT gated on the module being enabled, unlike the routes
     * and the listener. Those two publish surface an application did not ask
     * for; a cast on a column the package's own migration adds is inert until
     * something reads the column - and an application that has the table and
     * reads it with the module switched off still wants a date rather than a
     * string.
     *
     * One thing to know before adding a second caller: `customize()` holds ONE
     * closure and replaces it, it does not stack. An application that calls it
     * itself - to point the model at a differently named table, say - takes
     * this cast away with it, and has to restate the merge inside its own
     * callback.
     *
     * @return void
     */
    protected function registerPasskeyCasts(): void
    {
        if (!class_exists(\Laragear\WebAuthn\Models\WebAuthnCredential::class)) {
            return;
        }

        \Laragear\WebAuthn\Models\WebAuthnCredential::customize(
            static function (
                \Laragear\WebAuthn\Models\WebAuthnCredential $credential
            ): void {
                $credential->mergeCasts(['last_used_at' => 'datetime']);
            }
        );
    }

    /**
     * Stamp `last_used_at` on the credential a sign-in just used.
     *
     * The management screen shows this so a person can tell a key they still
     * carry from one on a laptop they handed back two years ago - and so an
     * unused credential is recognisable as a candidate for removal.
     *
     * A listener rather than controller code because the assertion is verified
     * inside the auth provider, several layers below the controller, and this
     * must also cover any future caller of it. Only wired up while the module
     * is enabled, so the library's event carries no cost otherwise.
     *
     * @return void
     */
    protected function registerPasskeyListeners(): void
    {
        if (
            !PasskeyController::isEnabled() ||
            !class_exists(\Laragear\WebAuthn\Events\CredentialAsserted::class)
        ) {
            return;
        }

        Event::listen(
            \Laragear\WebAuthn\Events\CredentialAsserted::class,
            static function (
                \Laragear\WebAuthn\Events\CredentialAsserted $event
            ): void {
                // No touch(): `updated_at` on a credential means "the record
                // changed", and a bare update keeps a read out of any audit
                // trail hanging off the model.
                $event->credential
                    ->newQuery()
                    ->whereKey($event->credential->getKey())
                    ->update(['last_used_at' => now()]);
            }
        );
    }

    /**
     * Passwordless OTP login.
     *
     * Two unauthenticated endpoints, so the module is off until an application
     * says otherwise. They sit under the package's API prefix, which puts the
     * defaults at /api/auth/request-otp and /api/auth/login-otp.
     *
     * @return void
     */
    protected function registerOtpRoutes(array $apiMiddleware, string $apiPrefix)
    {
        if (!config('visns-packages.otp.enabled', false)) {
            return;
        }

        $middleware =
            config('visns-packages.otp.middleware') ?: $apiMiddleware;

        $uris = (array) config('visns-packages.otp.uris', []);

        Route::middleware($middleware)
            ->prefix($apiPrefix)
            ->group(function () use ($uris) {
                Route::post(
                    $uris['request'] ?? 'auth/request-otp',
                    [OtpController::class, 'requestOtp']
                );

                Route::post($uris['login'] ?? 'auth/login-otp', [
                    OtpController::class,
                    'loginOtp',
                ]);
            });
    }

    /**
     * Staff impersonation.
     *
     * The two halves live on opposite sides of a trust boundary and are
     * registered separately because of it: issuing runs inside the CRM behind a
     * session and a permission, while validation is called by the portal with
     * nothing but the token, so it goes in the API group and answers with a
     * whitelisted payload only.
     *
     * @return void
     */
    protected function registerImpersonationRoutes(
        array $middleware,
        string $prefix,
        array $apiMiddleware,
        string $apiPrefix
    ) {
        if (!config('visns-packages.impersonation.enabled', false)) {
            return;
        }

        $uris = (array) config('visns-packages.impersonation.uris', []);

        $issueMiddleware = config(
            'visns-packages.impersonation.issue_middleware'
        );

        if (!is_array($issueMiddleware)) {
            $issueMiddleware = array_merge($middleware, ['auth']);

            // Impersonation grants a live session as somebody else, so it is a
            // higher privilege than ordinary client editing and gets its own
            // permission rather than riding on an existing one. Set the
            // permission to null to gate it some other way.
            $permission = config(
                'visns-packages.impersonation.permission',
                'Impersonate Client'
            );

            if (is_string($permission) && $permission !== '') {
                $issueMiddleware[] = 'permission:' . $permission;
            }
        }

        Route::middleware($issueMiddleware)
            ->prefix($prefix)
            ->group(function () use ($uris) {
                Route::post(
                    $uris['issue'] ?? 'ajax/impersonateClient',
                    [ImpersonationController::class, 'issue']
                );
            });

        $validateMiddleware =
            config('visns-packages.impersonation.validate_middleware') ?:
            $apiMiddleware;

        Route::middleware($validateMiddleware)
            ->prefix($apiPrefix)
            ->group(function () use ($uris) {
                Route::post(
                    $uris['validate'] ?? 'validateImpersonationToken',
                    [ImpersonationController::class, 'validateToken']
                );
            });
    }

    /**
     * Zoom Phone call queue pop.
     *
     * The webhook is registered on its own, outside every other group: its URI
     * is whatever the Zoom app is pointed at (absolute, no package prefix), and
     * the only thing guarding it is the signature middleware - session
     * middleware on a machine-to-machine callback would only get in the way.
     *
     * @return void
     */
    protected function registerCallQueueRoutes(array $middleware, string $prefix)
    {
        if (!config('visns-packages.call_queue.enabled', false)) {
            return;
        }

        $uris = (array) config('visns-packages.call_queue.uris', []);
        $permissions = (array) config(
            'visns-packages.call_queue.permissions',
            []
        );

        $webhookMiddleware = array_merge(
            (array) config('visns-packages.call_queue.webhook_middleware', []),
            [VerifyZoomWebhookSignature::class]
        );

        Route::middleware($webhookMiddleware)->post(
            $uris['webhook'] ?? 'api/zoom/webhook',
            [ZoomWebhookController::class, 'handle']
        );

        $routeMiddleware =
            config('visns-packages.call_queue.routes_middleware') ?: $middleware;

        /*
        | array_key_exists, not ??, because null here is a DECISION: it is how
        | an application says "this one is open to any signed-in user", and
        | withPermission() (plus the channel registration) reads it that way.
        | `??` would have quietly swapped that null back for the default and
        | left the route gated on a permission the config file plainly says it
        | does not want — the sort of bug that only shows up as "why can nobody
        | see the pop".
        */
        $monitor = array_key_exists('monitor', $permissions)
            ? $permissions['monitor']
            : 'Call Queue Monitor';
        $settings = array_key_exists('settings', $permissions)
            ? $permissions['settings']
            : 'Call Queue Settings';

        Route::middleware(
            $this->withPermission($routeMiddleware, $monitor)
        )
            ->prefix($prefix)
            ->group(function () use ($uris) {
                Route::get($uris['live'] ?? 'ajax/call-queue/live', [
                    CallQueueController::class,
                    'live',
                ]);
            });

        Route::middleware(
            $this->withPermission($routeMiddleware, $settings)
        )
            ->prefix($prefix)
            ->group(function () use ($uris) {
                $settingsUri = $uris['settings'] ?? 'ajax/call-queue/settings';

                Route::get($settingsUri, [
                    CallQueueSettingsController::class,
                    'index',
                ]);

                Route::put($settingsUri . '/{queueId}', [
                    CallQueueSettingsController::class,
                    'update',
                ]);

                /*
                | Diagnostics: the webhook ledger, the broadcast configuration,
                | and a test broadcast on the pop's own channel.
                |
                | Gated on the settings permission rather than the monitor's.
                | It names the broadcast target, the channel and the excluded
                | queues — administrator material, even though no credential
                | appears in the response — and the ping puts an event on the
                | live channel, which is not something every watcher of the pop
                | should be able to do.
                */
                $diagnosticsUri =
                    $uris['diagnostics'] ?? 'ajax/call-queue/diagnostics';

                Route::get($diagnosticsUri, [
                    CallQueueController::class,
                    'diagnostics',
                ]);

                Route::post($diagnosticsUri . '/ping', [
                    CallQueueController::class,
                    'ping',
                ]);
            });

        /*
        | The Zoom Phone roster.
        |
        | Registered only when `presence.enabled` is on, so a deployment running
        | the call queue alone does not grow an endpoint that would answer with
        | an empty list and a "not connected" flag.
        |
        | The permission defaults to the queue monitor's. Anyone who may watch
        | the pop already sees a caller's number and the client it resolves to,
        | which is exactly what the roster shows — a separate permission would
        | mean a new row assigned to nobody, and a header icon that renders for
        | no one until an administrator goes and ticks it. `presence.permission`
        | is there for a deployment that wants that anyway.
        */
        if (config('visns-packages.call_queue.presence.enabled', false)) {
            $presence = config(
                'visns-packages.call_queue.presence.permission'
            ) ?: $monitor;

            Route::middleware(
                $this->withPermission($routeMiddleware, $presence)
            )
                ->prefix($prefix)
                ->group(function () use ($uris) {
                    Route::get(
                        $uris['presence'] ?? 'ajax/call-queue/presence',
                        [PhonePresenceController::class, 'index']
                    );
                });
        }
    }


    /**
     * Vault: staff password manager.
     *
     * Everything hangs off one configurable base (`ajax/vault` by default), and
     * everything carries the access permission. Three details in here are load
     * bearing:
     *
     *  - `{id}` is constrained to digits. Without that, `GET {base}/log` is
     *    swallowed by `GET {base}/{id}` and the administrator's log endpoint
     *    quietly becomes "show me the entry called log", 404ing forever.
     *
     *  - The literal routes are registered BEFORE the `{id}` ones anyway. The
     *    constraint alone is enough, but relying on a single guard for a routing
     *    collision that fails silently is not worth the saving.
     *
     *  - Reveal is the only route carrying `vault.confirmed`. The access
     *    permission gets a session as far as titles and usernames; getting a
     *    plaintext password out needs the user to prove, again and recently,
     *    that they are the person the session belongs to.
     *
     * @return void
     */
    /**
     * One search endpoint for the whole application.
     *
     * No permission is required to reach it: the controller filters SOURCES by
     * permission instead, so a user always gets a working search box that
     * covers exactly what they may see — rather than a 403 on the box itself.
     */
    protected function registerUniversalSearchRoutes(array $middleware, string $prefix)
    {
        if (!config('visns-packages.universal_search.enabled', true)) {
            return;
        }

        $base = trim(
            (string) config('visns-packages.universal_search.uris.base', 'ajax/search'),
            '/'
        ) ?: 'ajax/search';

        $routeMiddleware =
            config('visns-packages.universal_search.routes_middleware') ?: $middleware;

        \Illuminate\Support\Facades\Route::middleware($routeMiddleware)
            ->prefix($prefix)
            ->group(function () use ($base) {
                \Illuminate\Support\Facades\Route::get(
                    $base,
                    \Visnsstudio\VisnsPackages\Controllers\UniversalSearchController::class
                )->name('visns.universal-search');
            });
    }

    /**
     * Settings -> Integrations.
     *
     * Credential entry and status for both drivers. The OAuth redirect and
     * callback are NOT here: `integrations/oauth/{provider}/authorize` and
     * `/callback` already exist on OAuthController and are browser
     * navigations, where these are all fetch calls from the settings page.
     *
     * Nothing registers unless the app has declared at least one integration,
     * so a project that does not use this pays nothing for it.
     */
    protected function registerIntegrationRoutes(array $middleware, string $prefix)
    {
        if (!config('visns-packages.integrations')) {
            return;
        }

        // `ajax/integrations`, NOT bare `integrations`. The latter is where
        // OAuthController already lives (`integrations/oauth/...`), so an
        // `integrations/{provider}` wildcard alongside it is asking for one to
        // swallow the other. `$prefix` is the app-wide outer prefix and is
        // usually empty; the base is what actually names the module.
        $base = trim(
            (string) config('visns-packages.integrations_uri', 'ajax/integrations'),
            '/'
        ) ?: 'ajax/integrations';

        Route::middleware($middleware)
            ->prefix($prefix)
            ->group(function () use ($base) {
                Route::prefix($base)
                    ->controller(IntegrationsController::class)
                    ->group(function () {
                        Route::get('/', 'index')->name('visns.integrations.index');
                        Route::post('/{provider}/test', 'test')
                            ->name('visns.integrations.test');
                        // Returns the consent URL as JSON rather than
                        // redirecting: a 302 to a third-party host fails CORS
                        // from a fetch, where a URL the page can assign to
                        // window.location does not.
                        Route::get('/{provider}/authorize-url', 'authorizeUrl')
                            ->name('visns.integrations.authorize');
                        // The wildcards go LAST, or `{provider}` matches
                        // `zoho/test` before the specific routes above get a
                        // chance.
                        Route::get('/{provider}', 'show')
                            ->name('visns.integrations.show');
                        Route::put('/{provider}', 'update')
                            ->name('visns.integrations.update');
                        Route::delete('/{provider}', 'destroy')
                            ->name('visns.integrations.destroy');
                    });
            });
    }

    protected function registerVaultRoutes(array $middleware, string $prefix)
    {
        if (!config('visns-packages.vault.enabled', false)) {
            return;
        }

        $base = trim(
            (string) config('visns-packages.vault.uris.base', 'ajax/vault'),
            '/'
        );

        if ($base === '') {
            $base = 'ajax/vault';
        }

        $routeMiddleware =
            config('visns-packages.vault.routes_middleware') ?: $middleware;

        $access = config(
            'visns-packages.vault.permissions.access',
            'Vault Access'
        );


        $accessMiddleware = $this->withPermission($routeMiddleware, $access);

        $reveal = $this->withThrottle(
            $accessMiddleware,
            config('visns-packages.vault.throttle.reveal')
        );

        $confirm = $this->withThrottle(
            $accessMiddleware,
            config('visns-packages.vault.throttle.confirm')
        );

        Route::middleware($accessMiddleware)
            ->prefix($prefix)
            ->group(function () use ($base, $reveal, $confirm) {
                // Literal segments first; see the docblock.
                Route::get($base . '/log', [VaultController::class, 'logIndex']);

                        // Typeahead for the client picker. Inert when no
                        // client model is configured.
                        Route::get($base . '/clients', [VaultController::class, 'clients'])
                            ->name('visns.vault.clients');

                        // The clients that actually HAVE entries, for the
                        // list's client filter. Registered before `/{id}` for
                        // the same reason as everything else in this block:
                        // a literal segment must not be eaten by the
                        // parameter route.
                        Route::get($base . '/entry-clients', [
                            VaultController::class,
                            'entryClients',
                        ])->name('visns.vault.entry-clients');

                Route::middleware($confirm)->post(
                    $base . '/confirm-password',
                    [VaultController::class, 'confirmPassword']
                );

                Route::get($base, [VaultController::class, 'index']);
                Route::post($base, [VaultController::class, 'store']);

                Route::get($base . '/{id}', [VaultController::class, 'show'])
                    ->whereNumber('id');
                Route::put($base . '/{id}', [VaultController::class, 'update'])
                    ->whereNumber('id');
                Route::delete($base . '/{id}', [VaultController::class, 'destroy'])
                    ->whereNumber('id');

                Route::post($base . '/{id}/restore', [
                    VaultController::class,
                    'restore',
                ])->whereNumber('id');

                Route::post($base . '/{id}/log', [VaultController::class, 'log'])
                    ->whereNumber('id');
                Route::get($base . '/{id}/log', [
                    VaultController::class,
                    'entryLog',
                ])->whereNumber('id');

                // The two that hand out secrets.
                Route::middleware(
                    array_merge($reveal, ['vault.confirmed'])
                )->post($base . '/{id}/reveal', [
                    VaultController::class,
                    'reveal',
                ])->whereNumber('id');

                Route::middleware($reveal)->get($base . '/{id}/otp', [
                    VaultController::class,
                    'otp',
                ])->whereNumber('id');
            });

        $this->registerVaultShareRoutes($accessMiddleware, $prefix, $base);
    }

    /**
     * Vault share links: the staff endpoints, and the one public page.
     *
     * TWO GROUPS, AND THE SPLIT IS THE POINT.
     *
     * The `ajax/vault/{id}/shares` endpoints sit inside the vault's own
     * middleware - session, auth, the access permission - because creating a
     * link that hands a credential to somebody outside the CRM must not be
     * reachable from a session that could not read the credential itself.
     *
     * The public page sits OUTSIDE all of that, which is the whole feature: the
     * recipient has no account. It carries `web` (it needs a session for the
     * CSRF token on the reveal form and nothing else), a throttle keyed by IP,
     * and no permission of any kind. `{token}` is constrained to hex so that a
     * request carrying anything else is refused by the router rather than
     * reaching the database.
     *
     * The reveal is a POST on the SAME path as the page. A preview bot fetching
     * the URL - which Slack, Teams and WhatsApp all do the moment it is pasted -
     * gets the inert page and cannot spend a view.
     *
     * @return void
     */
    protected function registerVaultShareRoutes(
        array $accessMiddleware,
        string $prefix,
        string $base
    ) {
        if (!config('visns-packages.vault.share.enabled', true)) {
            return;
        }

        $create = $this->withThrottle(
            $accessMiddleware,
            config('visns-packages.vault.share.throttle.create')
        );

        Route::middleware($accessMiddleware)
            ->prefix($prefix)
            ->group(function () use ($base, $create) {
                Route::get($base . '/{id}/shares', [
                    VaultShareController::class,
                    'index',
                ])->whereNumber('id')->name('visns.vault.shares.index');

                Route::middleware($create)->post($base . '/{id}/shares', [
                    VaultShareController::class,
                    'store',
                ])->whereNumber('id')->name('visns.vault.shares.store');

                Route::delete($base . '/{id}/shares/{share}', [
                    VaultShareController::class,
                    'destroy',
                ])->whereNumber('id')->whereNumber('share')
                    ->name('visns.vault.shares.destroy');
            });

        $publicBase = trim(
            (string) config('visns-packages.vault.share.uris.public', 'vault/share'),
            '/'
        ) ?: 'vault/share';

        // NOT $prefix: that prefix names the application's ajax surface, and
        // this is a URL a person is going to be sent in a message.
        $publicMiddleware =
            config('visns-packages.vault.share.routes_middleware') ?: ['web'];

        Route::middleware(
            $this->withThrottle(
                (array) $publicMiddleware,
                config('visns-packages.vault.share.throttle.view')
            )
        )->get($publicBase . '/{token}', [
            VaultPublicShareController::class,
            'show',
        ])->where('token', '[A-Fa-f0-9]{40,128}')
            ->name('visns.vault.share.show');

        // Tighter than the GET: this one spends a view and decrypts a secret,
        // where the GET is a static page.
        Route::middleware(
            $this->withThrottle(
                (array) $publicMiddleware,
                config('visns-packages.vault.share.throttle.reveal')
            )
        )->post($publicBase . '/{token}', [
            VaultPublicShareController::class,
            'reveal',
        ])->where('token', '[A-Fa-f0-9]{40,128}')
            ->name('visns.vault.share.reveal');
    }

    /**
     * Messaging: the SMS inbox.
     *
     * Everything hangs off one configurable base (`ajax/sms` by default) and
     * carries the access permission; the administrative endpoints check `manage`
     * inside the controller rather than in middleware, so that a user without it
     * gets a 403 on the settings call and still keeps a working inbox.
     *
     * Two details are load bearing:
     *
     *  - `{id}` is constrained to digits, and the literal routes are registered
     *    first anyway. Without that, `GET {base}/threads` would be swallowed by
     *    nothing here today - but `GET {base}/templates` and a future
     *    `{base}/{id}` would collide silently, which is the failure mode worth
     *    spending two lines to prevent.
     *
     *  - The webhook is NOT registered here. Zoom subscribes one URL per
     *    marketplace app, so SMS events arrive on the call queue's existing
     *    endpoint (ZoomWebhookController) already carrying its signature.
     *    Messaging can therefore be enabled with no change to the Zoom app
     *    beyond ticking the three phone.sms_* subscriptions.
     *
     * @return void
     */
    protected function registerMessagingRoutes(array $middleware, string $prefix)
    {
        if (!config('visns-packages.messaging.enabled', false)) {
            return;
        }

        $base = trim(
            (string) config('visns-packages.messaging.uris.base', 'ajax/sms'),
            '/'
        );

        if ($base === '') {
            $base = 'ajax/sms';
        }

        $routeMiddleware =
            config('visns-packages.messaging.routes_middleware') ?: $middleware;

        $access = config(
            'visns-packages.messaging.permissions.access',
            'Messaging Access'
        );

        $accessMiddleware = $this->withPermission($routeMiddleware, $access);

        Route::middleware($accessMiddleware)
            ->prefix($prefix)
            ->group(function () use ($base) {
                Route::get($base . '/status', [SmsController::class, 'status']);
                Route::get($base . '/lines', [SmsController::class, 'lines']);
                Route::get($base . '/unread', [SmsController::class, 'unread']);
                Route::get($base . '/clients/search', [
                    SmsController::class,
                    'clientSearch',
                ]);

                // Templates. Reading is part of composing; writing checks
                // `manage` in the controller.
                Route::get($base . '/templates', [
                    SmsTemplateController::class,
                    'index',
                ]);
                Route::post($base . '/templates', [
                    SmsTemplateController::class,
                    'store',
                ]);
                Route::put($base . '/templates/{id}', [
                    SmsTemplateController::class,
                    'update',
                ])->whereNumber('id');
                Route::delete($base . '/templates/{id}', [
                    SmsTemplateController::class,
                    'destroy',
                ])->whereNumber('id');

                // Line administration, all of it manage-only.
                Route::get($base . '/settings/lines', [
                    SmsLineSettingsController::class,
                    'index',
                ]);
                Route::post($base . '/settings/lines', [
                    SmsLineSettingsController::class,
                    'store',
                ]);
                Route::put($base . '/settings/lines/{id}', [
                    SmsLineSettingsController::class,
                    'update',
                ])->whereNumber('id');
                Route::delete($base . '/settings/lines/{id}', [
                    SmsLineSettingsController::class,
                    'destroy',
                ])->whereNumber('id');

                // Threads and messages.
                Route::get($base . '/threads', [SmsController::class, 'threads']);
                Route::post($base . '/threads', [
                    SmsController::class,
                    'storeThread',
                ]);

                Route::get($base . '/threads/{id}', [
                    SmsController::class,
                    'showThread',
                ])->whereNumber('id');
                Route::put($base . '/threads/{id}', [
                    SmsController::class,
                    'updateThread',
                ])->whereNumber('id');

                Route::post($base . '/threads/{id}/messages', [
                    SmsController::class,
                    'storeMessage',
                ])->whereNumber('id');
                Route::post($base . '/threads/{id}/read', [
                    SmsController::class,
                    'read',
                ])->whereNumber('id');
                Route::post($base . '/threads/{id}/archive', [
                    SmsController::class,
                    'archive',
                ])->whereNumber('id');
                Route::post($base . '/threads/{id}/unarchive', [
                    SmsController::class,
                    'unarchive',
                ])->whereNumber('id');

                // The dev aid. Manage-only, and refused outright when the Zoom
                // transport is connected - see the controller.
                Route::post($base . '/threads/{id}/simulate-inbound', [
                    SmsController::class,
                    'simulateInbound',
                ])->whereNumber('id');
            });
    }

    /**
     * Append Laravel's throttle middleware to a stack, unless the limit has been
     * configured away.
     *
     * The config value is the bare "<max>,<minutes>" string rather than a named
     * limiter, so an application can retune a limit in config without having to
     * register a RateLimiter in a service provider first.
     *
     * @return array<int, string>
     */
    protected function withThrottle(array $middleware, $limit): array
    {
        if (!is_string($limit) || trim($limit) === '') {
            return $middleware;
        }

        return array_merge($middleware, ['throttle:' . trim($limit)]);
    }

    /**
     * Authorize the call queue's private broadcast channel.
     *
     * A null/empty `permissions.monitor` admits any authenticated user, exactly
     * as withPermission() drops the middleware from the HTTP routes for the
     * same value. Without that the two disagreed: an application that opened
     * the pop to all staff would have unguarded routes and a channel that
     * denied everybody, i.e. a snapshot that loads and never updates.
     *
     * The permission row may not exist yet on an environment that has not
     * seeded it, and a lookup that throws here would take the whole
     * broadcasting auth route down - so a failure denies rather than escapes.
     *
     * @return void
     */
    protected function registerCallQueueChannel(): void
    {
        if (
            !config('visns-packages.call_queue.enabled', false) ||
            !config('visns-packages.call_queue.register_broadcast_channel', true)
        ) {
            return;
        }

        $permission = config(
            'visns-packages.call_queue.permissions.monitor',
            'Call Queue Monitor'
        );

        \Illuminate\Support\Facades\Broadcast::channel(
            \Visnsstudio\VisnsPackages\Support\CallQueueChannel::name(),
            function ($user) use ($permission) {
                if (!is_string($permission) || $permission === '') {
                    return (bool) $user;
                }

                try {
                    return $user->hasPermissionTo($permission);
                } catch (\Throwable $e) {
                    return false;
                }
            }
        );
    }

    /**
     * Authorize the messaging module's private per-line channels.
     *
     * One registration covers every line: the channel name carries the line id,
     * and the callback is handed it. Admitted are the staff attached to that
     * line in the pivot, plus anyone holding the manage permission - the same
     * rule the HTTP endpoints apply, because a channel that was more generous
     * than the API would be the way client conversations leaked.
     *
     * The permission row may not exist yet on an environment that has not seeded
     * it, and a lookup that throws here would take the whole broadcasting auth
     * route down - so a failure denies rather than escapes.
     *
     * @return void
     */
    protected function registerMessagingChannel(): void
    {
        if (
            !config('visns-packages.messaging.enabled', false) ||
            !config('visns-packages.messaging.register_broadcast_channel', false)
        ) {
            return;
        }

        \Illuminate\Support\Facades\Broadcast::channel(
            \Visnsstudio\VisnsPackages\Support\SmsChannel::pattern(),
            function ($user, $lineId) {
                try {
                    if (\Visnsstudio\VisnsPackages\Support\SmsAccess::manages($user)) {
                        return true;
                    }

                    return \Visnsstudio\VisnsPackages\Models\SmsLine::query()
                        ->visibleTo($user, false)
                        ->whereKey((int) $lineId)
                        ->exists();
                } catch (\Throwable $e) {
                    return false;
                }
            }
        );
    }

    /**
     * Append a permission middleware to a stack, unless the permission has been
     * configured away.
     *
     * @return array<int, string>
     */
    protected function withPermission(array $middleware, $permission): array
    {
        if (!is_string($permission) || $permission === '') {
            return $middleware;
        }

        return array_merge($middleware, ['permission:' . $permission]);
    }

    /**
     * The middleware stack for one dynamic entity's routes.
     *
     * The base is the package-wide `routes_middleware` (normally `['web']`,
     * and required here regardless — without it there is no session, so
     * nothing downstream can even tell WHO is asking). On top of that,
     * `entity_config.<entity>.middleware` if the application declared one,
     * or a bare `auth` when it did not.
     *
     * That default is the point of this method existing. These routes used
     * to be registered with no middleware at all, which made every dynamic
     * entity — contacts, customers, the lot — readable and writable by an
     * unauthenticated request, while the `middleware` key the config file
     * documented was never read. An entity that truly must be public now
     * has to say so explicitly (`'middleware' => []` in its entity_config).
     *
     * @return array<int, string>
     */
    protected function entityMiddleware(string $entity, array $base): array
    {
        $declared = config("visns-packages.entity_config.{$entity}.middleware");

        return array_values(array_unique(array_merge(
            $base,
            is_array($declared) ? $declared : ['auth']
        )));
    }

    /**
     * Register dynamic entity routes for DynamicController
     *
     * @return void
     */
    protected function registerDynamicEntityRoutes(array $middleware)
    {
        $dynamicEntities = config('visns-packages.dynamic_entities', []);

        if (!empty($dynamicEntities)) {
            foreach ($dynamicEntities as $entity) {
                // Register dynamic routes for all entities (controller is now determined by DynamicController)
                Route::middleware($this->entityMiddleware($entity, $middleware))
                    ->prefix("ajax/{$entity}")
                    ->controller(
                        \Visnsstudio\VisnsPackages\Controllers\DynamicController::class
                    )
                    ->group(function () use ($entity) {
                        Route::get('/', 'index');
                        Route::post('/', 'store');
                        Route::get('/{id}', 'show');
                        Route::put('/{id}', 'update');
                        Route::post('/updateGallery/{id}', 'updateGallery');
                        Route::post('/{id}/clone', 'clone');
                        Route::post('/merge', 'mergeModels');
                        Route::post('/detect-duplicates', 'detectDuplicates');
                        Route::delete('/{id}', 'destroy');
                        Route::post('/table', 'table');
                        Route::post('/dropdown', 'dropdown');
                        Route::post('/sort/{id}', 'templateSort');
                    });

                Route::middleware($this->entityMiddleware($entity, $middleware))
                    ->prefix("ajax/{$entity}/json")
                    ->controller(
                        \Visnsstudio\VisnsPackages\Controllers\DynamicJsonController::class
                    )
                    ->group(function () use ($entity) {
                        Route::post('/sortList', 'jsonSortList');
                        Route::post('/sortUpdate', 'jsonSortUpdate');
                        Route::post('/table', 'jsonTable');
                        Route::post('/get', 'jsonGet');
                        Route::post('/store', 'jsonStore');
                        Route::put('/update', 'jsonUpdate');
                        Route::post('/delete', 'jsonDelete');
                    });
            }
        }
    }

    /**
     * Register custom routes for entities with specific custom methods
     *
     * @param array<int, string> $middleware the package's routes_middleware
     * @return void
     */
    protected function registerCustomEntityRoutes(array $middleware)
    {
        $entityConfig = config('visns-packages.entity_config', []);

        foreach ($entityConfig as $entity => $config) {
            if (isset($config['custom_routes'])) {
                // Map entity names to controller classes
                $controllerClass = $this->getControllerClassForEntity($entity);

                if ($controllerClass) {
                    Route::middleware($this->entityMiddleware($entity, $middleware))
                        ->prefix("ajax/{$entity}")
                        ->controller($controllerClass)
                        ->group(function () use ($config) {
                            foreach (
                                $config['custom_routes']
                                as $route => $methods
                            ) {
                                if (is_array($methods)) {
                                    // Handle multiple HTTP methods for the same route
                                    foreach (
                                        $methods
                                        as $httpVerb => $methodName
                                    ) {
                                        Route::{$httpVerb}($route, $methodName);
                                    }
                                } else {
                                    // Single method, determine HTTP verb from route pattern
                                    $httpVerb = $this->determineHttpVerb(
                                        $route,
                                        $methods
                                    );
                                    Route::{$httpVerb}($route, $methods);
                                }
                            }
                        });
                }
            }
        }
    }

    /**
     * Get the controller class for a specific entity
     *
     * @param string $entity
     * @return string|null
     */
    protected function getControllerClassForEntity(string $entity): ?string
    {
        $controllerMap = [
            'proposalTemplates' => ProposalTemplateController::class,
            'brandingProfiles' => BrandingProfileController::class,
        ];

        return $controllerMap[$entity] ?? null;
    }

    /**
     * Determine the appropriate HTTP verb for a route
     *
     * @param string $route
     * @param string $method
     * @return string
     */
    protected function determineHttpVerb(string $route, string $method): string
    {
        // If route contains {id} and method contains update/edit, use PUT
        if (
            str_contains($route, '{id}') &&
            (str_contains(strtolower($method), 'update') ||
                str_contains(strtolower($method), 'edit'))
        ) {
            return 'put';
        }

        // If route contains {id} and method contains delete/destroy, use DELETE
        if (
            str_contains($route, '{id}') &&
            (str_contains(strtolower($method), 'delete') ||
                str_contains(strtolower($method), 'destroy'))
        ) {
            return 'delete';
        }

        // If method contains create/store/add/generate, use POST
        if (
            str_contains(strtolower($method), 'create') ||
            str_contains(strtolower($method), 'store') ||
            str_contains(strtolower($method), 'add') ||
            str_contains(strtolower($method), 'generate') ||
            str_contains(strtolower($method), 'duplicate') ||
            str_contains(strtolower($method), 'apply') ||
            str_contains(strtolower($method), 'reorder')
        ) {
            return 'post';
        }

        // Default to GET for all other methods
        return 'get';
    }

    /**
     * Check if MeiliSearch dependencies are available.
     *
     * @return bool
     */
    protected function meilisearchIsAvailable(): bool
    {
        return class_exists(\MeiliSearch\Client::class) &&
            class_exists(\Laravel\Scout\Searchable::class) &&
            config('scout.driver') === 'meilisearch' &&
            !config('visns-packages.search.force_disable_meilisearch', false);
    }

    /**
     * Register OAuth providers with the OAuth manager
     *
     * @param OAuthManager $manager
     * @return void
     */
    protected function registerOAuthProviders(OAuthManager $manager): void
    {
        // Anything declared as an `oauth2` integration becomes a provider with
        // no class of its own. Registered BEFORE the legacy `oauth-providers`
        // list so a hand-written provider class can still take a name back.
        foreach ((array) config('visns-packages.integrations', []) as $name => $definition) {
            if (!is_array($definition) || ($definition['driver'] ?? null) !== 'oauth2') {
                continue;
            }

            try {
                $manager->registerProvider(
                    $name,
                    new \Visnsstudio\VisnsPackages\Services\Providers\GenericOAuthProvider($name)
                );
            } catch (\Throwable $e) {
                if (app()->bound('log')) {
                    app('log')->warning(
                        "Failed to register integration provider {$name}: " . $e->getMessage()
                    );
                }
            }
        }

        $providers = config('oauth-providers', []);

        foreach ($providers as $name => $config) {
            if (!isset($config['provider_class']) || !($config['enabled'] ?? false)) {
                continue;
            }

            $providerClass = $config['provider_class'];
            
            if (!class_exists($providerClass)) {
                continue;
            }

            try {
                $providerInstance = new $providerClass($config);
                $manager->registerProvider($name, $providerInstance);
            } catch (\Exception $e) {
                // Log error but don't break the application
                if (app()->bound('log')) {
                    app('log')->warning("Failed to register OAuth provider {$name}: " . $e->getMessage());
                }
            }
        }
    }
}
