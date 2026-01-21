<?php

namespace Noo\LaravelRemoteSync;

use Noo\LaravelRemoteSync\Commands\CleanupSnapshotsCommand;
use Noo\LaravelRemoteSync\Commands\PushDatabaseCommand;
use Noo\LaravelRemoteSync\Commands\PushFilesCommand;
use Noo\LaravelRemoteSync\Commands\PushRemoteCommand;
use Noo\LaravelRemoteSync\Commands\SyncDatabaseCommand;
use Noo\LaravelRemoteSync\Commands\SyncFilesCommand;
use Noo\LaravelRemoteSync\Commands\SyncRemoteCommand;
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
                SyncRemoteCommand::class,
                SyncDatabaseCommand::class,
                SyncFilesCommand::class,
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
