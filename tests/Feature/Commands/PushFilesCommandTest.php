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

        $this->artisan('remote-sync:push-files', ['remote' => 'staging'])
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

            $mock->shouldReceive('rsyncUpload')
                ->once()
                ->withArgs(function ($remote, $localPath, $remotePath, $options) {
                    return in_array('--dry-run', $options);
                })
                ->andReturn($mockResult);
        });

        $this->artisan('remote-sync:push-files', [
            'remote' => 'staging',
            '--dry-run' => true,
        ])
            ->expectsOutputToContain('Dry run mode')
            ->expectsOutputToContain('Would sync: app/public')
            ->assertSuccessful();

        if (is_dir($testPath) && count(scandir($testPath)) == 2) {
            rmdir($testPath);
        }
    });

    it('requires double confirmation before push', function () {
        $this->setUpStagingRemote();
        config()->set('remote-sync.paths', ['app/public']);

        $testPath = storage_path('app/public');
        if (! is_dir($testPath)) {
            mkdir($testPath, 0755, true);
        }

        $this->artisan('remote-sync:push-files', ['remote' => 'staging'])
            ->expectsConfirmation(
                'You are about to push local files to [staging]. This will OVERWRITE remote data. Continue?',
                'yes'
            )
            ->expectsConfirmation(
                'Are you SURE you want to push to [staging]? This action cannot be undone.',
                'no'
            )
            ->expectsOutputToContain('Operation cancelled')
            ->assertSuccessful();

        if (is_dir($testPath) && count(scandir($testPath)) == 2) {
            rmdir($testPath);
        }
    });

    it('syncs all configured paths', function () {
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

            $mock->shouldReceive('rsyncUpload')
                ->twice()
                ->andReturn($mockResult);
        });

        $this->artisan('remote-sync:push-files', ['remote' => 'staging'])
            ->expectsConfirmation(
                'You are about to push local files to [staging]. This will OVERWRITE remote data. Continue?',
                'yes'
            )
            ->expectsConfirmation(
                'Are you SURE you want to push to [staging]? This action cannot be undone.',
                'yes'
            )
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

            $mock->shouldReceive('rsyncUpload')
                ->once()
                ->andReturn($mockResult);
        });

        $this->artisan('remote-sync:push-files', [
            'remote' => 'staging',
            '--path' => 'app/custom',
        ])
            ->expectsConfirmation(
                'You are about to push local files to [staging]. This will OVERWRITE remote data. Continue?',
                'yes'
            )
            ->expectsConfirmation(
                'Are you SURE you want to push to [staging]? This action cannot be undone.',
                'yes'
            )
            ->expectsOutputToContain('Pushing: app/custom')
            ->assertSuccessful();

        if (is_dir($testPath) && count(scandir($testPath)) == 2) {
            rmdir($testPath);
        }
    });

    it('uses --delete flag with additional confirmation', function () {
        $this->setUpStagingRemote();
        config()->set('remote-sync.paths', ['app/public']);

        $testPath = storage_path('app/public');
        if (! is_dir($testPath)) {
            mkdir($testPath, 0755, true);
        }

        $mockResult = Mockery::mock(ProcessResult::class);
        $mockResult->shouldReceive('successful')->andReturn(true);

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
        ])
            ->expectsConfirmation(
                'You are about to push local files to [staging]. This will OVERWRITE remote data. Continue?',
                'yes'
            )
            ->expectsConfirmation(
                'Are you SURE you want to push to [staging]? This action cannot be undone.',
                'yes'
            )
            ->expectsConfirmation(
                "WARNING: The --delete flag will REMOVE files on [staging] that don't exist locally. Are you absolutely sure?",
                'yes'
            )
            ->expectsOutputToContain('Files pushed to [staging]')
            ->assertSuccessful();

        if (is_dir($testPath) && count(scandir($testPath)) == 2) {
            rmdir($testPath);
        }
    });

    it('cancels when --delete confirmation is declined', function () {
        $this->setUpStagingRemote();
        config()->set('remote-sync.paths', ['app/public']);

        $testPath = storage_path('app/public');
        if (! is_dir($testPath)) {
            mkdir($testPath, 0755, true);
        }

        $this->artisan('remote-sync:push-files', [
            'remote' => 'staging',
            '--delete' => true,
        ])
            ->expectsConfirmation(
                'You are about to push local files to [staging]. This will OVERWRITE remote data. Continue?',
                'yes'
            )
            ->expectsConfirmation(
                'Are you SURE you want to push to [staging]? This action cannot be undone.',
                'yes'
            )
            ->expectsConfirmation(
                "WARNING: The --delete flag will REMOVE files on [staging] that don't exist locally. Are you absolutely sure?",
                'no'
            )
            ->expectsOutputToContain('Operation cancelled')
            ->assertSuccessful();

        if (is_dir($testPath) && count(scandir($testPath)) == 2) {
            rmdir($testPath);
        }
    });

    it('warns when local path does not exist', function () {
        $this->setUpStagingRemote();
        config()->set('remote-sync.paths', ['app/nonexistent-path']);

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
        });

        $this->artisan('remote-sync:push-files', ['remote' => 'staging'])
            ->expectsConfirmation(
                'You are about to push local files to [staging]. This will OVERWRITE remote data. Continue?',
                'yes'
            )
            ->expectsConfirmation(
                'Are you SURE you want to push to [staging]? This action cannot be undone.',
                'yes'
            )
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

        $mockResult = Mockery::mock(ProcessResult::class);
        $mockResult->shouldReceive('successful')->andReturn(false);
        $mockResult->shouldReceive('errorOutput')->andReturn('Connection refused');

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

            $mock->shouldReceive('rsyncUpload')
                ->once()
                ->andReturn($mockResult);
        });

        $this->artisan('remote-sync:push-files', ['remote' => 'staging'])
            ->expectsConfirmation(
                'You are about to push local files to [staging]. This will OVERWRITE remote data. Continue?',
                'yes'
            )
            ->expectsConfirmation(
                'Are you SURE you want to push to [staging]? This action cannot be undone.',
                'yes'
            )
            ->expectsOutputToContain('Failed to push app/public')
            ->assertFailed();

        if (is_dir($testPath) && count(scandir($testPath)) == 2) {
            rmdir($testPath);
        }
    });
});
