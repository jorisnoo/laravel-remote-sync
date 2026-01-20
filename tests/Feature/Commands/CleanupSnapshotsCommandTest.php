<?php

use Illuminate\Contracts\Process\ProcessResult;
use Illuminate\Support\Facades\Process;
use Laravel\Prompts\Prompt;
use Noo\LaravelRemoteSync\Data\RemoteConfig;
use Noo\LaravelRemoteSync\RemoteSyncService;

beforeEach(function () {
    Process::fake([
        '*' => Process::result(output: 'no'),
    ]);

    $snapshotPath = storage_path('snapshots');
    if (! is_dir($snapshotPath)) {
        mkdir($snapshotPath, 0755, true);
    }
});

afterEach(function () {
    $snapshotPath = storage_path('snapshots');
    $files = glob("{$snapshotPath}/test-*.sql.gz");
    foreach ($files as $file) {
        if (file_exists($file)) {
            unlink($file);
        }
    }
});

describe('CleanupSnapshotsCommand', function () {
    it('shows no snapshots message when nothing to cleanup', function () {
        $this->setUpProductionRemote();

        Process::fake([
            '*' => Process::result(output: ''),
        ]);

        $this->artisan('remote-sync:cleanup-snapshots', ['remote' => 'production'])
            ->expectsOutputToContain('No snapshots to cleanup')
            ->assertSuccessful();
    });

    it('lists local snapshots to delete', function () {
        $this->setUpProductionRemote();
        $snapshotPath = storage_path('snapshots');

        touch("{$snapshotPath}/test-old-1.sql.gz", time() - 3600);
        touch("{$snapshotPath}/test-old-2.sql.gz", time() - 7200);
        touch("{$snapshotPath}/test-recent-1.sql.gz", time() - 60);

        Process::fake([
            '*' => Process::result(output: ''),
        ]);

        $this->artisan('remote-sync:cleanup-snapshots', [
            'remote' => 'production',
            '--local' => true,
            '--keep' => 1,
            '--dry-run' => true,
        ])
            ->expectsOutputToContain('Local snapshots to delete')
            ->expectsOutputToContain('Dry run mode')
            ->assertSuccessful();
    });

    it('supports dry-run mode', function () {
        $this->setUpProductionRemote();
        $snapshotPath = storage_path('snapshots');

        touch("{$snapshotPath}/test-old-1.sql.gz", time() - 3600);
        touch("{$snapshotPath}/test-recent-1.sql.gz", time() - 60);

        Process::fake([
            '*' => Process::result(output: ''),
        ]);

        $this->artisan('remote-sync:cleanup-snapshots', [
            'remote' => 'production',
            '--local' => true,
            '--keep' => 1,
            '--dry-run' => true,
        ])
            ->expectsOutputToContain('Dry run mode - no files were deleted')
            ->assertSuccessful();

        expect(file_exists("{$snapshotPath}/test-old-1.sql.gz"))->toBeTrue();
    });

    it('deletes old snapshots based on retention count', function () {
        $this->setUpProductionRemote();
        $snapshotPath = storage_path('snapshots');

        touch("{$snapshotPath}/test-old-1.sql.gz", time() - 3600);
        touch("{$snapshotPath}/test-recent-1.sql.gz", time() - 60);

        Process::fake([
            '*' => Process::result(output: ''),
        ]);

        $this->artisan('remote-sync:cleanup-snapshots', [
            'remote' => 'production',
            '--local' => true,
            '--keep' => 1,
            '--force' => true,
        ])
            ->expectsOutputToContain('Deleted 1 local snapshot')
            ->assertSuccessful();

        expect(file_exists("{$snapshotPath}/test-old-1.sql.gz"))->toBeFalse();
        expect(file_exists("{$snapshotPath}/test-recent-1.sql.gz"))->toBeTrue();
    });

    it('displays snapshots that would be deleted and deletes with force flag', function () {
        $this->setUpProductionRemote();
        $snapshotPath = storage_path('snapshots');

        touch("{$snapshotPath}/test-old-1.sql.gz", time() - 3600);
        touch("{$snapshotPath}/test-recent-1.sql.gz", time() - 60);

        Process::fake([
            '*' => Process::result(output: ''),
        ]);

        $this->artisan('remote-sync:cleanup-snapshots', [
            'remote' => 'production',
            '--local' => true,
            '--keep' => 1,
            '--force' => true,
        ])
            ->assertSuccessful();

        expect(file_exists("{$snapshotPath}/test-old-1.sql.gz"))->toBeFalse();
        expect(file_exists("{$snapshotPath}/test-recent-1.sql.gz"))->toBeTrue();
    });

    it('skips confirmation with --force flag', function () {
        $this->setUpProductionRemote();
        $snapshotPath = storage_path('snapshots');

        touch("{$snapshotPath}/test-old-1.sql.gz", time() - 3600);
        touch("{$snapshotPath}/test-recent-1.sql.gz", time() - 60);

        Process::fake([
            '*' => Process::result(output: ''),
        ]);

        $this->artisan('remote-sync:cleanup-snapshots', [
            'remote' => 'production',
            '--local' => true,
            '--keep' => 1,
            '--force' => true,
        ])
            ->expectsOutputToContain('Deleted 1 local snapshot')
            ->assertSuccessful();
    });

    it('cleans up remote snapshots', function () {
        $this->setUpProductionRemote();

        $timestamp1 = time() - 3600;
        $timestamp2 = time() - 60;

        $mockResult = Mockery::mock(ProcessResult::class);
        $mockResult->shouldReceive('successful')->andReturn(true);
        $mockResult->shouldReceive('output')->andReturn("{$timestamp1} /path/to/test-old.sql.gz\n{$timestamp2} /path/to/test-recent.sql.gz");

        $mockDeleteResult = Mockery::mock(ProcessResult::class);
        $mockDeleteResult->shouldReceive('successful')->andReturn(true);
        $mockDeleteResult->shouldReceive('output')->andReturn('Deleted');

        $this->mock(RemoteSyncService::class, function ($mock) use ($mockResult, $mockDeleteResult) {
            $mock->shouldReceive('getRemote')
                ->andReturn(new RemoteConfig(
                    name: 'production',
                    host: 'user@production.example.com',
                    path: '/var/www/app',
                ));

            $mock->shouldReceive('isAtomicDeployment')
                ->andReturn(false);

            $mock->shouldReceive('getAvailableRemotes')
                ->andReturn(['production']);

            $mock->shouldReceive('listRemoteSnapshots')
                ->once()
                ->andReturn($mockResult);

            $mock->shouldReceive('deleteRemoteSnapshot')
                ->once()
                ->andReturn($mockDeleteResult);
        });

        $this->artisan('remote-sync:cleanup-snapshots', [
            'remote' => 'production',
            '--remote' => true,
            '--keep' => 1,
            '--force' => true,
        ])
            ->expectsOutputToContain('Deleted 1 remote snapshot')
            ->assertSuccessful();
    });

    it('cleans up both local and remote with both flags', function () {
        $this->setUpProductionRemote();
        $snapshotPath = storage_path('snapshots');

        touch("{$snapshotPath}/test-old-1.sql.gz", time() - 3600);
        touch("{$snapshotPath}/test-recent-1.sql.gz", time() - 60);

        $timestamp1 = time() - 3600;
        $timestamp2 = time() - 60;

        $mockResult = Mockery::mock(ProcessResult::class);
        $mockResult->shouldReceive('successful')->andReturn(true);
        $mockResult->shouldReceive('output')->andReturn("{$timestamp1} /path/to/test-old.sql.gz\n{$timestamp2} /path/to/test-recent.sql.gz");

        $mockDeleteResult = Mockery::mock(ProcessResult::class);
        $mockDeleteResult->shouldReceive('successful')->andReturn(true);
        $mockDeleteResult->shouldReceive('output')->andReturn('Deleted');

        $this->mock(RemoteSyncService::class, function ($mock) use ($mockResult, $mockDeleteResult, $snapshotPath) {
            $mock->shouldReceive('getRemote')
                ->andReturn(new RemoteConfig(
                    name: 'production',
                    host: 'user@production.example.com',
                    path: '/var/www/app',
                ));

            $mock->shouldReceive('isAtomicDeployment')
                ->andReturn(false);

            $mock->shouldReceive('getAvailableRemotes')
                ->andReturn(['production']);

            $mock->shouldReceive('getSnapshotPath')
                ->andReturn($snapshotPath);

            $mock->shouldReceive('listRemoteSnapshots')
                ->once()
                ->andReturn($mockResult);

            $mock->shouldReceive('deleteRemoteSnapshot')
                ->once()
                ->andReturn($mockDeleteResult);
        });

        $this->artisan('remote-sync:cleanup-snapshots', [
            'remote' => 'production',
            '--local' => true,
            '--remote' => true,
            '--keep' => 1,
            '--force' => true,
        ])
            ->expectsOutputToContain('Deleted 1 local snapshot')
            ->expectsOutputToContain('Deleted 1 remote snapshot')
            ->assertSuccessful();
    });

    it('uses default keep value of 5', function () {
        $this->setUpProductionRemote();
        $snapshotPath = storage_path('snapshots');

        for ($i = 1; $i <= 7; $i++) {
            touch("{$snapshotPath}/test-snapshot-{$i}.sql.gz", time() - ($i * 3600));
        }

        Process::fake([
            '*' => Process::result(output: ''),
        ]);

        $this->artisan('remote-sync:cleanup-snapshots', [
            'remote' => 'production',
            '--local' => true,
            '--force' => true,
        ])
            ->expectsOutputToContain('Deleted 2 local snapshots')
            ->assertSuccessful();
    });

    it('uses single remote when only one is configured', function () {
        $this->setUpProductionRemote();

        Process::fake([
            '*' => Process::result(output: ''),
        ]);

        $this->artisan('remote-sync:cleanup-snapshots')
            ->expectsOutputToContain('No snapshots to cleanup')
            ->assertSuccessful();
    });

    it('cleans up local by default when no flags provided', function () {
        $this->setUpProductionRemote();
        $snapshotPath = storage_path('snapshots');

        touch("{$snapshotPath}/test-old-1.sql.gz", time() - 3600);
        touch("{$snapshotPath}/test-recent-1.sql.gz", time() - 60);

        Process::fake([
            'ssh*stat*' => Process::result(output: ''),
            '*' => Process::result(output: 'no'),
        ]);

        $this->artisan('remote-sync:cleanup-snapshots', [
            'remote' => 'production',
            '--keep' => 1,
            '--force' => true,
        ])
            ->expectsOutputToContain('Deleted 1 local snapshot')
            ->assertSuccessful();
    });

    it('warns when remote snapshot deletion fails', function () {
        $this->setUpProductionRemote();

        $timestamp1 = time() - 3600;
        $timestamp2 = time() - 60;

        $mockResult = Mockery::mock(ProcessResult::class);
        $mockResult->shouldReceive('successful')->andReturn(true);
        $mockResult->shouldReceive('output')->andReturn("{$timestamp1} /path/to/test-old.sql.gz\n{$timestamp2} /path/to/test-recent.sql.gz");

        $mockDeleteResult = Mockery::mock(ProcessResult::class);
        $mockDeleteResult->shouldReceive('successful')->andReturn(false);
        $mockDeleteResult->shouldReceive('errorOutput')->andReturn('Failed');

        $this->mock(RemoteSyncService::class, function ($mock) use ($mockResult, $mockDeleteResult) {
            $mock->shouldReceive('getRemote')
                ->andReturn(new RemoteConfig(
                    name: 'production',
                    host: 'user@production.example.com',
                    path: '/var/www/app',
                ));

            $mock->shouldReceive('isAtomicDeployment')
                ->andReturn(false);

            $mock->shouldReceive('getAvailableRemotes')
                ->andReturn(['production']);

            $mock->shouldReceive('listRemoteSnapshots')
                ->once()
                ->andReturn($mockResult);

            $mock->shouldReceive('deleteRemoteSnapshot')
                ->once()
                ->andReturn($mockDeleteResult);
        });

        $this->artisan('remote-sync:cleanup-snapshots', [
            'remote' => 'production',
            '--remote' => true,
            '--keep' => 1,
            '--force' => true,
        ])
            ->expectsOutputToContain('Failed to delete remote snapshot')
            ->assertFailed();
    });
});
