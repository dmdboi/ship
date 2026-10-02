<?php

namespace Dmdboi\Ship\Builders;

use Dmdboi\Ship\Config\ShipConfiguration;
use Dmdboi\Ship\Detectors\DetectionResult;

/**
 * Builds the entrypoint.sh content based on configuration and detection.
 */
class EntrypointBuilder
{
    public function __construct(
        protected DetectionResult $detection,
        protected ShipConfiguration $config,
    ) {}

    /**
     * Build the complete entrypoint.sh content.
     */
    public function build(): string
    {
        $lines = [
            '#!/bin/sh',
            'set -e',
            '',
            'mkdir -p /var/log/supervisor',
            '',
            'if [ "${CONTAINER_ROLE:-web}" = "worker" ]; then',
            '  exec /usr/bin/supervisord -c /etc/supervisor/conf.d/supervisord-worker.conf',
            'fi',
            '',
            'if [ "${SHIP_RUN_MIGRATIONS:-true}" = "true" ]; then',
            '  php artisan migrate --force',
            'fi',
            '',
            'if [ "$APP_ENV" = "production" ]; then',
            '  php artisan optimize',
        ];

        // Add Filament optimization if detected
        if ($this->detection->hasPackage('filament')) {
            $lines[] = '  php artisan filament:optimize';
        }

        $lines = array_merge($lines, [
            'fi',
            '',
            'php artisan storage:link || true',
            '',
            'exec /usr/bin/supervisord -c /etc/supervisor/conf.d/supervisord.conf',
            '',
        ]);

        return implode("\n", $lines);
    }
}
