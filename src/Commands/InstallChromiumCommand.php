<?php

namespace Visnsstudio\VisnsPackages\Commands;

use Illuminate\Console\Command;
use Spatie\Browsershot\Browsershot;

class InstallChromiumCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'visns:install-chromium
                           {--force : Force installation even if already detected}
                           {--node-path= : Custom path to Node.js binary}
                           {--npm-path= : Custom path to npm binary}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Install Chromium for Spatie Laravel PDF (required for PDF generation)';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $this->info('🚀 Installing Chromium for Spatie Laravel PDF...');
        $this->newLine();

        // Check if Chromium/Chrome is already available
        if (!$this->option('force') && $this->isChromiumAvailable()) {
            $this->info('✅ Chromium/Chrome is already available!');
            $this->displayBrowserInfo();
            return 0;
        }

        // Check Node.js availability
        if (!$this->checkNodeJs()) {
            return 1;
        }

        // Install Puppeteer (which includes Chromium)
        $this->info('📦 Installing Puppeteer with Chromium...');
        
        try {
            $npmPath = $this->option('npm-path') ?: 'npm';
            
            // Install puppeteer globally
            $command = "{$npmPath} install -g puppeteer";
            $this->info("Running: {$command}");
            
            $process = proc_open(
                $command,
                [
                    0 => ['pipe', 'r'],
                    1 => ['pipe', 'w'],
                    2 => ['pipe', 'w']
                ],
                $pipes
            );

            if (!is_resource($process)) {
                throw new \Exception('Failed to start npm process');
            }

            fclose($pipes[0]);
            $output = stream_get_contents($pipes[1]);
            $error = stream_get_contents($pipes[2]);
            fclose($pipes[1]);
            fclose($pipes[2]);

            $returnCode = proc_close($process);

            if ($returnCode !== 0) {
                $this->error('❌ Failed to install Puppeteer:');
                $this->error($error);
                $this->newLine();
                $this->info('💡 You can also try:');
                $this->info('   • npm install -g puppeteer');
                $this->info('   • Or install Chrome/Chromium manually');
                return 1;
            }

            $this->info('✅ Puppeteer installed successfully!');
            $this->newLine();

            // Verify installation
            if ($this->isChromiumAvailable()) {
                $this->info('🎉 Installation complete! Chromium is now available.');
                $this->displayBrowserInfo();
                $this->newLine();
                $this->info('📄 You can now generate PDFs using Spatie Laravel PDF.');
                return 0;
            } else {
                $this->warn('⚠️  Installation completed but Chromium detection failed.');
                $this->info('💡 Try running the command again or install Chrome manually.');
                return 1;
            }

        } catch (\Exception $e) {
            $this->error('❌ Installation failed: ' . $e->getMessage());
            $this->newLine();
            $this->info('💡 Manual installation options:');
            $this->info('   • macOS: brew install chromium');
            $this->info('   • Ubuntu: apt-get install chromium-browser');
            $this->info('   • Or download Chrome from google.com/chrome');
            return 1;
        }
    }

    /**
     * Check if Chromium/Chrome is available
     */
    private function isChromiumAvailable(): bool
    {
        try {
            $browsershot = new Browsershot();
            $browsershot->html('<h1>Test</h1>')->pdf();
            return true;
        } catch (\Exception $e) {
            return false;
        }
    }

    /**
     * Display browser information
     */
    private function displayBrowserInfo(): void
    {
        try {
            $browsershot = new Browsershot();
            
            // Try to get browser path
            $this->info('🌐 Browser Details:');
            $this->info('   • Status: Available');
            $this->info('   • Ready for PDF generation: Yes');
            
        } catch (\Exception $e) {
            $this->error('   • Error getting browser info: ' . $e->getMessage());
        }
    }

    /**
     * Check if Node.js is available
     */
    private function checkNodeJs(): bool
    {
        $nodePath = $this->option('node-path') ?: 'node';
        
        $process = proc_open(
            "{$nodePath} --version",
            [
                0 => ['pipe', 'r'],
                1 => ['pipe', 'w'],
                2 => ['pipe', 'w']
            ],
            $pipes
        );

        if (!is_resource($process)) {
            $this->error('❌ Node.js not found!');
            $this->info('💡 Please install Node.js first:');
            $this->info('   • Visit: https://nodejs.org/');
            $this->info('   • macOS: brew install node');
            $this->info('   • Ubuntu: apt-get install nodejs npm');
            return false;
        }

        fclose($pipes[0]);
        $output = stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        proc_close($process);

        if (!empty(trim($output))) {
            $this->info("✅ Node.js found: " . trim($output));
            return true;
        } else {
            $this->error('❌ Node.js not working properly!');
            return false;
        }
    }
}