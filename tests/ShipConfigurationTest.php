<?php

use Dmdboi\Ship\Builders\DockerfileBuilder;
use Dmdboi\Ship\Builders\EntrypointBuilder;
use Dmdboi\Ship\Builders\SupervisorBuilder;
use Dmdboi\Ship\Config\ShipConfiguration;
use Dmdboi\Ship\Detectors\DetectionResult;
use Dmdboi\Ship\Publishers\FilePublisher;
use Illuminate\Filesystem\Filesystem;

it('builds a worker-aware entrypoint', function () {
    $entrypoint = (new EntrypointBuilder(new DetectionResult, new ShipConfiguration))->build();

    expect($entrypoint)
        ->toContain('CONTAINER_ROLE')
        ->toContain('supervisord-worker.conf')
        ->toContain('supervisord');
});

it('builds a non-root runtime with a working healthcheck', function () {
    $dockerfile = (new DockerfileBuilder(new ShipConfiguration(
        healthcheck: true,
        healthcheckEndpoint: '/up',
    )))->build();

    expect($dockerfile)
        ->toContain('USER appuser')
        ->toContain('COPY .platform/nginx.conf')
        ->toContain('wget')
        ->toContain('supervisorctl')
        ->toContain('EXPOSE 8080')
        ->toContain('localhost:8080/up');

    expect((new DockerfileBuilder(new ShipConfiguration(phpExtensions: ['redis', 'imagick'])))->build())
        ->toContain('pecl install redis imagick')
        ->toContain('imagemagick-dev')
        ->toContain('imagemagick');

    expect((new FilePublisher(new Filesystem, sys_get_temp_dir(), true, true))->getStub('nginx.conf'))
        ->toContain('listen 8080')
        ->not->toContain('pid ');
});

it('generates worker health checks for every configured worker process', function () {
    $filesystem    = new Filesystem;
    $configuration = new ShipConfiguration(
        workerQueue: 'notifications',
        workerProcesses: 2,
        workerTries: 5,
        workerTimeout: 180,
    );

    $workerConfig = (new SupervisorBuilder($configuration, $filesystem))->buildWorker();

    expect($workerConfig)
        ->toContain('--queue=notifications')
        ->toContain('--tries=5')
        ->toContain('--timeout=180')
        ->toContain('numprocs=2')
        ->toContain('supervisor-worker.sock')
        ->toContain('[rpcinterface:supervisor]');
});

it('supports frontend projects without a lockfile', function () {
    $dockerfile = (new DockerfileBuilder(new ShipConfiguration(
        hasFrontend: true,
        frontendLockfile: null,
    )))->build();

    expect($dockerfile)
        ->toContain('COPY package.json ./')
        ->toContain('RUN npm install --ignore-scripts')
        ->not->toContain('COPY package.json package-lock.json ./');
});

it('publishes an executable generic deploy script with the project image name', function () {
    $filesystem = new Filesystem;
    $basePath   = sys_get_temp_dir().'/ship-'.uniqid();
    $publisher  = new FilePublisher($filesystem, $basePath, true, true);

    $publisher->publishStub('deploy.sh', 'deploy.sh', [
        '{{APP_NAME}}'   => 'example',
        '{{IMAGE_NAME}}' => 'example',
    ]);

    expect($filesystem->get($basePath.'/deploy.sh'))
        ->toContain('IMAGE="${SHIP_IMAGE:-example}"')
        ->toContain('--name "$new_web"')
        ->toContain('--name "$new_worker"')
        ->toContain('CONTAINER_ROLE=worker');

    expect(fileperms($basePath.'/deploy.sh') & 0111)->toBeGreaterThan(0);

    $filesystem->deleteDirectory($basePath);
});

it('uses the project name as the default image name', function () {
    $configuration = ShipConfiguration::fromArray([
        'image_name' => 'example',
    ]);

    expect($configuration->imageName)->toBe('example');
});
