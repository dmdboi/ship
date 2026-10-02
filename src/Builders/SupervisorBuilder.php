<?php

namespace Dmdboi\Ship\Builders;

use Dmdboi\Ship\Config\ShipConfiguration;
use Illuminate\Filesystem\Filesystem;

/**
 * Builds the supervisord.conf content based on configuration.
 */
class SupervisorBuilder
{
    public function __construct(
        protected ShipConfiguration $config,
        protected Filesystem $files,
    ) {}

    /**
     * Build the complete supervisord.conf content.
     */
    public function build(): string
    {
        $config = $this->getStub('supervisord-base.conf');

        if ($this->config->scheduler) {
            $config .= "\n".$this->getStub('supervisord-scheduler.conf');
        }

        return $config;
    }

    public function buildWorker(): string
    {
        return str_replace([
            '{{WORKER_QUEUE}}',
            '{{WORKER_PROCESSES}}',
            '{{WORKER_TRIES}}',
            '{{WORKER_TIMEOUT}}',
        ], [
            $this->config->workerQueue,
            (string) $this->config->workerProcesses,
            (string) $this->config->workerTries,
            (string) $this->config->workerTimeout,
        ], $this->getStub('supervisord-worker.conf'));
    }

    /**
     * Get stub content by name.
     */
    protected function getStub(string $name): string
    {
        $path = __DIR__.'/../../stubs/'.$name.'.stub';

        if (! $this->files->exists($path)) {
            throw new \RuntimeException("Stub file not found: {$name}");
        }

        return $this->files->get($path);
    }
}
