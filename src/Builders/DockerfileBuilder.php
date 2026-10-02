<?php

namespace Dmdboi\Ship\Builders;

use Dmdboi\Ship\Config\ShipConfiguration;

/**
 * Builds the Dockerfile content based on configuration.
 */
class DockerfileBuilder
{
    public function __construct(
        protected ShipConfiguration $config,
    ) {}

    /**
     * Build the complete Dockerfile content.
     */
    public function build(): string
    {
        $lines = [];

        // Frontend stage (conditional)
        if ($this->config->hasFrontend) {
            $lines = array_merge($lines, $this->buildFrontendStage());
        }

        // Composer stage
        $lines = array_merge($lines, $this->buildComposerStage());

        // Runtime stage
        $lines = array_merge($lines, $this->buildRuntimeStage());

        return implode("\n", $lines);
    }

    /**
     * Build the frontend stage.
     *
     * @return array<int, string>
     */
    protected function buildFrontendStage(): array
    {
        $buildScript = $this->config->frontendBuildScript;
        $lockfile    = $this->config->frontendLockfile;
        $install     = match ($this->config->frontendPackageManager) {
            'pnpm'  => 'corepack enable && pnpm install --frozen-lockfile',
            'yarn'  => 'corepack enable && yarn install --immutable',
            default => 'npm ci',
        };

        return [
            '# =============================================================================',
            '# Stage 1: Build frontend assets',
            '# =============================================================================',
            'FROM node:22-alpine AS frontend',
            '',
            'WORKDIR /app',
            '',
            '# Copy package files first for better layer caching',
            $lockfile ? "COPY package.json {$lockfile} ./" : 'COPY package.json ./',
            $lockfile ? "RUN {$install} --ignore-scripts" : 'RUN npm install --ignore-scripts',
            '',
            '# Copy source files needed for build',
            'COPY resources ./resources',
            'COPY public ./public',
            'COPY vite.config.js ./',
            '',
            "RUN npm run {$buildScript}",
            '',
        ];
    }

    /**
     * Build the composer stage.
     *
     * @return array<int, string>
     */
    protected function buildComposerStage(): array
    {
        $stageNumber = $this->config->hasFrontend ? 2 : 1;

        return [
            '# =============================================================================',
            "# Stage {$stageNumber}: Install PHP dependencies",
            '# =============================================================================',
            'FROM composer:2 AS composer',
            '',
            'WORKDIR /app',
            '',
            '# Copy composer files',
            'COPY composer.json composer.lock ./',
            '',
            '# Install dependencies (no dev for production)',
            'RUN composer install \\',
            '    --no-dev \\',
            '    --no-interaction \\',
            '    --no-scripts \\',
            '    --no-autoloader \\',
            '    --prefer-dist \\',
            '    --ignore-platform-reqs',
            '',
            '# Copy application code for autoloader optimization',
            'COPY . .',
            '',
            '# Generate optimized autoloader',
            'RUN composer dump-autoload \\',
            '    --no-dev \\',
            '    --optimize \\',
            '    --classmap-authoritative',
            '',
        ];
    }

    /**
     * Build the runtime stage.
     *
     * @return array<int, string>
     */
    protected function buildRuntimeStage(): array
    {
        $phpVersion        = $this->config->phpVersion;
        $stageNumber       = $this->config->hasFrontend ? 3 : 2;
        $dbExtensions      = $this->getDatabaseExtensions();
        $phpExtensions     = $this->getPhpExtensions($dbExtensions);
        $peclExtensions    = $this->getPeclExtensions();
        $buildDeps         = $this->getDatabaseBuildDeps();
        $runtimeDeps       = $this->getDatabaseRuntimeDeps();

        $lines = [
            '# =============================================================================',
            "# Stage {$stageNumber}: Production runtime image",
            '# =============================================================================',
            "FROM php:{$phpVersion}-fpm-alpine AS runtime",
            '',
            '# Create the unprivileged runtime user',
            'RUN addgroup -g 1001 -S appuser && adduser -u 1001 -S -G appuser appuser',
            '',
        ];

        // Install dependencies
        $lines = array_merge($lines, $this->buildDependencyInstallation($phpExtensions, $peclExtensions, $buildDeps, $runtimeDeps));

        // PHP configuration
        $lines = array_merge($lines, $this->buildPhpConfiguration());

        // Workdir and config files
        $lines = array_merge($lines, $this->buildWorkdirAndConfig());

        // Copy application
        $lines = array_merge($lines, $this->buildApplicationCopy());

        // Permissions and cleanup
        $lines = array_merge($lines, $this->buildPermissionsAndCleanup());

        // Healthcheck (conditional)
        if ($this->config->healthcheck) {
            $lines = array_merge($lines, $this->buildHealthcheck());
        }

        // Expose and entrypoint
        $lines[] = 'EXPOSE 8080';
        $lines[] = '';
        $lines[] = 'ENTRYPOINT ["/usr/local/bin/entrypoint.sh"]';
        $lines[] = '';

        return $lines;
    }

