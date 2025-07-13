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
                           {--yarn-path= : Custom path to yarn binary}
                           {--use-npm : Use npm instead of yarn}
                           {--local : Install locally in project (default)}
                           {--global : Install globally (not recommended for Forge)}';

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
            $useNpm = $this->option('use-npm');
            $isGlobal = $this->option('global');
            $isLocal = $this->option('local') || !$isGlobal; // Default to local
            
            if ($useNpm) {
                $packageManager = $this->option('npm-path') ?: 'npm';
                $installFlag = $isGlobal ? '-g' : '';
            } else {
                $packageManager = $this->option('yarn-path') ?: 'yarn';
                $installFlag = $isGlobal ? 'global add' : 'add';
            }
            
            // Prepare the installation command
            if ($useNpm) {
                $command = $isGlobal ? 
                    "{$packageManager} install -g puppeteer" : 
                    "{$packageManager} install puppeteer";
            } else {
                $command = $isGlobal ? 
                    "{$packageManager} global add puppeteer" : 
                    "{$packageManager} add puppeteer";
            }
            
            $this->info("Running: {$command}");
            $this->info($isLocal ? "📍 Installing locally in project directory" : "🌐 Installing globally");
            
            // Set up environment for local installation
            $env = $_ENV;
            if ($isLocal) {
                // Ensure we're in the Laravel project root
                $projectRoot = base_path();
                $this->info("📂 Project directory: {$projectRoot}");
                
                // Create local cache directory for Puppeteer if needed
                $cacheDir = $projectRoot . '/.cache/puppeteer';
                if (!is_dir($cacheDir)) {
                    mkdir($cacheDir, 0755, true);
                    $this->info("📁 Created cache directory: {$cacheDir}");
                }
                
                $env['PUPPETEER_CACHE_DIR'] = $cacheDir;
            }
            
            $process = proc_open(
                $command,
                [
                    0 => ['pipe', 'r'],
                    1 => ['pipe', 'w'],
                    2 => ['pipe', 'w']
                ],
                $pipes,
                $isLocal ? base_path() : null,
                $env
            );

            if (!is_resource($process)) {
                throw new \Exception("Failed to start {$packageManager} process");
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
                if ($useNpm) {
                    $this->info('   • npm install puppeteer (local)');
                    $this->info('   • npm install -g puppeteer (global)');
                } else {
                    $this->info('   • yarn add puppeteer (local)');
                    $this->info('   • yarn global add puppeteer (global)');
                }
                $this->info('   • Or install Chrome/Chromium manually');
                return 1;
            }

            $this->info('✅ Puppeteer installed successfully!');
            
            // Update .env with executable path if local installation
            if ($isLocal) {
                $this->updateEnvironmentFile();
            }
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
        // Method 1: Check local project Puppeteer installation first
        $projectRoot = base_path();
        $localPuppeteerPaths = [
            // Local node_modules installation
            $projectRoot . '/node_modules/puppeteer/.local-chromium/*/chrome-linux/chrome',
            $projectRoot . '/node_modules/puppeteer/.local-chromium/*/chrome-*/chrome',
            // Local cache directory
            $projectRoot . '/.cache/puppeteer/chrome/*/chrome-linux/chrome',
            $projectRoot . '/.cache/puppeteer/chrome/*/chrome-*/chrome',
        ];

        foreach ($localPuppeteerPaths as $pathPattern) {
            $matches = glob($pathPattern);
            foreach ($matches as $path) {
                if (file_exists($path) && is_executable($path)) {
                    return true;
                }
            }
        }

        // Method 2: Check common system browser paths
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

        foreach ($browserPaths as $pathPattern) {
            if (strpos($pathPattern, '*') !== false) {
                $matches = glob($pathPattern);
                foreach ($matches as $path) {
                    if (file_exists($path) && is_executable($path)) {
                        return true;
                    }
                }
            } else {
                if (file_exists($pathPattern) && is_executable($pathPattern)) {
                    return true;
                }
            }
        }

        // Method 3: Check if browsers are in PATH
        $browsers = ['chromium-browser', 'chromium', 'google-chrome', 'chrome'];
        foreach ($browsers as $browser) {
            if ($this->commandExists($browser)) {
                return true;
            }
        }

        // Method 4: Try Puppeteer's executablePath (local first, then global)
        $puppeteerCommands = [
            // Local installation
            "cd {$projectRoot} && node -e \"console.log(require('./node_modules/puppeteer').executablePath())\"",
            // Global installation
            'node -e "console.log(require(\'puppeteer\').executablePath())"'
        ];

        foreach ($puppeteerCommands as $command) {
            try {
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
                // Continue to next method
            }
        }

        // Method 5: Last resort - try basic Browsershot test (less aggressive)
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
        $projectRoot = base_path();
        
        // Check local Puppeteer installations first
        $localPuppeteerPaths = [
            $projectRoot . '/node_modules/puppeteer/.local-chromium/*/chrome-linux/chrome',
            $projectRoot . '/node_modules/puppeteer/.local-chromium/*/chrome-*/chrome',
            $projectRoot . '/.cache/puppeteer/chrome/*/chrome-linux/chrome',
            $projectRoot . '/.cache/puppeteer/chrome/*/chrome-*/chrome',
        ];

        foreach ($localPuppeteerPaths as $pathPattern) {
            $matches = glob($pathPattern);
            foreach ($matches as $path) {
                if (file_exists($path) && is_executable($path)) {
                    $foundBrowsers[] = "local puppeteer ({$path})";
                    break 2; // Found local installation, no need to check others
                }
            }
        }
        
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

        // Check Puppeteer's chromium (both local and global)
        $puppeteerCommands = [
            // Local installation
            ['cmd' => "cd {$projectRoot} && node -e \"console.log(require('./node_modules/puppeteer').executablePath())\"", 'type' => 'local puppeteer'],
            // Global installation
            ['cmd' => 'node -e "console.log(require(\'puppeteer\').executablePath())"', 'type' => 'global puppeteer']
        ];

        foreach ($puppeteerCommands as $puppeteer) {
            try {
                $process = proc_open(
                    $puppeteer['cmd'],
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
                        $foundBrowsers[] = "{$puppeteer['type']} ({$output})";
                    }
                }
            } catch (\Exception $e) {
                // Puppeteer not available
            }
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

    /**
     * Update .env file with Puppeteer executable path
     */
    private function updateEnvironmentFile(): void
    {
        $projectRoot = base_path();
        $envPath = $projectRoot . '/.env';
        
        if (!file_exists($envPath)) {
            $this->warn('⚠️  .env file not found, skipping environment configuration');
            return;
        }

        // Try to find the local Puppeteer executable
        $executablePath = $this->findLocalPuppeteerExecutable();
        
        if (!$executablePath) {
            $this->warn('⚠️  Could not determine Puppeteer executable path');
            return;
        }

        $this->info("🔧 Configuring environment variables...");
        
        // Read current .env content
        $envContent = file_get_contents($envPath);
        $lines = explode("\n", $envContent);
        $updated = false;
        
        // Environment variables to set/update
        $envVars = [
            'PUPPETEER_EXECUTABLE_PATH' => $executablePath,
            'BROWSERSHOT_NODE_BINARY' => 'node',
            'BROWSERSHOT_NPM_BINARY' => 'npm',
        ];
        
        foreach ($envVars as $key => $value) {
            $found = false;
            foreach ($lines as $index => $line) {
                if (strpos($line, $key . '=') === 0) {
                    $lines[$index] = $key . '=' . $value;
                    $found = true;
                    $updated = true;
                    break;
                }
            }
            
            if (!$found) {
                $lines[] = $key . '=' . $value;
                $updated = true;
            }
        }
        
        if ($updated) {
            file_put_contents($envPath, implode("\n", $lines));
            $this->info("✅ Updated .env with Puppeteer configuration");
            $this->info("   • PUPPETEER_EXECUTABLE_PATH={$executablePath}");
        }
    }

    /**
     * Find the local Puppeteer executable path
     */
    private function findLocalPuppeteerExecutable(): ?string
    {
        $projectRoot = base_path();
        
        // Try using Node.js to get the executable path
        $command = "cd {$projectRoot} && node -e \"try { console.log(require('./node_modules/puppeteer').executablePath()); } catch(e) { process.exit(1); }\"";
        
        try {
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
                $returnCode = proc_close($process);

                if ($returnCode === 0 && !empty($output) && file_exists($output)) {
                    return $output;
                }
            }
        } catch (\Exception $e) {
            // Fall back to manual search
        }
        
        // Fallback: manually search for Chrome executable
        $searchPaths = [
            $projectRoot . '/node_modules/puppeteer/.local-chromium/*/chrome-linux/chrome',
            $projectRoot . '/node_modules/puppeteer/.local-chromium/*/chrome-*/chrome',
            $projectRoot . '/.cache/puppeteer/chrome/*/chrome-linux/chrome',
            $projectRoot . '/.cache/puppeteer/chrome/*/chrome-*/chrome',
        ];

        foreach ($searchPaths as $pathPattern) {
            $matches = glob($pathPattern);
            foreach ($matches as $path) {
                if (file_exists($path) && is_executable($path)) {
                    return $path;
                }
            }
        }
        
        return null;
    }
}