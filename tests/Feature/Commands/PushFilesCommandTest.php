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

describe('PushFilesCommand', function () {
    it('refuses to run in production environment', function () {
        app()->detectEnvironment(fn () => 'production');
        $this->setUpStagingRemote();
        config()->set('remote-sync.paths', ['app/public']);

        $this->artisan('remote-sync:push-files', ['remote' => 'staging'])
            ->assertFailed()
            ->expectsOutputToContain('This command cannot be run in production');
    });

    it('fails when remote is not configured', function () {
        config()->set('remote-sync.remotes', []);

        $this->artisan('remote-sync:push-files', ['remote' => 'nonexistent'])
            ->assertFailed()
            ->expectsOutputToContain("Remote 'nonexistent' is not configured");
    });

    it('fails when push is not allowed for remote', function () {
        $this->setUpProductionRemote();
        config()->set('remote-sync.paths', ['app/public']);

        $this->artisan('remote-sync:push-files', ['remote' => 'production'])
            ->assertFailed()
            ->expectsOutputToContain('Push is not allowed for remote [production]');
    });

    it('warns when no paths are configured', function () {
        $this->setUpStagingRemote();
        config()->set('remote-sync.paths', []);

        $this->artisan('remote-sync:push-files', [
            'remote' => 'staging',
            '--force' => true,
        ])
            ->expectsOutputToContain('No paths configured for pushing')
            ->assertSuccessful();
    });

    it('supports dry-run mode', function () {
        $this->setUpStagingRemote();
        config()->set('remote-sync.paths', ['app/public']);

        $testPath = storage_path('app/public');
        if (! is_dir($testPath)) {
            mkdir($testPath, 0755, true);
        }

        $mockResult = Mockery::mock(ProcessResult::class);
        $mockResult->shouldReceive('successful')->andReturn(true);
        $mockResult->shouldReceive('output')->andReturn('');

        $this->mock(RemoteSyncService::class, function ($mock) use ($mockResult) {
            $mock->shouldReceive('getRemote')
                ->andReturn(new RemoteConfig(
                    name: 'staging',
                    host: 'user@staging.example.com',
                    path: '/var/www/app',
                    pushAllowed: true,
                ));

            $mock->shouldReceive('isAtomicDeployment')
                ->andReturn(false);

            $mock->shouldReceive('rsyncUploadDryRun')
                ->andReturn($mockResult);
        });

        $this->artisan('remote-sync:push-files', [
            'remote' => 'staging',
            '--dry-run' => true,
            '--force' => true,
        ])
            ->expectsOutputToContain('Dry run mode')
            ->assertSuccessful();

        if (is_dir($testPath) && count(scandir($testPath)) == 2) {
            rmdir($testPath);
        }
    });

    it('syncs all configured paths with force flag', function () {
        $this->setUpStagingRemote();
        config()->set('remote-sync.paths', ['app/public', 'app/media']);

        $testPath1 = storage_path('app/public');
        $testPath2 = storage_path('app/media');
        if (! is_dir($testPath1)) {
            mkdir($testPath1, 0755, true);
        }
        if (! is_dir($testPath2)) {
            mkdir($testPath2, 0755, true);
        }

        $mockResult = Mockery::mock(ProcessResult::class);
        $mockResult->shouldReceive('successful')->andReturn(true);
        $mockResult->shouldReceive('output')->andReturn('');

        $this->mock(RemoteSyncService::class, function ($mock) use ($mockResult) {
            $mock->shouldReceive('getRemote')
                ->andReturn(new RemoteConfig(
                    name: 'staging',
                    host: 'user@staging.example.com',
                    path: '/var/www/app',
                    pushAllowed: true,
                ));

            $mock->shouldReceive('isAtomicDeployment')
                ->andReturn(false);

            $mock->shouldReceive('rsyncUploadDryRun')
                ->andReturn($mockResult);

            $mock->shouldReceive('rsyncUpload')
                ->twice()
                ->andReturn($mockResult);
        });

        $this->artisan('remote-sync:push-files', [
            'remote' => 'staging',
            '--force' => true,
        ])
            ->expectsOutputToContain('Pushing: app/public')
            ->expectsOutputToContain('Pushing: app/media')
            ->expectsOutputToContain('Files pushed to [staging]')
            ->assertSuccessful();

        if (is_dir($testPath1) && count(scandir($testPath1)) == 2) {
            rmdir($testPath1);
        }
        if (is_dir($testPath2) && count(scandir($testPath2)) == 2) {
            rmdir($testPath2);
        }
    });

    it('syncs single path when --path option is provided', function () {
        $this->setUpStagingRemote();
        config()->set('remote-sync.paths', ['app/public', 'app/media']);

        $testPath = storage_path('app/custom');
        if (! is_dir($testPath)) {
            mkdir($testPath, 0755, true);
        }

        $mockResult = Mockery::mock(ProcessResult::class);
        $mockResult->shouldReceive('successful')->andReturn(true);
        $mockResult->shouldReceive('output')->andReturn('');

        $this->mock(RemoteSyncService::class, function ($mock) use ($mockResult) {
            $mock->shouldReceive('getRemote')
                ->andReturn(new RemoteConfig(
                    name: 'staging',
                    host: 'user@staging.example.com',
                    path: '/var/www/app',
                    pushAllowed: true,
                ));

            $mock->shouldReceive('isAtomicDeployment')
                ->andReturn(false);

            $mock->shouldReceive('rsyncUploadDryRun')
                ->andReturn($mockResult);

            $mock->shouldReceive('rsyncUpload')
                ->once()
                ->andReturn($mockResult);
        });

        $this->artisan('remote-sync:push-files', [
            'remote' => 'staging',
            '--path' => 'app/custom',
            '--force' => true,
        ])
            ->expectsOutputToContain('Pushing: app/custom')
            ->assertSuccessful();

        if (is_dir($testPath) && count(scandir($testPath)) == 2) {
            rmdir($testPath);
        }
    });

    it('uses --delete flag when specified', function () {
        $this->setUpStagingRemote();
        config()->set('remote-sync.paths', ['app/public']);

        $testPath = storage_path('app/public');
        if (! is_dir($testPath)) {
            mkdir($testPath, 0755, true);
        }

        $mockResult = Mockery::mock(ProcessResult::class);
        $mockResult->shouldReceive('successful')->andReturn(true);
        $mockResult->shouldReceive('output')->andReturn('');

        $this->mock(RemoteSyncService::class, function ($mock) use ($mockResult) {
            $mock->shouldReceive('getRemote')
                ->andReturn(new RemoteConfig(
                    name: 'staging',
                    host: 'user@staging.example.com',
                    path: '/var/www/app',
                    pushAllowed: true,
                ));

            $mock->shouldReceive('isAtomicDeployment')
                ->andReturn(false);

            $mock->shouldReceive('rsyncUploadDryRun')
                ->andReturn($mockResult);

            $mock->shouldReceive('rsyncUpload')
                ->once()
                ->withArgs(function ($remote, $localPath, $remotePath, $options) {
                    return in_array('--delete', $options);
                })
                ->andReturn($mockResult);
        });

        $this->artisan('remote-sync:push-files', [
            'remote' => 'staging',
            '--delete' => true,
            '--force' => true,
        ])
            ->expectsOutputToContain('Files pushed to [staging]')
            ->assertSuccessful();

        if (is_dir($testPath) && count(scandir($testPath)) == 2) {
            rmdir($testPath);
        }
    });

    it('warns when local path does not exist', function () {
        $this->setUpStagingRemote();
        config()->set('remote-sync.paths', ['app/nonexistent-path']);

        $mockResult = Mockery::mock(ProcessResult::class);
        $mockResult->shouldReceive('successful')->andReturn(true);
        $mockResult->shouldReceive('output')->andReturn('');

        $this->mock(RemoteSyncService::class, function ($mock) use ($mockResult) {
            $mock->shouldReceive('getRemote')
                ->andReturn(new RemoteConfig(
                    name: 'staging',
                    host: 'user@staging.example.com',
                    path: '/var/www/app',
                    pushAllowed: true,
                ));

            $mock->shouldReceive('isAtomicDeployment')
                ->andReturn(false);

            $mock->shouldReceive('rsyncUploadDryRun')
                ->andReturn($mockResult);
        });

        $this->artisan('remote-sync:push-files', [
            'remote' => 'staging',
            '--force' => true,
        ])
            ->expectsOutputToContain('Local path does not exist')
            ->expectsOutputToContain('Files pushed to [staging]')
            ->assertSuccessful();
    });

    it('reports error when rsync fails', function () {
        $this->setUpStagingRemote();
        config()->set('remote-sync.paths', ['app/public']);

        $testPath = storage_path('app/public');
        if (! is_dir($testPath)) {
            mkdir($testPath, 0755, true);
        }

        $mockDryRunResult = Mockery::mock(ProcessResult::class);
        $mockDryRunResult->shouldReceive('successful')->andReturn(true);
        $mockDryRunResult->shouldReceive('output')->andReturn('');

        $mockResult = Mockery::mock(ProcessResult::class);
        $mockResult->shouldReceive('successful')->andReturn(false);
        $mockResult->shouldReceive('errorOutput')->andReturn('Connection refused');

        $this->mock(RemoteSyncService::class, function ($mock) use ($mockDryRunResult, $mockResult) {
            $mock->shouldReceive('getRemote')
                ->andReturn(new RemoteConfig(
                    name: 'staging',
                    host: 'user@staging.example.com',
                    path: '/var/www/app',
                    pushAllowed: true,
                ));

            $mock->shouldReceive('isAtomicDeployment')
                ->andReturn(false);

            $mock->shouldReceive('rsyncUploadDryRun')
                ->andReturn($mockDryRunResult);

            $mock->shouldReceive('rsyncUpload')
                ->once()
                ->andReturn($mockResult);
        });

        $this->artisan('remote-sync:push-files', [
            'remote' => 'staging',
            '--force' => true,
        ])
            ->expectsOutputToContain('Failed to push app/public')
            ->assertFailed();

        if (is_dir($testPath) && count(scandir($testPath)) == 2) {
            rmdir($testPath);
        }
    });
});
