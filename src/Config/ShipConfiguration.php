<?php

namespace Dmdboi\Ship\Config;

/**
 * Data Transfer Object holding all configuration options for Ship.
 */
final class ShipConfiguration
{
    public function __construct(
        public string $phpVersion = '8.4',
        public string $database = 'pgsql',
        /** @var array<int, string> */
        public array $phpExtensions = [],
        public bool $hasFrontend = false,
        public string $frontendBuildScript = 'build',
        public string $frontendPackageManager = 'npm',
        public ?string $frontendLockfile = null,
        public bool $healthcheck = false,
        public string $healthcheckEndpoint = '/up',
        public bool $scheduler = true,
        public string $workerQueue = 'default',
        public int $workerProcesses = 1,
        public int $workerTries = 3,
        public int $workerTimeout = 120,
        public bool $deployScript = true,
        public string $imageName = '',
    ) {}

    /**
     * Create a configuration from an array of options.
     *
     * @param  array<string, mixed>  $options
     */
    public static function fromArray(array $options): self
    {
        return new self(
            phpVersion: $options['php_version'] ?? '8.4',
            database: $options['database'] ?? 'pgsql',
            phpExtensions: $options['php_extensions'] ?? [],
            hasFrontend: $options['has_frontend'] ?? false,
            frontendBuildScript: $options['frontend_build_script'] ?? 'build',
            frontendPackageManager: $options['frontend_package_manager'] ?? 'npm',
            frontendLockfile: $options['frontend_lockfile'] ?? null,
            healthcheck: $options['healthcheck'] ?? false,
            healthcheckEndpoint: $options['healthcheck_endpoint'] ?? '/up',
            scheduler: $options['scheduler'] ?? true,
            workerQueue: $options['worker_queue'] ?? 'default',
            workerProcesses: $options['worker_processes'] ?? 1,
            workerTries: $options['worker_tries'] ?? 3,
            workerTimeout: $options['worker_timeout'] ?? 120,
            deployScript: $options['deploy_script'] ?? true,
            imageName: $options['image_name'] ?? '',
        );
    }

    /**
     * Convert the configuration to an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'php_version'              => $this->phpVersion,
            'database'                 => $this->database,
            'php_extensions'           => $this->phpExtensions,
            'has_frontend'             => $this->hasFrontend,
            'frontend_build_script'    => $this->frontendBuildScript,
            'frontend_package_manager' => $this->frontendPackageManager,
            'frontend_lockfile'        => $this->frontendLockfile,
            'healthcheck'              => $this->healthcheck,
            'healthcheck_endpoint'     => $this->healthcheckEndpoint,
            'scheduler'                => $this->scheduler,
            'worker_queue'             => $this->workerQueue,
            'worker_processes'         => $this->workerProcesses,
            'worker_tries'             => $this->workerTries,
            'worker_timeout'           => $this->workerTimeout,
            'deploy_script'            => $this->deployScript,
            'image_name'               => $this->imageName,
        ];
    }

    /**
     * Create a copy with modified values.
     *
     * @param  array<string, mixed>  $overrides
     */
    public function with(array $overrides): self
    {
        return self::fromArray(array_merge($this->toArray(), $overrides));
    }
}
