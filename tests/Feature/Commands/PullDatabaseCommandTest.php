<?php

use Illuminate\Contracts\Process\ProcessResult;
use Illuminate\Support\Facades\Process;
use Noo\LaravelRemoteSync\Data\RemoteConfig;
use Noo\LaravelRemoteSync\RemoteSyncService;

function mockSuccessfulPullFlow($mock, $mockProcessResult, $remoteConfig, array $extras = []): void
{
    $mock->shouldReceive('getRemote')->andReturn($remoteConfig);
    $mock->shouldReceive('isAtomicDeployment')->andReturn($extras['isAtomic'] ?? false);
    $mock->shouldReceive('getRemoteDatabaseDriver')->andReturn($extras['remoteDriver'] ?? null);
    $mock->shouldReceive('getRemoteTableNames')->andReturn($extras['remoteTables'] ?? []);
    $mock->shouldReceive('getLocalTableNames')->andReturn($extras['localTables'] ?? []);
    $mock->shouldReceive('getLocalMigrationRecords')->andReturn($extras['localMigrations'] ?? []);
    $mock->shouldReceive('getRemoteMigrationRecords')->andReturn($extras['remoteMigrations'] ?? []);
    $mock->shouldReceive('createRemoteSnapshot')->once()->andReturn($mockProcessResult);
    $mock->shouldReceive('getSnapshotPath')->andReturn(storage_path('snapshots'));
    $mock->shouldReceive('downloadSnapshot')->once()->andReturn($mockProcessResult);
    $mock->shouldReceive('loadSnapshotViaCli')->once()->andReturn($mockProcessResult);
    $mock->shouldReceive('deleteRemoteSnapshot')->once()->andReturn($mockProcessResult);
}

beforeEach(function () {
    Process::fake([
        '*' => Process::result(output: 'no'),
    ]);
});

