<?php

namespace Dmdboi\Ship\Commands;

use Dmdboi\Ship\Builders\DockerfileBuilder;
use Dmdboi\Ship\Builders\EntrypointBuilder;
use Dmdboi\Ship\Builders\SupervisorBuilder;
use Dmdboi\Ship\Config\ShipConfiguration;
use Dmdboi\Ship\Detectors\DetectionResult;
use Dmdboi\Ship\Detectors\EnvironmentDetector;
use Dmdboi\Ship\Publishers\FilePublisher;
use Illuminate\Console\Command;
use Illuminate\Filesystem\Filesystem;

use function Laravel\Prompts\multiselect;
use function Laravel\Prompts\select;
use function Laravel\Prompts\text;

class ShipCommand extends Command
{
    public $signature = 'ship:install
        {--defaults : Use detected defaults without prompts}
        {--force : Overwrite existing files}
        {--no-build : Generate configuration without building the Docker image}
        {--pull : Always attempt to pull newer base images}
        {--no-cache : Do not use Docker build cache}
        {--build-arg=* : Pass a KEY=VALUE build argument to Docker}
        {--php-extension=* : Add a PHP extension to the image}';

    public $description = 'Install Docker container configuration files for your Laravel application';

    protected Filesystem $files;

    protected DetectionResult $detection;

    protected ShipConfiguration $config;

    protected FilePublisher $publisher;

    protected bool $publishingSuccessful = true;

    public function __construct(Filesystem $files)
    {
        parent::__construct();
        $this->files = $files;
    }

    public function handle(): int
    {
        $this->info('Ship - Laravel Container Configuration');
        $this->line('');

        // Run detection
        $detector        = new EnvironmentDetector($this->files, base_path());
        $this->detection = $detector->detect();
        $this->outputDetectedPackages();

        // Setup publisher
        $this->publisher = new FilePublisher(
            $this->files,
            base_path(),
            (bool) $this->option('force'),
            (bool) $this->option('defaults')
        );

        // Gather configuration
        if ($this->option('defaults')) {
            $this->config = $this->buildDefaultConfiguration();
            $this->showDefaultsSummary();
        } else {
            $this->config = $this->gatherOptionsInteractively();
        }

        $this->config->phpExtensions = array_values(array_unique(array_merge(
            $this->detection->phpExtensions,
            $this->config->phpExtensions,
            $this->phpExtensionOptions(),
        )));

        // Publish files
        if (! $this->publishFiles()) {
            return self::FAILURE;
        }

        if (! $this->option('no-build') && ! $this->buildImage()) {
            return self::FAILURE;
        }

        $this->showCompletionMessage();

        return self::SUCCESS;
    }

    // =========================================================================
    // Defaults Mode
    // =========================================================================

    protected function buildDefaultConfiguration(): ShipConfiguration
    {
        $imageName = $this->projectName();

        return new ShipConfiguration(
            phpVersion: $this->detection->getRecommendedPhpVersion(),
            database: $this->detection->getNormalizedDatabase(),
            phpExtensions: array_values(array_unique(array_merge(
                $this->detection->phpExtensions,
                array_map(
                    fn (string $extension): string => str_starts_with($extension, 'ext-') ? substr($extension, 4) : $extension,
                    (array) $this->option('php-extension'),
                ),
            ))),
            hasFrontend: $this->detection->hasFrontend,
            frontendBuildScript: $this->detection->frontendBuildScript ?? 'build',
            frontendPackageManager: $this->detection->frontendPackageManager,
            frontendLockfile: $this->detection->frontendLockfile,
            healthcheck: $this->detection->healthcheckEndpoint !== null,
            healthcheckEndpoint: $this->detection->healthcheckEndpoint ?? '/up',
            scheduler: true,
            workerQueue: 'default',
            workerProcesses: 1,
            workerTries: 3,
            workerTimeout: 120,
            deployScript: true,
            imageName: $imageName,
        );
    }

