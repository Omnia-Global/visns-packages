<?php

namespace Visnsstudio\VisnsPackages;

use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Facades\Route;
use Visnsstudio\VisnsPackages\Commands\PublishMigrationsCommand;
use Visnsstudio\VisnsPackages\Commands\MeilisearchConfigureCommand;
use Visnsstudio\VisnsPackages\Commands\MeilisearchDebugCommand;
use Visnsstudio\VisnsPackages\Commands\MeilisearchTestCommand;
use Visnsstudio\VisnsPackages\Commands\MeilisearchSyncCommand;
use Visnsstudio\VisnsPackages\Controllers\AuthController;
use Visnsstudio\VisnsPackages\Controllers\UserController;
use Visnsstudio\VisnsPackages\Controllers\FileController;
use Visnsstudio\VisnsPackages\Controllers\PermissionController;
use Visnsstudio\VisnsPackages\Controllers\RoleController;
use Visnsstudio\VisnsPackages\Controllers\SocialiteController;
use Visnsstudio\VisnsPackages\Controllers\ReportBuilderController;
use Visnsstudio\VisnsPackages\Controllers\PDFController;
use Visnsstudio\VisnsPackages\Controllers\ProposalTemplateController;
use Visnsstudio\VisnsPackages\Controllers\BrandingProfileController;
use Visnsstudio\VisnsPackages\Middleware\AcceptJson;
use Visnsstudio\VisnsPackages\Services\FilePathResolver;

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
        $commands = [PublishMigrationsCommand::class];

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

        // Merge config
        $this->mergeConfigFrom(
            __DIR__ . '/../config/visns-packages.php',
            'visns-packages'
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
                __DIR__ . '/../database/seeders' => database_path(
                    'seeders'
                ),
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

        // Register middleware
        $router = $this->app['router'];
        $router->aliasMiddleware('accept-json', AcceptJson::class);

        // Register routes
        $this->registerRoutes();
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

        if (
            config('visns-packages.register_routes', true) &&
            (!$runningInConsole || $runningRouteList)
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

                    // Report Builder routes
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
                            Route::post('/generate-proposal', 'generateProposalPDF');
                            Route::post('/preview-proposal', 'previewProposalPDF');
                            Route::post('/generate-proposal-html', 'generateProposalHTML');
                        });

                    // Proposal Template routes (backward compatible)
                    Route::prefix('ajax/proposal-templates')
                        ->controller(ProposalTemplateController::class)
                        ->group(function () {
                            Route::get('/', 'index');
                            Route::post('/', 'store');
                            Route::get('/{id}', 'show');
                            Route::put('/{id}', 'update');
                            Route::delete('/{id}', 'destroy');
                            Route::post('/table', 'table');
                            Route::post('/dropdown', 'dropdown');
                            Route::get('/{id}/preview', 'preview');
                            Route::post('/{id}/duplicate', 'duplicate');
                            Route::get('/variables/available', 'getAvailableVariables');
                        });

                    // Branding Profile routes (backward compatible)  
                    Route::prefix('ajax/branding-profiles')
                        ->controller(BrandingProfileController::class)
                        ->group(function () {
                            Route::get('/', 'index');
                            Route::post('/', 'store');
                            Route::get('/{id}', 'show');
                            Route::put('/{id}', 'update');
                            Route::delete('/{id}', 'destroy');
                            Route::post('/table', 'table');
                            Route::post('/dropdown', 'dropdown');
                            Route::get('/default', 'getDefault');
                            Route::post('/apply-branding', 'applyBranding');
                            Route::get('/{id}/preview', 'preview');
                            Route::post('/{id}/duplicate', 'duplicate');
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
                            Route::post('/generate-proposal', 'generateProposalPDF');
                            Route::post('/preview-proposal', 'previewProposalPDF');
                            Route::post('/generate-proposal-html', 'generateProposalHTML');
                        });
                });

            // Register dynamic entity routes if configured
            $this->registerDynamicEntityRoutes();
        }
    }

    /**
     * Register dynamic entity routes for DynamicController
     *
     * @return void
     */
    protected function registerDynamicEntityRoutes()
    {
        $dynamicEntities = config('visns-packages.dynamic_entities', []);
        $entityConfig = config('visns-packages.entity_config', []);
        
        if (!empty($dynamicEntities)) {
            foreach ($dynamicEntities as $entity) {
                // Check if entity has a specific controller defined in entity_config
                $hasSpecificController = isset($entityConfig[$entity]) && isset($entityConfig[$entity]['controller']);
                
                // Only register dynamic routes for entities that don't have specific controllers
                if (!$hasSpecificController) {
                    Route::prefix("ajax/{$entity}")
                        ->controller(\Visnsstudio\VisnsPackages\Controllers\DynamicController::class)
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
                        });

                    Route::prefix("ajax/{$entity}/json")
                        ->controller(\Visnsstudio\VisnsPackages\Controllers\DynamicJsonController::class)
                        ->group(function () use ($entity) {
                            Route::post('/sortList', 'jsonSortList');
                            Route::post('/sortUpdate', 'jsonSortUpdate');
                            Route::post('/table', 'jsonTable');
                            Route::post('/get', 'jsonGet');
                            Route::post('/set', 'jsonSet');
                            Route::post('/push', 'jsonPush');
                            Route::post('/where', 'jsonWhere');
                            Route::post('/search', 'jsonSearch');
                            Route::post('/clone', 'jsonClone');
                            Route::post('/toggle', 'jsonToggle');
                            Route::post('/delete', 'jsonDelete');
                            Route::post('/dropdown', 'jsonDropdown');
                        });
                }
            }
        }
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
}
