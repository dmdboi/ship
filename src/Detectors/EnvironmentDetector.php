<?php

namespace Dmdboi\Ship\Detectors;

use Illuminate\Filesystem\Filesystem;

/**
 * Detects the Laravel application's environment configuration.
 */
class EnvironmentDetector
{
    public function __construct(
        protected Filesystem $files,
        protected string $basePath,
    ) {}

    /**
     * Run all detections and return the result.
     */
    public function detect(): DetectionResult
    {
        return new DetectionResult(
            phpVersion: $this->detectPhpVersion(),
            database: $this->detectDatabaseDriver(),
            hasFrontend: $this->detectHasFrontend(),
            frontendBuildScript: $this->detectFrontendBuildScript(),
            frontendPackageManager: $this->detectFrontendPackageManager(),
            frontendLockfile: $this->detectFrontendLockfile(),
            healthcheckEndpoint: $this->detectHealthcheckEndpoint(),
            packages: $this->detectPackages(),
            phpExtensions: $this->detectPhpExtensions(),
        );
    }

    /**
     * Detect PHP version from composer.json.
     */
    protected function detectPhpVersion(): ?string
    {
        $composerJson = $this->basePath.'/composer.json';

        if (! $this->files->exists($composerJson)) {
            return null;
        }

        $composer = json_decode($this->files->get($composerJson), true);

        // A composer.json constraint can be broad while the lockfile contains
        // packages that require a newer PHP minor version.
        $composerLock = $this->basePath.'/composer.lock';
        if ($this->files->exists($composerLock)) {
            $lock         = json_decode($this->files->get($composerLock), true);
            $requirements = [];

            foreach (array_merge($lock['packages'] ?? [], $lock['packages-dev'] ?? []) as $package) {
                if (isset($package['require']['php'])) {
                    $requirements[] = $package['require']['php'];
                }
            }

            if (collect($requirements)->contains(fn (string $requirement): bool => preg_match('/(?:^|\|\s*)>=\s*8\.4(?:\.\d+)?/', $requirement) === 1)) {
                return '8.4';
            }
        }

        return $composer['require']['php'] ?? null;
    }

    /**
     * Detect database driver from .env or config/database.php.
     */
    protected function detectDatabaseDriver(): ?string
    {
        // First try .env
        $envFile = $this->basePath.'/.env';
        if ($this->files->exists($envFile)) {
            $envContent = $this->files->get($envFile);

            if (preg_match('/^DB_CONNECTION=(.+)$/m', $envContent, $matches)) {
                return trim($matches[1]);
            }
        }

        // Fall back to config/database.php default
        $databaseConfig = $this->basePath.'/config/database.php';
        if ($this->files->exists($databaseConfig)) {
            $content = $this->files->get($databaseConfig);

            if (preg_match('/[\'"]default[\'"]\s*=>\s*env\s*\(\s*[\'"]DB_CONNECTION[\'"]\s*,\s*[\'"](\w+)[\'"]\s*\)/', $content, $matches)) {
                return $matches[1];
            }
        }

        return null;
    }

    /**
     * Detect if the project has frontend assets.
     */
    protected function detectHasFrontend(): bool
    {
        return $this->files->exists($this->basePath.'/package.json');
    }

    /**
     * Detect the frontend build script name.
     */
    protected function detectFrontendBuildScript(): ?string
    {
        $packageJson = $this->basePath.'/package.json';

        if (! $this->files->exists($packageJson)) {
            return null;
        }

        $package = json_decode($this->files->get($packageJson), true);
        $scripts = $package['scripts'] ?? [];

        if (isset($scripts['build'])) {
            return 'build';
        }
        if (isset($scripts['prod'])) {
            return 'prod';
        }
        if (isset($scripts['production'])) {
            return 'production';
        }

        return null;
    }

    protected function detectFrontendPackageManager(): string
    {
        if ($this->files->exists($this->basePath.'/pnpm-lock.yaml')) {
            return 'pnpm';
        }

        if ($this->files->exists($this->basePath.'/yarn.lock')) {
            return 'yarn';
        }

        return 'npm';
    }

    protected function detectFrontendLockfile(): ?string
    {
        foreach (['pnpm-lock.yaml', 'yarn.lock', 'package-lock.json'] as $lockfile) {
            if ($this->files->exists($this->basePath.'/'.$lockfile)) {
                return $lockfile;
            }
        }

        return null;
    }

    /**
     * Detect healthcheck endpoint from routes or bootstrap.
     */
    protected function detectHealthcheckEndpoint(): ?string
    {
        // Check routes/web.php for health routes
        $webRoutes = $this->basePath.'/routes/web.php';
        if ($this->files->exists($webRoutes)) {
            $content = $this->files->get($webRoutes);

            if (preg_match('/[\'"]\/up[\'"]/', $content)) {
                return '/up';
            }

            if (preg_match('/[\'"]\/health[\'"]/', $content)) {
                return '/health';
            }

            if (preg_match('/[\'"]\/healthcheck[\'"]/', $content)) {
                return '/healthcheck';
            }
        }

        // Check bootstrap/app.php for Laravel 11+ health middleware
        $bootstrapApp = $this->basePath.'/bootstrap/app.php';
        if ($this->files->exists($bootstrapApp)) {
            $content = $this->files->get($bootstrapApp);

            if (str_contains($content, '->withHealthChecks') || str_contains($content, '/up')) {
                return '/up';
            }
        }

        return null;
    }

    /**
     * Detect installed packages from composer.lock.
     *
     * @return array<string, bool>
     */
    protected function detectPackages(): array
    {
        $packages     = [];
        $composerLock = $this->basePath.'/composer.lock';

        if (! $this->files->exists($composerLock)) {
            return $packages;
        }

        $lockContent = json_decode($this->files->get($composerLock), true);
        $allPackages = array_merge(
            $lockContent['packages'] ?? [],
            $lockContent['packages-dev'] ?? []
        );

        $packageNames = array_column($allPackages, 'name');

        // Detect FilamentPHP
        $packages['filament'] = in_array('filament/filament', $packageNames)
            || in_array('filament/support', $packageNames);

        // Detect Horizon
        $packages['horizon'] = in_array('laravel/horizon', $packageNames);

        return $packages;
    }

    /**
     * Detect production PHP extensions required by Composer.
     *
     * @return array<int, string>
     */
    protected function detectPhpExtensions(): array
    {
        $composerJson = $this->basePath.'/composer.json';

        if (! $this->files->exists($composerJson)) {
            return [];
        }

        $composer     = json_decode($this->files->get($composerJson), true);
        $requirements = array_keys($composer['require'] ?? []);

        return array_values(array_unique(array_map(
            fn (string $requirement): string => substr($requirement, 4),
            array_filter($requirements, fn (string $requirement): bool => str_starts_with($requirement, 'ext-')),
        )));
    }
}
