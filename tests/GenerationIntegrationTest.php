<?php

use Dmdboi\Ship\Builders\DockerfileBuilder;
use Dmdboi\Ship\Builders\EntrypointBuilder;
use Dmdboi\Ship\Builders\SupervisorBuilder;
use Dmdboi\Ship\Config\ShipConfiguration;
use Dmdboi\Ship\Detectors\EnvironmentDetector;
use Dmdboi\Ship\Publishers\FilePublisher;
use Illuminate\Filesystem\Filesystem;

it('generates a complete deployment setup from a Laravel fixture', function () {
    $filesystem = new Filesystem;
    $basePath   = sys_get_temp_dir().'/ship-fixture-'.uniqid();
    $filesystem->copyDirectory(__DIR__.'/fixtures/laravel', $basePath);

    $detection     = (new EnvironmentDetector($filesystem, $basePath))->detect();
    $configuration = new ShipConfiguration(
        phpVersion: $detection->getRecommendedPhpVersion(),
        database: $detection->getNormalizedDatabase(),
        phpExtensions: $detection->phpExtensions,
        hasFrontend: $detection->hasFrontend,
        frontendBuildScript: $detection->frontendBuildScript ?? 'build',
        frontendPackageManager: $detection->frontendPackageManager,
        frontendLockfile: $detection->frontendLockfile,
        healthcheck: $detection->healthcheckEndpoint !== null,
        healthcheckEndpoint: $detection->healthcheckEndpoint ?? '/up',
        imageName: 'fixture',
    );
    $publisher = new FilePublisher($filesystem, $basePath, true, true);

    $publisher->ensureDirectoriesExist(['.platform']);
    $publisher->publish('Dockerfile', (new DockerfileBuilder($configuration))->build());
    $publisher->publishStub('dockerignore', '.dockerignore');
    $publisher->publishStub('nginx.conf', '.platform/nginx.conf');
    $publisher->publish('.platform/supervisord.conf', (new SupervisorBuilder($configuration, $filesystem))->build());
    $publisher->publish('.platform/supervisord-worker.conf', (new SupervisorBuilder($configuration, $filesystem))->buildWorker());
    $publisher->publish('.platform/entrypoint.sh', (new EntrypointBuilder($detection, $configuration))->build());
    $publisher->publishStub('deploy.sh', 'deploy.sh', [
        '{{APP_NAME}}'   => 'fixture',
        '{{IMAGE_NAME}}' => 'fixture',
    ]);

    expect($detection->frontendPackageManager)->toBe('pnpm')
        ->and($detection->getNormalizedDatabase())->toBe('mysql')
        ->and($detection->phpExtensions)->toContain('redis')
        ->and($detection->healthcheckEndpoint)->toBe('/health')
        ->and($filesystem->files($basePath.'/.platform'))->toHaveCount(4)
        ->and($filesystem->exists($basePath.'/Dockerfile'))->toBeTrue()
        ->and($filesystem->get($basePath.'/Dockerfile'))
        ->toContain('pnpm install --frozen-lockfile')
        ->toContain('pecl install redis')
        ->and($filesystem->get($basePath.'/.platform/supervisord-worker.conf'))->toContain('--queue=default')
        ->and($filesystem->get($basePath.'/deploy.sh'))->toContain('fixture');

    $filesystem->deleteDirectory($basePath);
});
