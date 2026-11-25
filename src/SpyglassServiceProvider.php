<?php

namespace Keepsuit\Spyglass;

use Spatie\LaravelPackageTools\Package;
use Spatie\LaravelPackageTools\PackageServiceProvider;

class SpyglassServiceProvider extends PackageServiceProvider
{
    public function configurePackage(Package $package): void
    {
        $package
            ->name('laravel-spyglass')
            ->hasConfigFile();
    }
}
