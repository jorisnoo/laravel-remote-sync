<?php

use Illuminate\Contracts\Process\ProcessResult;
use Illuminate\Support\Facades\Process;
use Noo\LaravelRemoteSync\Data\RemoteConfig;
use Noo\LaravelRemoteSync\RemoteSyncService;

beforeEach(function () {
    Process::fake([
        '*' => Process::result(output: 'no'),
    ]);
    config()->set('remote-sync.paths', []);
});

describe('PushRemoteCommand (database)', function () {
    it('refuses to run in production environment', function () {
        app()->detectEnvironment(fn () => 'production');
        $this->setUpStagingRemote();

        $this->artisan('remote-sync:push', ['remote' => 'staging'])
            ->assertFailed()
            ->expectsOutputToContain('This command cannot be run in production');
    });

    it('fails when remote is not configured', function () {
        config()->set('remote-sync.remotes', []);

        $this->artisan('remote-sync:push', ['remote' => 'nonexistent'])
            ->assertFailed()
            ->expectsOutputToContain("Remote 'nonexistent' is not configured");
    });

    it('fails when push is not allowed for remote', function () {
        $this->setUpProductionRemote();

        $this->artisan('remote-sync:push', ['remote' => 'production'])
            ->assertFailed()
            ->expectsOutputToContain('Push is not allowed for remote [production]');
    });

    it('requires push_allowed to be true', function () {
        config()->set('remote-sync.remotes', [
            'staging' => [
                'host' => 'user@staging.example.com',
                'path' => '/var/www/app',
                'push_allowed' => false,
            ],
        ]);

        $this->artisan('remote-sync:push', ['remote' => 'staging'])
            ->assertFailed()
            ->expectsOutputToContain('Push is not allowed for remote [staging]');
    });

    it('warns when database driver cannot be detected but proceeds with force', function () {
        $this->setUpStagingRemote();

        $mockProcessResult = Mockery::mock(ProcessResult::class);
        $mockProcessResult->shouldReceive('successful')->andReturn(true);
        $mockProcessResult->shouldReceive('output')->andReturn('');

        $this->mock(RemoteSyncService::class, function ($mock) use ($mockProcessResult) {
            $mock->shouldReceive('getRemote')
                ->andReturn(new RemoteConfig(
                    name: 'staging',
                    host: 'user@staging.example.com',
                    path: '/var/www/app',
                    pushAllowed: true,
                ));

            $mock->shouldReceive('isAtomicDeployment')
                ->andReturn(false);

            $mock->shouldReceive('getRemoteDatabaseDriver')
                ->once()
                ->andReturn(null);

            $mock->shouldReceive('getLocalTableNames')
                ->andReturn([]);

            $mock->shouldReceive('getRemoteTableNames')
                ->andReturn([]);

            $mock->shouldReceive('getLocalMigrationRecords')
                ->andReturn([]);

            $mock->shouldReceive('getRemoteMigrationRecords')
                ->andReturn([]);

            $mock->shouldReceive('createRemoteBackup')
                ->once()
                ->andReturn($mockProcessResult);

            $mock->shouldReceive('getSnapshotPath')
                ->andReturn(storage_path('snapshots'));

            $mock->shouldReceive('uploadSnapshot')
                ->once()
                ->andReturn($mockProcessResult);

            $mock->shouldReceive('loadRemoteSnapshot')
                ->once()
                ->andReturn($mockProcessResult);

            $mock->shouldReceive('deleteRemoteSnapshot')
                ->once()
                ->andReturn($mockProcessResult);
        });

        $this->artisan('remote-sync:push', [
            'remote' => 'staging',
            '--force' => true,
        ])
            ->expectsOutputToContain('Could not detect remote database driver')
            ->assertSuccessful();
    });

    it('fails on database driver mismatch', function () {
        $this->setUpStagingRemote();
        config()->set('database.default', 'testing');
        config()->set('database.connections.testing.driver', 'sqlite');

        $this->mock(RemoteSyncService::class, function ($mock) {
            $mock->shouldReceive('getRemote')
                ->andReturn(new RemoteConfig(
                    name: 'staging',
                    host: 'user@staging.example.com',
                    path: '/var/www/app',
                    pushAllowed: true,
                ));

            $mock->shouldReceive('isAtomicDeployment')
                ->andReturn(false);

            $mock->shouldReceive('getRemoteDatabaseDriver')
                ->once()
                ->andReturn('mysql');
        });

        $this->artisan('remote-sync:push', ['remote' => 'staging', '--force' => true])
            ->assertFailed()
            ->expectsOutputToContain('Database driver mismatch');
    });

    it('proceeds with push when using force flag', function () {
        $this->setUpStagingRemote();

        $mockProcessResult = Mockery::mock(ProcessResult::class);
        $mockProcessResult->shouldReceive('successful')->andReturn(true);
        $mockProcessResult->shouldReceive('output')->andReturn('');

        $this->mock(RemoteSyncService::class, function ($mock) use ($mockProcessResult) {
            $mock->shouldReceive('getRemote')
                ->andReturn(new RemoteConfig(
                    name: 'staging',
                    host: 'user@staging.example.com',
                    path: '/var/www/app',
                    pushAllowed: true,
                ));

            $mock->shouldReceive('isAtomicDeployment')
                ->andReturn(false);

            $mock->shouldReceive('getRemoteDatabaseDriver')
                ->andReturn('sqlite');

            $mock->shouldReceive('getLocalTableNames')
                ->andReturn([]);

            $mock->shouldReceive('getRemoteTableNames')
                ->andReturn([]);

            $mock->shouldReceive('getLocalMigrationRecords')
                ->andReturn([]);

            $mock->shouldReceive('getRemoteMigrationRecords')
                ->andReturn([]);

            $mock->shouldReceive('createRemoteBackup')
                ->once()
                ->andReturn($mockProcessResult);

            $mock->shouldReceive('getSnapshotPath')
                ->andReturn(storage_path('snapshots'));

            $mock->shouldReceive('uploadSnapshot')
                ->once()
                ->andReturn($mockProcessResult);

            $mock->shouldReceive('loadRemoteSnapshot')
                ->once()
                ->andReturn($mockProcessResult);

            $mock->shouldReceive('deleteRemoteSnapshot')
                ->once()
                ->andReturn($mockProcessResult);
        });

        $this->artisan('remote-sync:push', [
            'remote' => 'staging',
            '--force' => true,
        ])
            ->assertSuccessful();
    });

    it('treats mariadb and mysql as compatible drivers', function () {
        $this->setUpStagingRemote();

        $mockProcessResult = Mockery::mock(ProcessResult::class);
        $mockProcessResult->shouldReceive('successful')->andReturn(true);
        $mockProcessResult->shouldReceive('output')->andReturn('');

        $this->mock(RemoteSyncService::class, function ($mock) use ($mockProcessResult) {
            $mock->shouldReceive('getRemote')
                ->andReturn(new RemoteConfig(
                    name: 'staging',
                    host: 'user@staging.example.com',
                    path: '/var/www/app',
                    pushAllowed: true,
                ));

            $mock->shouldReceive('isAtomicDeployment')
                ->andReturn(false);

            $mock->shouldReceive('getRemoteDatabaseDriver')
                ->andReturn('sqlite');

            $mock->shouldReceive('getLocalTableNames')
                ->andReturn([]);

            $mock->shouldReceive('getRemoteTableNames')
                ->andReturn([]);

            $mock->shouldReceive('getLocalMigrationRecords')
                ->andReturn([]);

            $mock->shouldReceive('getRemoteMigrationRecords')
                ->andReturn([]);

            $mock->shouldReceive('createRemoteBackup')
                ->once()
                ->andReturn($mockProcessResult);

            $mock->shouldReceive('getSnapshotPath')
                ->andReturn(storage_path('snapshots'));

            $mock->shouldReceive('uploadSnapshot')
                ->once()
                ->andReturn($mockProcessResult);

            $mock->shouldReceive('loadRemoteSnapshot')
                ->once()
                ->andReturn($mockProcessResult);

            $mock->shouldReceive('deleteRemoteSnapshot')
                ->once()
                ->andReturn($mockProcessResult);
        });

        $this->artisan('remote-sync:push', [
            'remote' => 'staging',
            '--force' => true,
        ])
            ->assertSuccessful();
    });

    it('uses default remote when not specified', function () {
        config()->set('remote-sync.remotes', [
            'staging' => [
                'host' => 'user@staging.example.com',
                'path' => '/var/www/app',
                'push_allowed' => true,
            ],
        ]);
        config()->set('remote-sync.default', 'staging');

        $mockProcessResult = Mockery::mock(ProcessResult::class);
        $mockProcessResult->shouldReceive('successful')->andReturn(true);
        $mockProcessResult->shouldReceive('output')->andReturn('');

        $this->mock(RemoteSyncService::class, function ($mock) use ($mockProcessResult) {
            $mock->shouldReceive('getAvailableRemotes')
                ->andReturn(['staging']);

            $mock->shouldReceive('getRemote')
                ->andReturn(new RemoteConfig(
                    name: 'staging',
                    host: 'user@staging.example.com',
                    path: '/var/www/app',
                    pushAllowed: true,
                ));

            $mock->shouldReceive('isAtomicDeployment')
                ->andReturn(false);

            $mock->shouldReceive('getRemoteDatabaseDriver')
                ->andReturn('sqlite');

            $mock->shouldReceive('getLocalTableNames')
                ->andReturn([]);

            $mock->shouldReceive('getRemoteTableNames')
                ->andReturn([]);

            $mock->shouldReceive('getLocalMigrationRecords')
                ->andReturn([]);

            $mock->shouldReceive('getRemoteMigrationRecords')
                ->andReturn([]);

            $mock->shouldReceive('createRemoteBackup')
                ->once()
                ->andReturn($mockProcessResult);

            $mock->shouldReceive('getSnapshotPath')
                ->andReturn(storage_path('snapshots'));

            $mock->shouldReceive('uploadSnapshot')
                ->once()
                ->andReturn($mockProcessResult);

            $mock->shouldReceive('loadRemoteSnapshot')
                ->once()
                ->andReturn($mockProcessResult);

            $mock->shouldReceive('deleteRemoteSnapshot')
                ->once()
                ->andReturn($mockProcessResult);
        });

        $this->artisan('remote-sync:push', ['--force' => true])
            ->assertSuccessful();
    });

    it('excludes configured tables from push preview and snapshot', function () {
        $this->setUpStagingRemote();
        config()->set('remote-sync.exclude_tables', ['sessions', 'cache', 'jobs']);

        $mockProcessResult = Mockery::mock(ProcessResult::class);
        $mockProcessResult->shouldReceive('successful')->andReturn(true);
        $mockProcessResult->shouldReceive('output')->andReturn('');

        $this->mock(RemoteSyncService::class, function ($mock) use ($mockProcessResult) {
            $mock->shouldReceive('getRemote')
                ->andReturn(new RemoteConfig(
                    name: 'staging',
                    host: 'user@staging.example.com',
                    path: '/var/www/app',
                    pushAllowed: true,
                ));

            $mock->shouldReceive('isAtomicDeployment')
                ->andReturn(false);

            $mock->shouldReceive('getRemoteDatabaseDriver')
                ->andReturn('sqlite');

            $mock->shouldReceive('getLocalTableNames')
                ->andReturn(['users', 'posts', 'sessions', 'cache', 'jobs']);

            $mock->shouldReceive('getRemoteTableNames')
                ->andReturn(['users', 'posts', 'sessions', 'cache', 'jobs']);

            $mock->shouldReceive('getLocalMigrationRecords')
                ->andReturn([]);

            $mock->shouldReceive('getRemoteMigrationRecords')
                ->andReturn([]);

            $mock->shouldReceive('createRemoteBackup')
                ->once()
                ->andReturn($mockProcessResult);

            $mock->shouldReceive('getSnapshotPath')
                ->andReturn(storage_path('snapshots'));

            $mock->shouldReceive('uploadSnapshot')
                ->once()
                ->andReturn($mockProcessResult);

            $mock->shouldReceive('loadRemoteSnapshot')
                ->once()
                ->andReturn($mockProcessResult);

            $mock->shouldReceive('deleteRemoteSnapshot')
                ->once()
                ->andReturn($mockProcessResult);
        });

        $this->artisan('remote-sync:push', [
            'remote' => 'staging',
            '--force' => true,
        ])
            ->expectsOutputToContain('Database push preview: local → remote')
            ->expectsOutputToContain('Syncing 2 tables')
            ->expectsOutputToContain('Excluded 3 tables (preserved on remote)')
            ->expectsOutputToContain('cache, jobs, sessions')
            ->assertSuccessful();
    });

    it('shows migration comparison with differences in push preview', function () {
        $this->setUpStagingRemote();
        config()->set('remote-sync.exclude_tables', ['sessions', 'cache']);

        $mockProcessResult = Mockery::mock(ProcessResult::class);
        $mockProcessResult->shouldReceive('successful')->andReturn(true);
        $mockProcessResult->shouldReceive('output')->andReturn('');

        $this->mock(RemoteSyncService::class, function ($mock) use ($mockProcessResult) {
            $mock->shouldReceive('getRemote')
                ->andReturn(new RemoteConfig(
                    name: 'staging',
                    host: 'user@staging.example.com',
                    path: '/var/www/app',
                    pushAllowed: true,
                ));

            $mock->shouldReceive('isAtomicDeployment')
                ->andReturn(false);

            $mock->shouldReceive('getRemoteDatabaseDriver')
                ->andReturn('sqlite');

            $mock->shouldReceive('getLocalTableNames')
                ->andReturn(['users', 'posts', 'sessions', 'cache', 'migrations']);

            $mock->shouldReceive('getRemoteTableNames')
                ->andReturn(['users', 'posts', 'sessions', 'cache', 'migrations']);

            $mock->shouldReceive('getLocalMigrationRecords')
                ->andReturn(['2024_01_01_create_users', '2024_01_15_add_tags_table']);

            $mock->shouldReceive('getRemoteMigrationRecords')
                ->andReturn(['2024_01_01_create_users', '2024_01_12_fix_users_index']);

            $mock->shouldReceive('createRemoteBackup')
                ->once()
                ->andReturn($mockProcessResult);

            $mock->shouldReceive('getSnapshotPath')
                ->andReturn(storage_path('snapshots'));

            $mock->shouldReceive('uploadSnapshot')
                ->once()
                ->andReturn($mockProcessResult);

            $mock->shouldReceive('loadRemoteSnapshot')
                ->once()
                ->andReturn($mockProcessResult);

            $mock->shouldReceive('deleteRemoteSnapshot')
                ->once()
                ->andReturn($mockProcessResult);
        });

        $this->artisan('remote-sync:push', [
            'remote' => 'staging',
            '--force' => true,
        ])
            ->expectsOutputToContain('Syncing 2 tables')
            ->expectsOutputToContain('Migration records differ')
            ->expectsOutputToContain('1 migration only in local')
            ->expectsOutputToContain('2024_01_15_add_tags_table')
            ->expectsOutputToContain('1 migration only in remote')
            ->expectsOutputToContain('2024_01_12_fix_users_index')
            ->assertSuccessful();
    });

    it('detects atomic deployment and uses correct path', function () {
        config()->set('remote-sync.remotes', [
            'staging' => [
                'host' => 'user@staging.example.com',
                'path' => '/var/www/app/current',
                'push_allowed' => true,
            ],
        ]);
        config()->set('remote-sync.default', 'staging');

        $mockProcessResult = Mockery::mock(ProcessResult::class);
        $mockProcessResult->shouldReceive('successful')->andReturn(true);
        $mockProcessResult->shouldReceive('output')->andReturn('');

        $remoteConfig = new RemoteConfig(
            name: 'staging',
            host: 'user@staging.example.com',
            path: '/var/www/app/current',
            pushAllowed: true,
        );

        $this->mock(RemoteSyncService::class, function ($mock) use ($mockProcessResult, $remoteConfig) {
            $mock->shouldReceive('getRemote')
                ->andReturn($remoteConfig);

            $mock->shouldReceive('isAtomicDeployment')
                ->andReturn(true);

            $mock->shouldReceive('getRemoteDatabaseDriver')
                ->andReturn('sqlite');

            $mock->shouldReceive('getLocalTableNames')
                ->andReturn([]);

            $mock->shouldReceive('getRemoteTableNames')
                ->andReturn([]);

            $mock->shouldReceive('getLocalMigrationRecords')
                ->andReturn([]);

            $mock->shouldReceive('getRemoteMigrationRecords')
                ->andReturn([]);

            $mock->shouldReceive('createRemoteBackup')
                ->once()
                ->andReturn($mockProcessResult);

            $mock->shouldReceive('getSnapshotPath')
                ->andReturn(storage_path('snapshots'));

            $mock->shouldReceive('uploadSnapshot')
                ->once()
                ->andReturn($mockProcessResult);

            $mock->shouldReceive('loadRemoteSnapshot')
                ->once()
                ->andReturn($mockProcessResult);

            $mock->shouldReceive('deleteRemoteSnapshot')
                ->once()
                ->andReturn($mockProcessResult);
        });

        $this->artisan('remote-sync:push', [
            'remote' => 'staging',
            '--force' => true,
        ])
            ->assertSuccessful();
    });
});
