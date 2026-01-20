<?php

namespace Noo\LaravelRemoteSync\Tests;

use Illuminate\Database\Eloquent\Factories\Factory;
use Noo\LaravelRemoteSync\LaravelRemoteSyncServiceProvider;
use Orchestra\Testbench\TestCase as Orchestra;
use Spatie\DbSnapshots\DbSnapshotsServiceProvider;

class TestCase extends Orchestra
{
    protected function setUp(): void
    {
        parent::setUp();

        Factory::guessFactoryNamesUsing(
            fn (string $modelName) => 'Noo\\LaravelRemoteSync\\Database\\Factories\\'.class_basename($modelName).'Factory'
        );
    }

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
    }

    protected function setUpRemoteConfig(array $remotes = [], ?string $default = null): void
    {
        config()->set('remote-sync.remotes', $remotes);

        if ($default !== null) {
            config()->set('remote-sync.default', $default);
        }
    }

    protected function setUpProductionRemote(): void
    {
        $this->setUpRemoteConfig([
            'production' => [
                'host' => 'user@production.example.com',
                'path' => '/var/www/app',
                'push_allowed' => false,
            ],
        ], 'production');
    }

    protected function setUpStagingRemote(): void
    {
        $this->setUpRemoteConfig([
            'staging' => [
                'host' => 'user@staging.example.com',
                'path' => '/var/www/app',
                'push_allowed' => true,
            ],
        ], 'staging');
    }

    protected function setUpMultipleRemotes(): void
    {
        $this->setUpRemoteConfig([
            'production' => [
                'host' => 'user@production.example.com',
                'path' => '/var/www/app',
                'push_allowed' => false,
            ],
            'staging' => [
                'host' => 'user@staging.example.com',
                'path' => '/var/www/app',
                'push_allowed' => true,
            ],
        ], 'production');
    }
}
