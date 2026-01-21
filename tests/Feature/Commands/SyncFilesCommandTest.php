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

describe('SyncFilesCommand', function () {
    it('refuses to run in production environment', function () {
        app()->detectEnvironment(fn () => 'production');
        $this->setUpProductionRemote();
        config()->set('remote-sync.paths', ['app/public']);

        $this->artisan('remote-sync:pull-files', ['remote' => 'production'])
            ->assertFailed()
            ->expectsOutputToContain('This command cannot be run in production');
    });

    it('fails when remote is not configured', function () {
        config()->set('remote-sync.remotes', []);

        $this->artisan('remote-sync:pull-files', ['remote' => 'nonexistent'])
            ->assertFailed()
            ->expectsOutputToContain("Remote 'nonexistent' is not configured");
    });

    it('warns when no paths are configured', function () {
        $this->setUpProductionRemote();
        config()->set('remote-sync.paths', []);

        $this->artisan('remote-sync:pull-files', [
            'remote' => 'production',
            '--force' => true,
        ])
            ->expectsOutputToContain('No paths configured for syncing')
            ->assertSuccessful();
    });

    it('syncs all configured paths', function () {
        $this->setUpProductionRemote();
        config()->set('remote-sync.paths', ['app/public', 'app/media']);

        $mockResult = Mockery::mock(ProcessResult::class);
        $mockResult->shouldReceive('successful')->andReturn(true);

        $this->mock(RemoteSyncService::class, function ($mock) use ($mockResult) {
            $mock->shouldReceive('getRemote')
                ->andReturn(new RemoteConfig(
                    name: 'production',
                    host: 'user@production.example.com',
                    path: '/var/www/app',
                ));

            $mock->shouldReceive('isAtomicDeployment')
                ->andReturn(false);

            $mock->shouldReceive('rsync')
                ->twice()
                ->andReturn($mockResult);
        });

        $this->artisan('remote-sync:pull-files', [
            'remote' => 'production',
            '--force' => true,
        ])
            ->expectsOutputToContain('Syncing: app/public')
            ->expectsOutputToContain('Syncing: app/media')
            ->expectsOutputToContain('Files synced from [production]')
            ->assertSuccessful();
    });

    it('syncs single path when --path option is provided', function () {
        $this->setUpProductionRemote();
        config()->set('remote-sync.paths', ['app/public', 'app/media']);

        $mockResult = Mockery::mock(ProcessResult::class);
        $mockResult->shouldReceive('successful')->andReturn(true);

        $this->mock(RemoteSyncService::class, function ($mock) use ($mockResult) {
            $mock->shouldReceive('getRemote')
                ->andReturn(new RemoteConfig(
                    name: 'production',
                    host: 'user@production.example.com',
                    path: '/var/www/app',
                ));

            $mock->shouldReceive('isAtomicDeployment')
                ->andReturn(false);

            $mock->shouldReceive('rsync')
                ->once()
                ->withArgs(function ($remote, $sourcePath, $destPath, $options) {
                    return str_contains($sourcePath, 'app/custom');
                })
                ->andReturn($mockResult);
        });

        $this->artisan('remote-sync:pull-files', [
            'remote' => 'production',
            '--path' => 'app/custom',
            '--force' => true,
        ])
            ->expectsOutputToContain('Syncing: app/custom')
            ->assertSuccessful();
    });

    it('uses --delete flag when specified', function () {
        $this->setUpProductionRemote();
        config()->set('remote-sync.paths', ['app/public']);

        $mockResult = Mockery::mock(ProcessResult::class);
        $mockResult->shouldReceive('successful')->andReturn(true);

        $this->mock(RemoteSyncService::class, function ($mock) use ($mockResult) {
            $mock->shouldReceive('getRemote')
                ->andReturn(new RemoteConfig(
                    name: 'production',
                    host: 'user@production.example.com',
                    path: '/var/www/app',
                ));

            $mock->shouldReceive('isAtomicDeployment')
                ->andReturn(false);

            $mock->shouldReceive('rsync')
                ->once()
                ->withArgs(function ($remote, $sourcePath, $destPath, $options) {
                    return in_array('--delete', $options);
                })
                ->andReturn($mockResult);
        });

        $this->artisan('remote-sync:pull-files', [
            'remote' => 'production',
            '--delete' => true,
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

        $this->mock(RemoteSyncService::class, function ($mock) use ($mockResult) {
            $mock->shouldReceive('getRemote')
                ->andReturn(new RemoteConfig(
                    name: 'production',
                    host: 'user@production.example.com',
                    path: '/var/www/app',
                ));

            $mock->shouldReceive('isAtomicDeployment')
                ->andReturn(false);

            $mock->shouldReceive('rsync')
                ->once()
                ->andReturn($mockResult);
        });

        $this->artisan('remote-sync:pull-files', [
            'remote' => 'production',
            '--force' => true,
        ])
            ->assertSuccessful();

        expect(is_dir($testPath))->toBeTrue();

        rmdir($testPath);
    });

    it('reports error when rsync fails', function () {
        $this->setUpProductionRemote();
        config()->set('remote-sync.paths', ['app/public']);

        $mockResult = Mockery::mock(ProcessResult::class);
        $mockResult->shouldReceive('successful')->andReturn(false);
        $mockResult->shouldReceive('errorOutput')->andReturn('Connection refused');

        $this->mock(RemoteSyncService::class, function ($mock) use ($mockResult) {
            $mock->shouldReceive('getRemote')
                ->andReturn(new RemoteConfig(
                    name: 'production',
                    host: 'user@production.example.com',
                    path: '/var/www/app',
                ));

            $mock->shouldReceive('isAtomicDeployment')
                ->andReturn(false);

            $mock->shouldReceive('rsync')
                ->once()
                ->andReturn($mockResult);
        });

        $this->artisan('remote-sync:pull-files', [
            'remote' => 'production',
            '--force' => true,
        ])
            ->expectsOutputToContain('Failed to sync app/public')
            ->assertFailed();
    });

    it('uses default remote when not specified', function () {
        $this->setUpProductionRemote();
        config()->set('remote-sync.paths', ['app/public']);

        $mockResult = Mockery::mock(ProcessResult::class);
        $mockResult->shouldReceive('successful')->andReturn(true);

        $this->mock(RemoteSyncService::class, function ($mock) use ($mockResult) {
            $mock->shouldReceive('getRemote')
                ->andReturn(new RemoteConfig(
                    name: 'production',
                    host: 'user@production.example.com',
                    path: '/var/www/app',
                ));

            $mock->shouldReceive('isAtomicDeployment')
                ->andReturn(false);

            $mock->shouldReceive('rsync')
                ->once()
                ->andReturn($mockResult);
        });

        $this->artisan('remote-sync:pull-files', ['--force' => true])
            ->assertSuccessful();
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

        $this->mock(RemoteSyncService::class, function ($mock) use ($mockResult) {
            $mock->shouldReceive('getRemote')
                ->andReturn(new RemoteConfig(
                    name: 'production',
                    host: 'user@example.com',
                    path: '/var/www/app',
                    isAtomic: true,
                ));

            $mock->shouldReceive('isAtomicDeployment')
                ->andReturn(true);

            $mock->shouldReceive('rsync')
                ->once()
                ->withArgs(function ($remote, $sourcePath, $destPath, $options) {
                    return str_contains($sourcePath, '/current/storage/');
                })
                ->andReturn($mockResult);
        });

        $this->artisan('remote-sync:pull-files', [
            'remote' => 'production',
            '--force' => true,
        ])
            ->assertSuccessful();
    });
});