    /**
     * Build the dependency installation block.
     *
     * @param  array<int, string>  $phpExtensions
     * @param  array<int, string>  $peclExtensions
     * @param  array<int, string>  $buildDeps
     * @param  array<int, string>  $runtimeDeps
     * @return array<int, string>
     */
    protected function buildDependencyInstallation(array $phpExtensions, array $peclExtensions, array $buildDeps, array $runtimeDeps): array
    {
        $lines = [
            '# Install runtime dependencies and PHP extensions',
            'RUN apk add --no-cache --virtual .build-deps \\',
            '        libpng-dev \\',
            '        libzip-dev \\',
        ];

        foreach (array_merge($buildDeps, $this->getPeclBuildDependencies($peclExtensions), $peclExtensions ? ['autoconf', 'g++', 'make'] : []) as $dep) {
            $lines[] = "        {$dep} \\";
        }

        $lines[] = '        icu-dev \\';
        $lines[] = '        libexif-dev \\';
        $lines[] = '        linux-headers \\';
        $lines[] = '    && apk add --no-cache \\';
        $lines[] = '        nginx \\';
        $lines[] = '        supervisor \\';
        $lines[] = '        wget \\';
        $lines[] = '        libpng \\';
        $lines[] = '        libzip \\';

        foreach ($runtimeDeps as $dep) {
            $lines[] = "        {$dep} \\";
        }

        foreach ($this->getPeclRuntimeDependencies($peclExtensions) as $dep) {
            $lines[] = "        {$dep} \\";
        }

        $lines[] = '        icu-libs \\';
        $lines[] = '        libexif \\';
        $lines[] = '    && docker-php-ext-install -j$(nproc) \\';

        foreach ($phpExtensions as $extension) {
            $lines[] = '        '.$extension.' \\';
        }

        $lines[] = '    && docker-php-ext-enable opcache \\';

        if ($peclExtensions) {
            $lines[] = '    && pecl install '.implode(' ', $peclExtensions).' \\';
            $lines[] = '    && docker-php-ext-enable '.implode(' ', $peclExtensions).' \\';
        }

        $lines[] = '    && apk del .build-deps \\';
        $lines[] = '    && rm -rf /var/cache/apk/* /tmp/*';
        $lines[] = '';

        return $lines;
    }

    /**
     * Build PHP configuration block.
     *
     * @return array<int, string>
     */
    protected function buildPhpConfiguration(): array
    {
        return [
            '# Configure PHP for production',
            'RUN mv "$PHP_INI_DIR/php.ini-production" "$PHP_INI_DIR/php.ini"',
            '',
            '# Configure OPcache for production',
            'RUN echo \'opcache.memory_consumption=256\' >> "$PHP_INI_DIR/conf.d/opcache.ini" \\',
            '    && echo \'opcache.interned_strings_buffer=16\' >> "$PHP_INI_DIR/conf.d/opcache.ini" \\',
            '    && echo \'opcache.max_accelerated_files=20000\' >> "$PHP_INI_DIR/conf.d/opcache.ini" \\',
            '    && echo \'opcache.validate_timestamps=0\' >> "$PHP_INI_DIR/conf.d/opcache.ini" \\',
            '    && echo \'opcache.enable_cli=1\' >> "$PHP_INI_DIR/conf.d/opcache.ini"',
            '',
        ];
    }

    /**
     * Build workdir and config files block.
     *
     * @return array<int, string>
     */
    protected function buildWorkdirAndConfig(): array
    {
        return [
            'WORKDIR /var/www/html',
            '',
            '# Copy config files',
            'COPY .platform/nginx.conf /etc/nginx/http.d/default.conf',
            'COPY .platform/supervisord.conf /etc/supervisor/conf.d/supervisord.conf',
            'COPY .platform/supervisord-worker.conf /etc/supervisor/conf.d/supervisord-worker.conf',
            'COPY .platform/entrypoint.sh /usr/local/bin/entrypoint.sh',
            'RUN chmod +x /usr/local/bin/entrypoint.sh',
            'RUN sed -i "s/^user = .*/user = appuser/; s/^group = .*/group = appuser/" /usr/local/etc/php-fpm.d/www.conf',
            '',
        ];
    }

    /**
     * Build application copy block.
     *
     * @return array<int, string>
     */
    protected function buildApplicationCopy(): array
    {
        $lines = [
            '# Copy application code',
            'COPY --chown=appuser:appuser . .',
            '',
            '# Copy vendor directory from composer stage',
            'COPY --from=composer --chown=appuser:appuser /app/vendor ./vendor',
            '',
        ];

        if ($this->config->hasFrontend) {
            $lines[] = '# Copy built assets from frontend stage';
            $lines[] = 'COPY --from=frontend --chown=appuser:appuser /app/public/build ./public/build';
            $lines[] = '';
        }

        return $lines;
    }

