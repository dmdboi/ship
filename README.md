# Ship

[![Latest Version on Packagist](https://img.shields.io/packagist/v/dmdboi/ship.svg?style=flat-square)](https://packagist.org/packages/dmdboi/ship)
[![GitHub Tests Action Status](https://img.shields.io/github/actions/workflow/status/dmdboi/ship/run-tests.yml?branch=main&label=tests&style=flat-square)](https://github.com/dmdboi/ship/actions?query=workflow%3Arun-tests+branch%3Amain)
[![GitHub Code Style Action Status](https://img.shields.io/github/actions/workflow/status/dmdboi/ship/fix-php-code-style-issues.yml?branch=main&label=code%20style&style=flat-square)](https://github.com/dmdboi/ship/actions?query=workflow%3A"Fix+PHP+code+style+issues"+branch%3Amain)
[![Total Downloads](https://img.shields.io/packagist/dt/dmdboi/ship.svg?style=flat-square)](https://packagist.org/packages/dmdboi/ship)

Ship Laravel in a container. Generate production-ready Docker configuration files for your Laravel application with a single command.

## Installation

Install the package as a **dev dependency** via composer:

```bash
composer require dmdboi/ship --dev
```

> **Note:** This package is intended for development use only. It generates configuration files that are committed to your repository, so the package itself doesn't need to be installed in production. When deploying, use `composer install --no-dev` to exclude it.

## Usage

Run the install command to generate Docker configuration files:

```bash
php artisan ship:install
```

### Options

- `--defaults` - Use detected defaults without interactive prompts (great for CI/CD)
- `--force` - Overwrite existing files without confirmation
- `--no-build` - Generate configuration without building the Docker image
- `--pull` - Pull newer Docker base images during the build
- `--no-cache` - Disable Docker build caching
- `--build-arg KEY=VALUE` - Pass a build argument to Docker (repeatable)
- `--php-extension NAME` - Add a PHP extension to the generated image (repeatable)

```bash
# Interactive mode (default)
php artisan ship:install

# Use detected defaults
php artisan ship:install --defaults

# Build with fresh base images and no cache
php artisan ship:install --defaults --pull --no-cache
```

### What Gets Generated

- `Dockerfile` - Multi-stage build with PHP-FPM, Nginx, and Supervisor
- `.dockerignore` - Optimized ignore patterns
- `.platform/nginx.conf` - Nginx configuration
- `.platform/supervisord.conf` - Supervisor configuration for the web container
- `.platform/supervisord-worker.conf` - Configurable queue worker processes
- `.platform/entrypoint.sh` - Container entrypoint script
- `deploy.sh` - Generic script for starting web and worker containers (optional)

The command builds the image using the project directory name as the image name.
Run `./deploy.sh` afterwards to replace the `-web` and `-worker` containers.

### Detection

Ship automatically detects:

- PHP version from `composer.json`
- Database driver from `.env` or `config/database.php`
- Frontend build setup from `package.json`
- Health check endpoints from routes
- Installed packages (FilamentPHP, Horizon)
- PHP extensions declared in Composer (`ext-*` requirements)

Redis and Imagick are installed through PECL when requested or detected. Other
extensions are installed with `docker-php-ext-install`.

Interactive installs use a multiselect prompt with detected Composer extensions
preselected, followed by an optional field for custom extensions. The
`--php-extension` option remains available for scripts and CI.

## Testing

```bash
composer test
```

## Changelog

Please see [CHANGELOG](CHANGELOG.md) for more information on what has changed recently.

## Contributing

Please see [CONTRIBUTING](CONTRIBUTING.md) for details.

## Credits

- [dmdboi](https://github.com/dmdboi)
- [All Contributors](../../contributors)

## License

The MIT License (MIT). Please see [License File](LICENSE.md) for more information.
