<?php

namespace Noo\LaravelRemoteSync;

use Noo\LaravelRemoteSync\Commands\CleanupSnapshotsCommand;
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
                CleanupSnapshotsCommand::class,
            ]);
    }

    public function packageRegistered(): void
    {
        $this->app->singleton(RemoteSyncService::class);
    }
}