    /**
     * Build permissions and cleanup block.
     *
     * @return array<int, string>
     */
    protected function buildPermissionsAndCleanup(): array
    {
        return [
            '# Set permissions',
            'RUN chmod -R 755 storage bootstrap/cache \\',
            '    && mkdir -p /var/log/nginx /var/lib/nginx /run/nginx /var/log/supervisor \\',
            '    && chown -R appuser:appuser /var/www/html /var/log/nginx /var/lib/nginx /run/nginx /var/log/supervisor',
            '',
            '# Remove unnecessary files',
            'RUN rm -rf \\',
            '    .git \\',
            '    .github \\',
            '    tests \\',
            '    node_modules \\',
            '    .env.example \\',
            '    README.md \\',
            '    phpunit.xml \\',
            '    vite.config.js \\',
            '    tailwind.config.js \\',
            '    postcss.config.js \\',
            '    package.json \\',
            '    package-lock.json \\',
            '    *.config.js \\',
            '    *.config.ts',
            '',
            '# Run the complete container as an unprivileged user',
            'USER appuser',
            '',
        ];
    }

    /**
     * Build healthcheck block.
     *
     * @return array<int, string>
     */
    protected function buildHealthcheck(): array
    {
        $endpoint = $this->config->healthcheckEndpoint;

        return [
            '# Healthcheck',
            'HEALTHCHECK --interval=30s --timeout=5s --start-period=5s --retries=3 \\',
            '    CMD sh -c \'if [ "${CONTAINER_ROLE:-web}" = "worker" ]; then supervisorctl -c /etc/supervisor/conf.d/supervisord-worker.conf status queue:* | grep -q RUNNING; else wget --quiet --tries=1 --spider http://localhost:8080'.$endpoint.' || exit 1; fi\'',
            '',
        ];
    }

    /**
     * Get database extensions for the configured driver.
     *
     * @return array<int, string>
     */
    protected function getDatabaseExtensions(): array
    {
        return match ($this->config->database) {
            'pgsql'   => ['pdo_pgsql', 'pgsql'],
            'mysql'   => ['pdo_mysql', 'mysqli'],
            'sqlite'  => ['pdo_sqlite'],
            'none'    => [],
            default   => ['pdo_pgsql', 'pgsql'],
        };
    }

    /**
     * @param  array<int, string>  $databaseExtensions
     * @return array<int, string>
     */
    protected function getPhpExtensions(array $databaseExtensions): array
    {
        return array_values(array_unique(array_merge(
            $databaseExtensions,
            ['gd', 'zip', 'pcntl', 'intl', 'exif', 'sockets', 'opcache'],
            array_filter(
                $this->normalizedPhpExtensions(),
                fn (string $extension): bool => ! in_array($extension, $this->getPeclExtensionNames(), true),
            ),
        )));
    }

    /**
     * @return array<int, string>
     */
    protected function getPeclExtensions(): array
    {
        return array_values(array_intersect($this->getPeclExtensionNames(), $this->normalizedPhpExtensions()));
    }

    /**
     * @return array<int, string>
     */
    protected function normalizedPhpExtensions(): array
    {
        return array_values(array_unique(array_map(
            fn (string $extension): string => str_starts_with($extension, 'ext-') ? substr($extension, 4) : $extension,
            $this->config->phpExtensions,
        )));
    }

    /**
     * @return array<int, string>
     */
    protected function getPeclExtensionNames(): array
    {
        return ['redis', 'imagick'];
    }

    /**
     * @param  array<int, string>  $extensions
     * @return array<int, string>
     */
    protected function getPeclBuildDependencies(array $extensions): array
    {
        return in_array('imagick', $extensions, true) ? ['imagemagick-dev'] : [];
    }

    /**
     * @param  array<int, string>  $extensions
     * @return array<int, string>
     */
    protected function getPeclRuntimeDependencies(array $extensions): array
    {
        return in_array('imagick', $extensions, true) ? ['imagemagick'] : [];
    }

    /**
     * Get build dependencies for the configured database driver.
     *
     * @return array<int, string>
     */
    protected function getDatabaseBuildDeps(): array
    {
        return match ($this->config->database) {
            'pgsql'   => ['postgresql-dev'],
            'mysql'   => ['mysql-dev'],
            'sqlite'  => ['sqlite-dev'],
            'none'    => [],
            default   => ['postgresql-dev'],
        };
    }

    /**
     * Get runtime dependencies for the configured database driver.
     *
     * @return array<int, string>
     */
    protected function getDatabaseRuntimeDeps(): array
    {
        return match ($this->config->database) {
            'pgsql'   => ['postgresql-libs'],
            'mysql'   => ['mysql-client'],
            'sqlite'  => ['sqlite-libs'],
            'none'    => [],
            default   => ['postgresql-libs'],
        };
    }
}
