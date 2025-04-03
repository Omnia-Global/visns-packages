<?php

namespace Visnsstudio\VisnsPackages;

use Illuminate\Support\ServiceProvider;
use Visnsstudio\VisnsPackages\Commands\PublishMigrationsCommand;

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
    }
}