    protected function showDefaultsSummary(): void
    {
        $this->info('Using detected defaults:');
        $this->table(
            ['Setting', 'Value'],
            [
                ['PHP Version', $this->config->phpVersion],
                ['Database', $this->config->database],
                ['Frontend Build', $this->config->hasFrontend ? 'Yes' : 'No'],
                ['Healthcheck', $this->config->healthcheck ? $this->config->healthcheckEndpoint : 'No'],
                ['PHP Extensions', implode(', ', $this->config->phpExtensions) ?: 'Defaults only'],
                ['Scheduler', 'Yes'],
                ['Worker Queue', $this->config->workerQueue],
                ['Worker Processes', $this->config->workerProcesses],
                ['Deploy Script', 'Yes'],
                ['Image', $this->config->imageName],
            ]
        );
        $this->newLine();
        $this->comment('Run without --defaults to customize these options.');
        $this->newLine();
    }

    // =========================================================================
    // Interactive Mode
    // =========================================================================

    protected function gatherOptionsInteractively(): ShipConfiguration
    {
        $database      = $this->gatherDatabaseDriver();
        $phpExtensions = $this->gatherPhpExtensions();

        return new ShipConfiguration(
            phpVersion: $this->detection->getRecommendedPhpVersion(),
            database: $database,
            phpExtensions: $phpExtensions,
            hasFrontend: $this->detection->hasFrontend && $this->detection->frontendBuildScript !== null,
            frontendBuildScript: $this->detection->frontendBuildScript ?? 'build',
            frontendPackageManager: $this->detection->frontendPackageManager,
            frontendLockfile: $this->detection->frontendLockfile,
            healthcheck: $this->detection->healthcheckEndpoint !== null,
            healthcheckEndpoint: $this->detection->healthcheckEndpoint ?? '/up',
            imageName: $this->projectName(),
        );
    }

    protected function gatherDatabaseDriver(): string
    {
        if ($this->detection->database) {
            return $this->detection->getNormalizedDatabase();
        }

        return select(
            label: 'Which database driver does your application use?',
            options: [
                'pgsql'  => 'PostgreSQL',
                'mysql'  => 'MySQL / MariaDB',
                'sqlite' => 'SQLite',
                'none'   => 'None (API without database)',
            ],
            default: 'pgsql',
        );
    }

    /**
     * @return array<int, string>
     */
    protected function gatherPhpExtensions(): array
    {
        $options = [
            'bcmath'  => 'BCMath',
            'gmp'     => 'GMP',
            'imagick' => 'Imagick (PECL)',
            'intl'    => 'Internationalization',
            'ldap'    => 'LDAP',
            'mysqli'  => 'MySQLi',
            'redis'   => 'Redis (PECL)',
            'soap'    => 'SOAP',
            'swoole'  => 'Swoole (PECL)',
            'zip'     => 'ZIP',
        ];

        foreach ($this->detection->phpExtensions as $extension) {
            if (! isset($options[$extension])) {
                $options[$extension] = "Detected: {$extension}";
            }
        }

        $selected = multiselect(
            label: 'Which additional PHP extensions should Ship install?',
            options: $options,
            default: array_values(array_intersect(array_keys($options), $this->detection->phpExtensions)),
            hint: 'Detected Composer extensions are preselected.',
        );
        $additional = text(
            label: 'Any other PHP extensions? (comma-separated, optional)',
            placeholder: 'e.g. pcntl, sockets',
            default: '',
        );

        return array_values(array_unique(array_merge(
            $selected,
            array_filter(array_map('trim', explode(',', $additional))),
        )));
    }

    // =========================================================================
    // Detection Output
    // =========================================================================

    protected function outputDetectedPackages(): void
    {
        if ($this->detection->hasPackage('filament')) {
            $this->info('Detected: FilamentPHP');
        }

        if ($this->detection->hasPackage('horizon')) {
            $this->info('Detected: Laravel Horizon');
        }
    }

    // =========================================================================
    // Publishing
    // =========================================================================