describe('PullDatabaseCommand', function () {
    it('refuses to run in production environment', function () {
        app()->detectEnvironment(fn () => 'production');
        $this->setUpProductionRemote();

        $this->artisan('remote-sync:pull-db', ['remote' => 'production'])
            ->assertFailed()
            ->expectsOutputToContain('This command cannot be run in production');
    });

    it('fails when remote is not configured', function () {
        config()->set('remote-sync.remotes', []);

        $this->artisan('remote-sync:pull-db', ['remote' => 'nonexistent'])
            ->assertFailed()
            ->expectsOutputToContain("Remote 'nonexistent' is not configured");
    });

    it('fails when remote is missing host', function () {
        config()->set('remote-sync.remotes', [
            'incomplete' => ['path' => '/var/www/app'],
        ]);

        $this->artisan('remote-sync:pull-db', ['remote' => 'incomplete'])
            ->assertFailed()
            ->expectsOutputToContain('missing host or path configuration');
    });

    it('warns when database driver cannot be detected but proceeds', function () {
        $this->setUpProductionRemote();

        $mockProcessResult = Mockery::mock(ProcessResult::class);
        $mockProcessResult->shouldReceive('successful')->andReturn(true);
        $mockProcessResult->shouldReceive('output')->andReturn('');

        $remoteConfig = new RemoteConfig(
            name: 'production',
            host: 'user@production.example.com',
            path: '/var/www/app',
        );

        $this->mock(RemoteSyncService::class, function ($mock) use ($mockProcessResult, $remoteConfig) {
            mockSuccessfulPullFlow($mock, $mockProcessResult, $remoteConfig);
        });

        $this->artisan('remote-sync:pull-db', [
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

        $this->artisan('remote-sync:pull-db', ['remote' => 'production'])
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

        $remoteConfig = new RemoteConfig(
            name: 'production',
            host: 'user@production.example.com',
            path: '/var/www/app',
        );

        $this->mock(RemoteSyncService::class, function ($mock) use ($mockProcessResult, $remoteConfig) {
            mockSuccessfulPullFlow($mock, $mockProcessResult, $remoteConfig, ['remoteDriver' => 'mysql']);
        });

        $this->artisan('remote-sync:pull-db', [
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

        $remoteConfig = new RemoteConfig(
            name: 'production',
            host: 'user@production.example.com',
            path: '/var/www/app',
        );

        $this->mock(RemoteSyncService::class, function ($mock) use ($mockProcessResult, $remoteConfig) {
            mockSuccessfulPullFlow($mock, $mockProcessResult, $remoteConfig, ['remoteDriver' => 'mysql']);
        });

        $this->artisan('remote-sync:pull-db', [
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

        $remoteConfig = new RemoteConfig(
            name: 'production',
            host: 'user@production.example.com',
            path: '/var/www/app',
        );

        $this->mock(RemoteSyncService::class, function ($mock) use ($mockProcessResult, $remoteConfig) {
            $mock->shouldReceive('getAvailableRemotes')->andReturn(['production']);
            mockSuccessfulPullFlow($mock, $mockProcessResult, $remoteConfig, ['remoteDriver' => 'mysql']);
        });

        $this->artisan('remote-sync:pull-db', [
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
            mockSuccessfulPullFlow($mock, $mockProcessResult, $remoteConfig, [
                'isAtomic' => true,
                'remoteDriver' => 'mysql',
            ]);
        });

        $this->artisan('remote-sync:pull-db', [
            'remote' => 'production',
            '--no-backup' => true,
            '--force' => true,
        ])
            ->assertSuccessful();
    });

    it('shows migration diff in pull preview when records differ', function () {
        $this->setUpProductionRemote();
        config()->set('database.connections.testing.driver', 'mysql');
        config()->set('remote-sync.exclude_tables', []);

        $mockProcessResult = Mockery::mock(ProcessResult::class);
        $mockProcessResult->shouldReceive('successful')->andReturn(true);
        $mockProcessResult->shouldReceive('output')->andReturn('');

        $remoteConfig = new RemoteConfig(
            name: 'production',
            host: 'user@production.example.com',
            path: '/var/www/app',
        );

        $this->mock(RemoteSyncService::class, function ($mock) use ($mockProcessResult, $remoteConfig) {
            mockSuccessfulPullFlow($mock, $mockProcessResult, $remoteConfig, [
                'remoteDriver' => 'mysql',
                'localMigrations' => ['2024_01_01_create_users', '2024_01_15_add_tags_table'],
                'remoteMigrations' => ['2024_01_01_create_users', '2024_01_12_fix_users_index'],
            ]);
        });

        $this->artisan('remote-sync:pull-db', [
            'remote' => 'production',
            '--no-backup' => true,
            '--force' => true,
        ])
            ->expectsOutputToContain('Migration records differ')
            ->expectsOutputToContain('1 migration only in local')
            ->expectsOutputToContain('2024_01_15_add_tags_table')
            ->expectsOutputToContain('1 migration only in remote')
            ->expectsOutputToContain('2024_01_12_fix_users_index')
            ->assertSuccessful();
    });

    it('shows migrations in sync when records match', function () {
        $this->setUpProductionRemote();
        config()->set('database.connections.testing.driver', 'mysql');
        config()->set('remote-sync.exclude_tables', []);

        $mockProcessResult = Mockery::mock(ProcessResult::class);
        $mockProcessResult->shouldReceive('successful')->andReturn(true);
        $mockProcessResult->shouldReceive('output')->andReturn('');

        $remoteConfig = new RemoteConfig(
            name: 'production',
            host: 'user@production.example.com',
            path: '/var/www/app',
        );

        $this->mock(RemoteSyncService::class, function ($mock) use ($mockProcessResult, $remoteConfig) {
            mockSuccessfulPullFlow($mock, $mockProcessResult, $remoteConfig, [
                'remoteDriver' => 'mysql',
                'localMigrations' => ['2024_01_01_create_users'],
                'remoteMigrations' => ['2024_01_01_create_users'],
            ]);
        });

        $this->artisan('remote-sync:pull-db', [
            'remote' => 'production',
            '--no-backup' => true,
            '--force' => true,
        ])
            ->expectsOutputToContain('Migrations: in sync across environments')
            ->assertSuccessful();
    });
});
