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
        // Method 1: Check common browser paths
        $browserPaths = [
            // Linux paths (Ubuntu/Debian)
            '/usr/bin/chromium-browser',
            '/usr/bin/chromium',
            '/usr/bin/google-chrome',
            '/usr/bin/google-chrome-stable',
            // macOS paths
            '/Applications/Google Chrome.app/Contents/MacOS/Google Chrome',
            '/Applications/Chromium.app/Contents/MacOS/Chromium',
            // Puppeteer global installation
            '/usr/local/lib/node_modules/puppeteer/.local-chromium/*/chrome-linux/chrome',
            '/usr/lib/node_modules/puppeteer/.local-chromium/*/chrome-linux/chrome',
        ];

        foreach ($browserPaths as $path) {
            if (file_exists($path) && is_executable($path)) {
                return true;
            }
        }

        // Method 2: Check if browsers are in PATH
        $browsers = ['chromium-browser', 'chromium', 'google-chrome', 'chrome'];
        foreach ($browsers as $browser) {
            if ($this->commandExists($browser)) {
                return true;
            }
        }

        // Method 3: Try Puppeteer's chromium
        try {
            $command = 'node -e "console.log(require(\'puppeteer\').executablePath())"';
            $process = proc_open(
                $command,
                [
                    0 => ['pipe', 'r'],
                    1 => ['pipe', 'w'],
                    2 => ['pipe', 'w']
                ],
                $pipes
            );

            if (is_resource($process)) {
                fclose($pipes[0]);
                $output = trim(stream_get_contents($pipes[1]));
                fclose($pipes[1]);
                fclose($pipes[2]);
                proc_close($process);

                if (!empty($output) && file_exists($output)) {
                    return true;
                }
            }
        } catch (\Exception $e) {
            // Puppeteer not available, continue with other methods
        }

        // Method 4: Last resort - try basic Browsershot test (less aggressive)
        try {
            $browsershot = new Browsershot();
            $browsershot->html('<h1>Test</h1>')->pdf();
            return true;
        } catch (\Exception $e) {
            return false;
        }
    }

    /**
     * Check if a command exists in PATH
     */
    private function commandExists(string $command): bool
    {
        $whereIs = (PHP_OS == 'WINNT') ? 'where' : 'which';
        $process = proc_open(
            "{$whereIs} {$command}",
            [
                0 => ['pipe', 'r'],
                1 => ['pipe', 'w'],
                2 => ['pipe', 'w']
            ],
            $pipes
        );

        if (!is_resource($process)) {
            return false;
        }

        fclose($pipes[0]);
        $output = stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $returnCode = proc_close($process);

        return $returnCode === 0 && !empty(trim($output));
    }

    /**
     * Display browser information
     */
    private function displayBrowserInfo(): void
    {
        $this->info('🌐 Browser Details:');
        
        // Check specific browser paths and show which one is found
        $foundBrowsers = [];
        
        $browserPaths = [
            'chromium-browser' => '/usr/bin/chromium-browser',
            'chromium' => '/usr/bin/chromium',
            'google-chrome' => '/usr/bin/google-chrome',
            'google-chrome-stable' => '/usr/bin/google-chrome-stable',
        ];

        foreach ($browserPaths as $name => $path) {
            if (file_exists($path) && is_executable($path)) {
                $foundBrowsers[] = "{$name} ({$path})";
            }
        }

        // Check PATH browsers
        $pathBrowsers = ['chromium-browser', 'chromium', 'google-chrome', 'chrome'];
        foreach ($pathBrowsers as $browser) {
            if ($this->commandExists($browser)) {
                $foundBrowsers[] = "{$browser} (in PATH)";
            }
        }

        // Check Puppeteer's chromium
        try {
            $command = 'node -e "console.log(require(\'puppeteer\').executablePath())"';
            $process = proc_open(
                $command,
                [
                    0 => ['pipe', 'r'],
                    1 => ['pipe', 'w'],
                    2 => ['pipe', 'w']
                ],
                $pipes
            );

            if (is_resource($process)) {
                fclose($pipes[0]);
                $output = trim(stream_get_contents($pipes[1]));
                fclose($pipes[1]);
                fclose($pipes[2]);
                proc_close($process);

                if (!empty($output) && file_exists($output)) {
                    $foundBrowsers[] = "puppeteer chromium ({$output})";
                }
            }
        } catch (\Exception $e) {
            // Puppeteer not available
        }

        if (!empty($foundBrowsers)) {
            $this->info('   • Found browsers:');
            foreach ($foundBrowsers as $browser) {
                $this->info("     - {$browser}");
            }
            $this->info('   • Status: Available');
            $this->info('   • Ready for PDF generation: Yes');
        } else {
            $this->warn('   • No browsers detected in common locations');
            $this->info('   • You may need to configure custom browser path');
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