    protected function publishFiles(): bool
    {
        $directories = ['.platform'];
        $this->publisher->ensureDirectoriesExist($directories);
        $this->publishingSuccessful = true;

        $confirmCallback = fn (string $path) => $this->confirm(
            basename($path).' already exists. Overwrite?',
            false
        );

        // Build and publish Dockerfile
        $this->task('Publishing Dockerfile', function () use ($confirmCallback) {
            $builder = new DockerfileBuilder($this->config);

            return $this->publisher->publish('Dockerfile', $builder->build(), $confirmCallback);
        });

        // Publish .dockerignore
        $this->task('Publishing .dockerignore', function () use ($confirmCallback) {
            return $this->publisher->publishStub('dockerignore', '.dockerignore', [], $confirmCallback);
        });

        // Publish nginx.conf
        $this->task('Publishing nginx.conf', function () use ($confirmCallback) {
            return $this->publisher->publishStub('nginx.conf', '.platform/nginx.conf', [], $confirmCallback);
        });

        // Build and publish supervisord.conf
        $this->task('Publishing supervisord.conf', function () use ($confirmCallback) {
            $builder = new SupervisorBuilder($this->config, $this->files);

            return $this->publisher->publish('.platform/supervisord.conf', $builder->build(), $confirmCallback);
        });

        $this->task('Publishing worker supervisor config', function () use ($confirmCallback) {
            $builder = new SupervisorBuilder($this->config, $this->files);

            return $this->publisher->publish('.platform/supervisord-worker.conf', $builder->buildWorker(), $confirmCallback);
        });

        // Build and publish entrypoint.sh
        $this->task('Publishing entrypoint.sh', function () use ($confirmCallback) {
            $builder = new EntrypointBuilder($this->detection, $this->config);

            return $this->publisher->publish('.platform/entrypoint.sh', $builder->build(), $confirmCallback);
        });

        // Publish deploy.sh (conditional)
        if ($this->config->deployScript) {
            $this->task('Publishing deploy.sh', function () use ($confirmCallback) {
                return $this->publisher->publishStub('deploy.sh', 'deploy.sh', [
                    '{{APP_NAME}}'   => $this->config->imageName,
                    '{{IMAGE_NAME}}' => $this->config->imageName,
                ], $confirmCallback);
            });
        }

        return $this->publishingSuccessful;
    }

    // =========================================================================
    // Output Helpers
    // =========================================================================

    protected function showCompletionMessage(): void
    {
        $this->newLine();
        $this->info('Ship installation complete!');
        $this->line('');
        $this->line('Next steps:');
        $this->line('  1. Review the generated files in .platform/');

        if ($this->config->deployScript) {
            $this->line('  2. Run ./deploy.sh to start the web and worker containers');
        } else {
            $this->line('  2. Run docker build -t '.$this->config->imageName.' .');
        }
    }

    protected function projectName(): string
    {
        return strtolower((string) preg_replace('/[^a-z0-9]+/i', '-', basename(base_path())));
    }

    /**
     * @return array<int, string>
     */
    protected function phpExtensionOptions(): array
    {
        return array_map(
            fn (string $extension): string => str_starts_with($extension, 'ext-') ? substr($extension, 4) : $extension,
            (array) $this->option('php-extension'),
        );
    }

    protected function buildImage(): bool
    {
        $image = $this->config->imageName;
        $this->newLine();
        $this->info("Building Docker image {$image}...");

        $arguments = ['docker', 'build', '-t', $image];
        if ($this->option('pull')) {
            $arguments[] = '--pull';
        }
        if ($this->option('no-cache')) {
            $arguments[] = '--no-cache';
        }
        foreach ((array) $this->option('build-arg') as $buildArgument) {
            if (! preg_match('/^[A-Za-z_][A-Za-z0-9_]*=.*/', $buildArgument)) {
                $this->error("Invalid build argument: {$buildArgument}. Expected KEY=VALUE.");

                return false;
            }

            $arguments[] = '--build-arg';
            $arguments[] = $buildArgument;
        }
        $arguments[] = base_path();

        passthru(implode(' ', array_map('escapeshellarg', $arguments)), $exitCode);

        return $exitCode === 0;
    }

    protected function task(string $description, callable $task): bool
    {
        $this->output->write("  {$description}...");

        try {
            $result = $task();

            if ($result === false) {
                $this->output->writeln(' <comment>SKIPPED</comment>');
                $this->publishingSuccessful = false;
            } else {
                $this->output->writeln(' <info>DONE</info>');
            }

            return $result !== false;
        } catch (\Throwable $e) {
            $this->output->writeln(' <error>FAILED</error>');
            $this->error($e->getMessage());
            $this->publishingSuccessful = false;

            return false;
        }
    }
}
