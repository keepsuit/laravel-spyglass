<?php

namespace Keepsuit\Spyglass;

use Keepsuit\Spyglass\Commands\SpyglassCommand;
use Spatie\LaravelPackageTools\Package;
use Spatie\LaravelPackageTools\PackageServiceProvider;

class SpyglassServiceProvider extends PackageServiceProvider
{
    public function configurePackage(Package $package): void
    {
        /*
         * This class is a Package Service Provider
         *
         * More info: https://github.com/spatie/laravel-package-tools
         */
        $package
            ->name('laravel-spyglass')
            ->hasConfigFile()
            ->hasViews()
            ->hasMigration('create_laravel_spyglass_table')
            ->hasCommand(SpyglassCommand::class);
    }
}
