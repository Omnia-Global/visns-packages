<?php

namespace Visnsstudio\VisnsPackages;

use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Facades\Route;
use Visnsstudio\VisnsPackages\Commands\PublishMigrationsCommand;
use Visnsstudio\VisnsPackages\Controllers\AuthController;
use Visnsstudio\VisnsPackages\Controllers\UserController;
use Visnsstudio\VisnsPackages\Controllers\FileController;
use Visnsstudio\VisnsPackages\Controllers\PermissionController;
use Visnsstudio\VisnsPackages\Controllers\RoleController;

class VisnsPackagesServiceProvider extends ServiceProvider
{
    /**
     * Register services.
     *
     * @return void
     */
    public function register()
    {
        // Register commands
        $this->commands([PublishMigrationsCommand::class]);

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

        // Publish config
        $this->publishes(
            [
                __DIR__ . '/../config/visns-packages.php' => config_path(
                    'visns-packages.php'
                ),
            ],
            'visns-packages-config'
        );

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
        // Only register routes if enabled in config and not running in console
        if (
            config('visns-packages.register_routes', true) &&
            !$this->app->runningInConsole()
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
                });
        }
    }
}
