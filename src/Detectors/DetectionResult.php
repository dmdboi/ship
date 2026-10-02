<?php

namespace Dmdboi\Ship\Detectors;

/**
 * Data Transfer Object holding environment detection results.
 */
final class DetectionResult
{
    /**
     * @param  array<string, bool>  $packages
     * @param  array<int, string>  $phpExtensions
     */
    public function __construct(
        public ?string $phpVersion = null,
        public ?string $database = null,
        public bool $hasFrontend = false,
        public ?string $frontendBuildScript = null,
        public string $frontendPackageManager = 'npm',
        public ?string $frontendLockfile = null,
        public ?string $healthcheckEndpoint = null,
        public array $packages = [],
        public array $phpExtensions = [],
    ) {}

    /**
     * Check if a package is detected.
     */
    public function hasPackage(string $name): bool
    {
        return $this->packages[$name] ?? false;
    }

    /**
     * Get the recommended PHP version based on detection.
     */
    public function getRecommendedPhpVersion(): string
    {
        if (! $this->phpVersion) {
            return '8.4';
        }

        if (str_contains($this->phpVersion, '8.2')) {
            return '8.2';
        }
        if (str_contains($this->phpVersion, '8.3')) {
            return '8.3';
        }
        if (str_contains($this->phpVersion, '8.4')) {
            return '8.4';
        }

        // For constraints like ^8.2, >=8.2, etc.
        if (preg_match('/8\.([234])/', $this->phpVersion, $matches)) {
            return '8.'.$matches[1];
        }

        return '8.4';
    }

    /**
     * Get the normalized database driver.
     */
    public function getNormalizedDatabase(): string
    {
        return match ($this->database) {
            'pgsql'            => 'pgsql',
            'mysql', 'mariadb' => 'mysql',
            'sqlite'           => 'sqlite',
            default            => 'pgsql',
        };
    }
}
