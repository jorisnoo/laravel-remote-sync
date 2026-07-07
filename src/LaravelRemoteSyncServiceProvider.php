<?php

namespace Noo\LaravelRemoteSync;

use Noo\LaravelRemoteSync\Commands\DoctorCommand;
use Noo\LaravelRemoteSync\Commands\PruneCommand;
use Noo\LaravelRemoteSync\Commands\PullCommand;
use Noo\LaravelRemoteSync\Commands\PushCommand;
use Spatie\LaravelPackageTools\Package;
use Spatie\LaravelPackageTools\PackageServiceProvider;

class LaravelRemoteSyncServiceProvider extends PackageServiceProvider
{
    public function configurePackage(Package $package): void
    {
        $package
            ->name('remote-sync')
            ->hasConfigFile()
            ->hasTranslations()
            ->hasCommands([
                PullCommand::class,
                PushCommand::class,
                PruneCommand::class,
                DoctorCommand::class,
            ]);
    }
}
