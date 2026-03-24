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

describe('PullRemoteCommand (files)', function () {
    it('refuses to run in production environment', function () {
        app()->detectEnvironment(fn () => 'production');
        $this->setUpProductionRemote();
        config()->set('remote-sync.paths', ['app/public']);

        $this->artisan('remote-sync:pull', ['remote' => 'production'])
            ->assertFailed()
            ->expectsOutputToContain('This command cannot be run in production');
    });

    it('fails when remote is not configured', function () {
        config()->set('remote-sync.remotes', []);

        $this->artisan('remote-sync:pull', ['remote' => 'nonexistent'])
            ->assertFailed()
            ->expectsOutputToContain("Remote 'nonexistent' is not configured");
    });

    it('warns when no paths are configured', function () {
        $this->setUpProductionRemote();
        config()->set('remote-sync.paths', []);

        $mockProcessResult = Mockery::mock(ProcessResult::class);
        $mockProcessResult->shouldReceive('successful')->andReturn(true);
        $mockProcessResult->shouldReceive('output')->andReturn('');

        $remoteConfig = new RemoteConfig(
            name: 'production',
            host: 'user@production.example.com',
            path: '/var/www/app',
        );

        $this->mock(RemoteSyncService::class, function ($mock) use ($mockProcessResult, $remoteConfig) {
            $mock->shouldReceive('getRemote')->andReturn($remoteConfig);
            $mock->shouldReceive('checkHostKey')->andReturn('ok');
            $mock->shouldReceive('isAtomicDeployment')->andReturn(false);
            $mock->shouldReceive('getRemoteDatabaseInfo')->andReturn(['driver' => null, 'tables' => [], 'migrations' => []]);
            $mock->shouldReceive('getLocalTableNames')->andReturn([]);
            $mock->shouldReceive('getLocalMigrationRecords')->andReturn([]);
            $mock->shouldReceive('createRemoteSnapshot')->once()->andReturn($mockProcessResult);
            $mock->shouldReceive('getSnapshotPath')->andReturn(storage_path('snapshots'));
            $mock->shouldReceive('downloadSnapshot')->once()->andReturn($mockProcessResult);
            $mock->shouldReceive('loadSnapshotViaCli')->once()->andReturn($mockProcessResult);
            $mock->shouldReceive('deleteRemoteSnapshot')->once()->andReturn($mockProcessResult);
        });

        $this->artisan('remote-sync:pull', [
            'remote' => 'production',
            '--no-backup' => true,
            '--no-clear-cache' => true,
            '--force' => true,
        ])
            ->assertSuccessful();
    });

    it('syncs all configured paths', function () {
        $this->setUpProductionRemote();
        config()->set('remote-sync.paths', ['app/public', 'app/media']);

        $mockResult = Mockery::mock(ProcessResult::class);
        $mockResult->shouldReceive('successful')->andReturn(true);
        $mockResult->shouldReceive('output')->andReturn('');

        $this->mock(RemoteSyncService::class, function ($mock) use ($mockResult) {
            $mock->shouldReceive('getRemote')
                ->andReturn(new RemoteConfig(
                    name: 'production',
                    host: 'user@production.example.com',
                    path: '/var/www/app',
                ));

            $mock->shouldReceive('checkHostKey')->andReturn('ok');

            $mock->shouldReceive('isAtomicDeployment')
                ->andReturn(false);

            $mock->shouldReceive('getRemoteDatabaseInfo')->andReturn(['driver' => null, 'tables' => [], 'migrations' => []]);
            $mock->shouldReceive('getLocalTableNames')->andReturn([]);
            $mock->shouldReceive('getLocalMigrationRecords')->andReturn([]);
            $mock->shouldReceive('createRemoteSnapshot')->once()->andReturn($mockResult);
            $mock->shouldReceive('getSnapshotPath')->andReturn(storage_path('snapshots'));
            $mock->shouldReceive('downloadSnapshot')->once()->andReturn($mockResult);
            $mock->shouldReceive('loadSnapshotViaCli')->once()->andReturn($mockResult);
            $mock->shouldReceive('deleteRemoteSnapshot')->once()->andReturn($mockResult);

            $mock->shouldReceive('rsyncDryRun')
                ->andReturn($mockResult);

            $mock->shouldReceive('rsync')
                ->twice()
                ->andReturn($mockResult);
        });

        $this->artisan('remote-sync:pull', [
            'remote' => 'production',
            '--no-backup' => true,
            '--no-clear-cache' => true,
            '--force' => true,
        ])
            ->expectsOutputToContain('Pulling: app/public')
            ->expectsOutputToContain('Pulling: app/media')
            ->expectsOutputToContain('Files pulled from [production]')
            ->assertSuccessful();
    });

    it('syncs single path when --path option is provided', function () {
        $this->setUpProductionRemote();
        config()->set('remote-sync.paths', ['app/public', 'app/media']);

        $mockResult = Mockery::mock(ProcessResult::class);
        $mockResult->shouldReceive('successful')->andReturn(true);
        $mockResult->shouldReceive('output')->andReturn('');

        $this->mock(RemoteSyncService::class, function ($mock) use ($mockResult) {
            $mock->shouldReceive('getRemote')
                ->andReturn(new RemoteConfig(
                    name: 'production',
                    host: 'user@production.example.com',
                    path: '/var/www/app',
                ));

            $mock->shouldReceive('checkHostKey')->andReturn('ok');

            $mock->shouldReceive('isAtomicDeployment')
                ->andReturn(false);

            $mock->shouldReceive('getRemoteDatabaseInfo')->andReturn(['driver' => null, 'tables' => [], 'migrations' => []]);
            $mock->shouldReceive('getLocalTableNames')->andReturn([]);
            $mock->shouldReceive('getLocalMigrationRecords')->andReturn([]);
            $mock->shouldReceive('createRemoteSnapshot')->once()->andReturn($mockResult);
            $mock->shouldReceive('getSnapshotPath')->andReturn(storage_path('snapshots'));
            $mock->shouldReceive('downloadSnapshot')->once()->andReturn($mockResult);
            $mock->shouldReceive('loadSnapshotViaCli')->once()->andReturn($mockResult);
            $mock->shouldReceive('deleteRemoteSnapshot')->once()->andReturn($mockResult);

            $mock->shouldReceive('rsyncDryRun')
                ->andReturn($mockResult);

            $mock->shouldReceive('rsync')
                ->once()
                ->withArgs(function ($remote, $sourcePath, $destPath, $options) {
                    return str_contains($sourcePath, 'app/custom');
                })
                ->andReturn($mockResult);
        });

        $this->artisan('remote-sync:pull', [
            'remote' => 'production',
            '--path' => 'app/custom',
            '--no-backup' => true,
            '--no-clear-cache' => true,
            '--force' => true,
        ])
            ->expectsOutputToContain('Pulling: app/custom')
            ->assertSuccessful();
    });

    it('uses --delete flag when specified', function () {
        $this->setUpProductionRemote();
        config()->set('remote-sync.paths', ['app/public']);

        $mockResult = Mockery::mock(ProcessResult::class);
        $mockResult->shouldReceive('successful')->andReturn(true);
        $mockResult->shouldReceive('output')->andReturn('');

        $this->mock(RemoteSyncService::class, function ($mock) use ($mockResult) {
            $mock->shouldReceive('getRemote')
                ->andReturn(new RemoteConfig(
                    name: 'production',
                    host: 'user@production.example.com',
                    path: '/var/www/app',
                ));

            $mock->shouldReceive('checkHostKey')->andReturn('ok');

            $mock->shouldReceive('isAtomicDeployment')
                ->andReturn(false);

            $mock->shouldReceive('getRemoteDatabaseInfo')->andReturn(['driver' => null, 'tables' => [], 'migrations' => []]);
            $mock->shouldReceive('getLocalTableNames')->andReturn([]);
            $mock->shouldReceive('getLocalMigrationRecords')->andReturn([]);
            $mock->shouldReceive('createRemoteSnapshot')->once()->andReturn($mockResult);
            $mock->shouldReceive('getSnapshotPath')->andReturn(storage_path('snapshots'));
            $mock->shouldReceive('downloadSnapshot')->once()->andReturn($mockResult);
            $mock->shouldReceive('loadSnapshotViaCli')->once()->andReturn($mockResult);
            $mock->shouldReceive('deleteRemoteSnapshot')->once()->andReturn($mockResult);

            $mock->shouldReceive('rsyncDryRun')
                ->andReturn($mockResult);

            $mock->shouldReceive('rsync')
                ->once()
                ->withArgs(function ($remote, $sourcePath, $destPath, $options) {
                    return in_array('--delete', $options);
                })
                ->andReturn($mockResult);
        });

        $this->artisan('remote-sync:pull', [
            'remote' => 'production',
            '--delete' => true,
            '--no-backup' => true,
            '--no-clear-cache' => true,
            '--force' => true,
        ])
            ->assertSuccessful();
    });

    it('creates local directory if it does not exist', function () {
        $this->setUpProductionRemote();
        config()->set('remote-sync.paths', ['app/test-sync-dir']);

        $testPath = storage_path('app/test-sync-dir');
        if (is_dir($testPath)) {
            rmdir($testPath);
        }

        $mockResult = Mockery::mock(ProcessResult::class);
        $mockResult->shouldReceive('successful')->andReturn(true);
        $mockResult->shouldReceive('output')->andReturn('');

        $this->mock(RemoteSyncService::class, function ($mock) use ($mockResult) {
            $mock->shouldReceive('getRemote')
                ->andReturn(new RemoteConfig(
                    name: 'production',
                    host: 'user@production.example.com',
                    path: '/var/www/app',
                ));

            $mock->shouldReceive('checkHostKey')->andReturn('ok');

            $mock->shouldReceive('isAtomicDeployment')
                ->andReturn(false);

            $mock->shouldReceive('getRemoteDatabaseInfo')->andReturn(['driver' => null, 'tables' => [], 'migrations' => []]);
            $mock->shouldReceive('getLocalTableNames')->andReturn([]);
            $mock->shouldReceive('getLocalMigrationRecords')->andReturn([]);
            $mock->shouldReceive('createRemoteSnapshot')->once()->andReturn($mockResult);
            $mock->shouldReceive('getSnapshotPath')->andReturn(storage_path('snapshots'));
            $mock->shouldReceive('downloadSnapshot')->once()->andReturn($mockResult);
            $mock->shouldReceive('loadSnapshotViaCli')->once()->andReturn($mockResult);
            $mock->shouldReceive('deleteRemoteSnapshot')->once()->andReturn($mockResult);

            $mock->shouldReceive('rsyncDryRun')
                ->andReturn($mockResult);

            $mock->shouldReceive('rsync')
                ->once()
                ->andReturn($mockResult);
        });

        $this->artisan('remote-sync:pull', [
            'remote' => 'production',
            '--no-backup' => true,
            '--no-clear-cache' => true,
            '--force' => true,
        ])
            ->assertSuccessful();

        expect(is_dir($testPath))->toBeTrue();

        rmdir($testPath);
    });

    it('reports error when rsync fails', function () {
        $this->setUpProductionRemote();
        config()->set('remote-sync.paths', ['app/public']);

        $mockSuccessResult = Mockery::mock(ProcessResult::class);
        $mockSuccessResult->shouldReceive('successful')->andReturn(true);
        $mockSuccessResult->shouldReceive('output')->andReturn('');

        $mockResult = Mockery::mock(ProcessResult::class);
        $mockResult->shouldReceive('successful')->andReturn(false);
        $mockResult->shouldReceive('errorOutput')->andReturn('Connection refused');

        $this->mock(RemoteSyncService::class, function ($mock) use ($mockSuccessResult, $mockResult) {
            $mock->shouldReceive('getRemote')
                ->andReturn(new RemoteConfig(
                    name: 'production',
                    host: 'user@production.example.com',
                    path: '/var/www/app',
                ));

            $mock->shouldReceive('checkHostKey')->andReturn('ok');

            $mock->shouldReceive('isAtomicDeployment')
                ->andReturn(false);

            $mock->shouldReceive('getRemoteDatabaseInfo')->andReturn(['driver' => null, 'tables' => [], 'migrations' => []]);
            $mock->shouldReceive('getLocalTableNames')->andReturn([]);
            $mock->shouldReceive('getLocalMigrationRecords')->andReturn([]);
            $mock->shouldReceive('createRemoteSnapshot')->once()->andReturn($mockSuccessResult);
            $mock->shouldReceive('getSnapshotPath')->andReturn(storage_path('snapshots'));
            $mock->shouldReceive('downloadSnapshot')->once()->andReturn($mockSuccessResult);
            $mock->shouldReceive('loadSnapshotViaCli')->once()->andReturn($mockSuccessResult);
            $mock->shouldReceive('deleteRemoteSnapshot')->once()->andReturn($mockSuccessResult);

            $mock->shouldReceive('rsyncDryRun')
                ->andReturn($mockSuccessResult);

            $mock->shouldReceive('rsync')
                ->once()
                ->andReturn($mockResult);
        });

        $this->artisan('remote-sync:pull', [
            'remote' => 'production',
            '--no-backup' => true,
            '--no-clear-cache' => true,
            '--force' => true,
        ])
            ->expectsOutputToContain('Failed to pull app/public')
            ->assertFailed();
    });

    it('uses storage path from atomic deployment', function () {
        config()->set('remote-sync.remotes', [
            'production' => [
                'host' => 'user@example.com',
                'path' => '/var/www/app',
            ],
        ]);
        config()->set('remote-sync.default', 'production');
        config()->set('remote-sync.paths', ['app/public']);

        $mockResult = Mockery::mock(ProcessResult::class);
        $mockResult->shouldReceive('successful')->andReturn(true);
        $mockResult->shouldReceive('output')->andReturn('');

        $this->mock(RemoteSyncService::class, function ($mock) use ($mockResult) {
            $mock->shouldReceive('getRemote')
                ->andReturn(new RemoteConfig(
                    name: 'production',
                    host: 'user@example.com',
                    path: '/var/www/app',
                    isAtomic: true,
                ));

            $mock->shouldReceive('checkHostKey')->andReturn('ok');

            $mock->shouldReceive('isAtomicDeployment')
                ->andReturn(true);

            $mock->shouldReceive('getRemoteDatabaseInfo')->andReturn(['driver' => null, 'tables' => [], 'migrations' => []]);
            $mock->shouldReceive('getLocalTableNames')->andReturn([]);
            $mock->shouldReceive('getLocalMigrationRecords')->andReturn([]);
            $mock->shouldReceive('createRemoteSnapshot')->once()->andReturn($mockResult);
            $mock->shouldReceive('getSnapshotPath')->andReturn(storage_path('snapshots'));
            $mock->shouldReceive('downloadSnapshot')->once()->andReturn($mockResult);
            $mock->shouldReceive('loadSnapshotViaCli')->once()->andReturn($mockResult);
            $mock->shouldReceive('deleteRemoteSnapshot')->once()->andReturn($mockResult);

            $mock->shouldReceive('rsyncDryRun')
                ->andReturn($mockResult);

            $mock->shouldReceive('rsync')
                ->once()
                ->withArgs(function ($remote, $sourcePath, $destPath, $options) {
                    return str_contains($sourcePath, '/current/storage/');
                })
                ->andReturn($mockResult);
        });

        $this->artisan('remote-sync:pull', [
            'remote' => 'production',
            '--no-backup' => true,
            '--no-clear-cache' => true,
            '--force' => true,
        ])
            ->assertSuccessful();
    });
});
