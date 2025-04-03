<?php

namespace Visnsstudio\VisnsPackages\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

class PublishMigrationsCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'visns:publish-migrations';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Publish migrations from the Visns Packages';

    /**
     * Execute the console command.
     *
     * @return int
     */
    public function handle()
    {
        $this->info('Publishing migrations from Visns Packages...');

        $this->call('vendor:publish', [
            '--tag' => 'visns-packages-migrations',
        ]);

        $this->info('Migrations published successfully!');

        return 0;
    }
}
