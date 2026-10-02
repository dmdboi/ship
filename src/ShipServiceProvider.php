<?php

namespace Dmdboi\Ship;

use Dmdboi\Ship\Commands\ShipCommand;
use Spatie\LaravelPackageTools\Package;
use Spatie\LaravelPackageTools\PackageServiceProvider;

class ShipServiceProvider extends PackageServiceProvider
{
    public function configurePackage(Package $package): void
    {
        $package
            ->name('ship')
            ->hasCommand(ShipCommand::class);
    }

    public function boot(): void
    {
        parent::boot();

        $this->warnIfNotDevEnvironment();
    }

    /**
     * Warn if the package is loaded in a non-local environment.
     */
    protected function warnIfNotDevEnvironment(): void
    {
        if ($this->app->environment('production')) {
            $this->app->make('log')->warning(
                'Ship package is loaded in production. This package should only be installed as a dev dependency. '
                .'Run "composer install --no-dev" in production to exclude it.'
            );
        }
    }
}
