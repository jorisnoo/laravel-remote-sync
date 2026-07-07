<?php

namespace Noo\LaravelRemoteSync\Tests;

use Noo\LaravelRemoteSync\LaravelRemoteSyncServiceProvider;
use Orchestra\Testbench\TestCase as Orchestra;
use Spatie\DbSnapshots\DbSnapshotsServiceProvider;

class TestCase extends Orchestra
{
    protected function getPackageProviders($app)
    {
        return [
            LaravelRemoteSyncServiceProvider::class,
            DbSnapshotsServiceProvider::class,
        ];
    }

    public function getEnvironmentSetUp($app)
    {
        config()->set('database.default', 'testing');
        config()->set('database.connections.testing', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
        ]);

        config()->set('filesystems.disks.snapshots', [
            'driver' => 'local',
            'root' => storage_path('snapshots'),
        ]);
    }
}
