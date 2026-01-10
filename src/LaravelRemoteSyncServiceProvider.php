<?php

namespace Noo\LaravelRemoteSync;

use Spatie\LaravelPackageTools\Package;
use Spatie\LaravelPackageTools\PackageServiceProvider;
use Noo\LaravelRemoteSync\Commands\LaravelRemoteSyncCommand;

class LaravelRemoteSyncServiceProvider extends PackageServiceProvider
{
    public function configurePackage(Package $package): void
    {
        /*
         * This class is a Package Service Provider
         *
         * More info: https://github.com/spatie/laravel-package-tools
         */
        $package
            ->name('laravel-remote-sync')
            ->hasConfigFile()
            ->hasViews()
            ->hasMigration('create_laravel_remote_sync_table')
            ->hasCommand(LaravelRemoteSyncCommand::class);
    }
}
