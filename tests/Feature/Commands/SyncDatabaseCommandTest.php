<?php

use Illuminate\Contracts\Process\ProcessResult;
use Illuminate\Support\Facades\Process;
use Noo\LaravelRemoteSync\Data\RemoteConfig;
use Noo\LaravelRemoteSync\RemoteSyncService;

beforeEach(function () {
    Process::fake([
        '*' => Process::result(output: 'no'),
    ]);
});

describe('SyncDatabaseCommand', function () {
    it('refuses to run in production environment', function () {
        app()->detectEnvironment(fn () => 'production');
        $this->setUpProductionRemote();

        $this->artisan('remote-sync:pull-database', ['remote' => 'production'])
            ->assertFailed()
            ->expectsOutputToContain('This command cannot be run in production');
    });

    it('fails when remote is not configured', function () {
        config()->set('remote-sync.remotes', []);

        $this->artisan('remote-sync:pull-database', ['remote' => 'nonexistent'])
            ->assertFailed()
            ->expectsOutputToContain("Remote 'nonexistent' is not configured");
    });

    it('fails when remote is missing host', function () {
        config()->set('remote-sync.remotes', [
            'incomplete' => ['path' => '/var/www/app'],
        ]);

        $this->artisan('remote-sync:pull-database', ['remote' => 'incomplete'])
            ->assertFailed()
            ->expectsOutputToContain('missing host or path configuration');
    });

    it('warns when database driver cannot be detected but proceeds', function () {
        $this->setUpProductionRemote();

        $mockProcessResult = Mockery::mock(ProcessResult::class);
        $mockProcessResult->shouldReceive('successful')->andReturn(true);
        $mockProcessResult->shouldReceive('output')->andReturn('');

        $this->mock(RemoteSyncService::class, function ($mock) use ($mockProcessResult) {
            $mock->shouldReceive('getRemote')
                ->andReturn(new RemoteConfig(
                    name: 'production',
                    host: 'user@production.example.com',
                    path: '/var/www/app',
                ));

            $mock->shouldReceive('isAtomicDeployment')
                ->andReturn(false);

            $mock->shouldReceive('getRemoteDatabaseDriver')
                ->once()
                ->andReturn(null);

            $mock->shouldReceive('createRemoteSnapshot')
                ->once()
                ->andReturn($mockProcessResult);

            $mock->shouldReceive('getSnapshotPath')
                ->andReturn(storage_path('snapshots'));

            $mock->shouldReceive('downloadSnapshot')
                ->once()
                ->andReturn($mockProcessResult);

            $mock->shouldReceive('deleteRemoteSnapshot')
                ->once()
                ->andReturn($mockProcessResult);
        });

        $this->artisan('remote-sync:pull-database', [
            'remote' => 'production',
            '--no-backup' => true,
            '--force' => true,
        ])
            ->expectsOutputToContain('Could not detect remote database driver')
            ->assertSuccessful();
    });

    it('fails on database driver mismatch', function () {
        $this->setUpProductionRemote();
        config()->set('database.default', 'testing');
        config()->set('database.connections.testing.driver', 'sqlite');

        $this->mock(RemoteSyncService::class, function ($mock) {
            $mock->shouldReceive('getRemote')
                ->andReturn(new RemoteConfig(
                    name: 'production',
                    host: 'user@production.example.com',
                    path: '/var/www/app',
                ));

            $mock->shouldReceive('isAtomicDeployment')
                ->andReturn(false);

            $mock->shouldReceive('getRemoteDatabaseDriver')
                ->once()
                ->andReturn('mysql');
        });

        $this->artisan('remote-sync:pull-database', ['remote' => 'production'])
            ->assertFailed()
            ->expectsOutputToContain('Database driver mismatch');
    });

    it('uses options from CLI to skip prompts', function () {
        $this->setUpProductionRemote();
        config()->set('database.connections.testing.driver', 'mysql');
        config()->set('remote-sync.exclude_tables', []);

        $mockProcessResult = Mockery::mock(ProcessResult::class);
        $mockProcessResult->shouldReceive('successful')->andReturn(true);
        $mockProcessResult->shouldReceive('output')->andReturn('');

        $this->mock(RemoteSyncService::class, function ($mock) use ($mockProcessResult) {
            $mock->shouldReceive('getRemote')
                ->andReturn(new RemoteConfig(
                    name: 'production',
                    host: 'user@production.example.com',
                    path: '/var/www/app',
                ));

            $mock->shouldReceive('isAtomicDeployment')
                ->andReturn(false);

            $mock->shouldReceive('getRemoteDatabaseDriver')
                ->andReturn('mysql');

            $mock->shouldReceive('createRemoteSnapshot')
                ->once()
                ->andReturn($mockProcessResult);

            $mock->shouldReceive('getSnapshotPath')
                ->andReturn(storage_path('snapshots'));

            $mock->shouldReceive('downloadSnapshot')
                ->once()
                ->andReturn($mockProcessResult);

            $mock->shouldReceive('deleteRemoteSnapshot')
                ->once()
                ->andReturn($mockProcessResult);
        });

        $this->artisan('remote-sync:pull-database', [
            'remote' => 'production',
            '--no-backup' => true,
            '--force' => true,
        ])
            ->assertSuccessful();
    });

    it('treats mariadb and mysql as compatible drivers', function () {
        $this->setUpProductionRemote();
        config()->set('database.connections.testing.driver', 'mariadb');
        config()->set('remote-sync.exclude_tables', []);

        $mockProcessResult = Mockery::mock(ProcessResult::class);
        $mockProcessResult->shouldReceive('successful')->andReturn(true);
        $mockProcessResult->shouldReceive('output')->andReturn('');

        $this->mock(RemoteSyncService::class, function ($mock) use ($mockProcessResult) {
            $mock->shouldReceive('getRemote')
                ->andReturn(new RemoteConfig(
                    name: 'production',
                    host: 'user@production.example.com',
                    path: '/var/www/app',
                ));

            $mock->shouldReceive('isAtomicDeployment')
                ->andReturn(false);

            $mock->shouldReceive('getRemoteDatabaseDriver')
                ->andReturn('mysql');

            $mock->shouldReceive('createRemoteSnapshot')
                ->once()
                ->andReturn($mockProcessResult);

            $mock->shouldReceive('getSnapshotPath')
                ->andReturn(storage_path('snapshots'));

            $mock->shouldReceive('downloadSnapshot')
                ->once()
                ->andReturn($mockProcessResult);

            $mock->shouldReceive('deleteRemoteSnapshot')
                ->once()
                ->andReturn($mockProcessResult);
        });

        $this->artisan('remote-sync:pull-database', [
            'remote' => 'production',
            '--no-backup' => true,
            '--force' => true,
        ])
            ->assertSuccessful();
    });

    it('uses default remote when not specified', function () {
        $this->setUpProductionRemote();
        config()->set('database.connections.testing.driver', 'mysql');
        config()->set('remote-sync.exclude_tables', []);

        $mockProcessResult = Mockery::mock(ProcessResult::class);
        $mockProcessResult->shouldReceive('successful')->andReturn(true);
        $mockProcessResult->shouldReceive('output')->andReturn('');

        $this->mock(RemoteSyncService::class, function ($mock) use ($mockProcessResult) {
            $mock->shouldReceive('getRemote')
                ->andReturn(new RemoteConfig(
                    name: 'production',
                    host: 'user@production.example.com',
                    path: '/var/www/app',
                ));

            $mock->shouldReceive('isAtomicDeployment')
                ->andReturn(false);

            $mock->shouldReceive('getRemoteDatabaseDriver')
                ->andReturn('mysql');

            $mock->shouldReceive('createRemoteSnapshot')
                ->once()
                ->andReturn($mockProcessResult);

            $mock->shouldReceive('getSnapshotPath')
                ->andReturn(storage_path('snapshots'));

            $mock->shouldReceive('downloadSnapshot')
                ->once()
                ->andReturn($mockProcessResult);

            $mock->shouldReceive('deleteRemoteSnapshot')
                ->once()
                ->andReturn($mockProcessResult);
        });

        $this->artisan('remote-sync:pull-database', [
            '--no-backup' => true,
            '--force' => true,
        ])
            ->assertSuccessful();
    });

    it('detects atomic deployment path ending with /current', function () {
        config()->set('remote-sync.remotes', [
            'production' => [
                'host' => 'user@example.com',
                'path' => '/var/www/app/current',
            ],
        ]);
        config()->set('remote-sync.default', 'production');
        config()->set('database.connections.testing.driver', 'mysql');
        config()->set('remote-sync.exclude_tables', []);

        $mockProcessResult = Mockery::mock(ProcessResult::class);
        $mockProcessResult->shouldReceive('successful')->andReturn(true);
        $mockProcessResult->shouldReceive('output')->andReturn('');

        $remoteConfig = new RemoteConfig(
            name: 'production',
            host: 'user@example.com',
            path: '/var/www/app/current',
        );

        $this->mock(RemoteSyncService::class, function ($mock) use ($mockProcessResult, $remoteConfig) {
            $mock->shouldReceive('getRemote')
                ->andReturn($remoteConfig);

            $mock->shouldReceive('isAtomicDeployment')
                ->andReturn(true);

            $mock->shouldReceive('getRemoteDatabaseDriver')
                ->andReturn('mysql');

            $mock->shouldReceive('createRemoteSnapshot')
                ->once()
                ->andReturn($mockProcessResult);

            $mock->shouldReceive('getSnapshotPath')
                ->andReturn(storage_path('snapshots'));

            $mock->shouldReceive('downloadSnapshot')
                ->once()
                ->andReturn($mockProcessResult);

            $mock->shouldReceive('deleteRemoteSnapshot')
                ->once()
                ->andReturn($mockProcessResult);
        });

        $this->artisan('remote-sync:pull-database', [
            'remote' => 'production',
            '--no-backup' => true,
            '--force' => true,
        ])
            ->assertSuccessful();
    });
});
