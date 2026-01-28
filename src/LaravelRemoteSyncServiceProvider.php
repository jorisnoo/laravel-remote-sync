<?php

namespace Noo\LaravelRemoteSync;

use Noo\LaravelRemoteSync\Commands\CleanupSnapshotsCommand;
use Noo\LaravelRemoteSync\Commands\PullDatabaseCommand;
use Noo\LaravelRemoteSync\Commands\PullFilesCommand;
use Noo\LaravelRemoteSync\Commands\PullRemoteCommand;
use Noo\LaravelRemoteSync\Commands\PushDatabaseCommand;
use Noo\LaravelRemoteSync\Commands\PushFilesCommand;
use Noo\LaravelRemoteSync\Commands\PushRemoteCommand;
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
                PullRemoteCommand::class,
                PullDatabaseCommand::class,
                PullFilesCommand::class,
                PushRemoteCommand::class,
                PushDatabaseCommand::class,
                PushFilesCommand::class,
                CleanupSnapshotsCommand::class,
            ]);
    }

    public function packageRegistered(): void
    {
        $this->app->singleton(RemoteSyncService::class);
    }
}
