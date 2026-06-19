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

describe('PullRemoteCommand', function () {
    it('confirms once when pulling both database and files', function () {
        $this->setUpProductionRemote();
        config()->set('database.connections.testing.driver', 'mysql');
        config()->set('remote-sync.exclude_tables', []);
        config()->set('remote-sync.paths', ['app/public']);

        $result = Mockery::mock(ProcessResult::class);
        $result->shouldReceive('successful')->andReturn(true);
        $result->shouldReceive('output')->andReturn('');

        $remoteConfig = new RemoteConfig(
            name: 'production',
            host: 'user@production.example.com',
            path: '/var/www/app',
        );

        $this->mock(RemoteSyncService::class, function ($mock) use ($result, $remoteConfig) {
            $mock->shouldReceive('getRemote')->andReturn($remoteConfig);
            $mock->shouldReceive('checkHostKey')->andReturn('ok');
            $mock->shouldReceive('isAtomicDeployment')->andReturn(false);
            $mock->shouldReceive('getRemoteDatabaseInfo')->andReturn(['driver' => 'mysql', 'tables' => [], 'migrations' => []]);
            $mock->shouldReceive('getLocalTableNames')->andReturn([]);
            $mock->shouldReceive('getLocalMigrationRecords')->andReturn([]);
            $mock->shouldReceive('createRemoteSnapshot')->once()->andReturn($result);
            $mock->shouldReceive('getSnapshotPath')->andReturn(storage_path('snapshots'));
            $mock->shouldReceive('downloadSnapshot')->once()->andReturn($result);
            $mock->shouldReceive('loadSnapshotViaCli')->once()->andReturn($result);
            $mock->shouldReceive('deleteRemoteSnapshot')->once()->andReturn($result);
            $mock->shouldReceive('rsyncDryRun')->andReturn($result);
            $mock->shouldReceive('rsync')->once()->andReturn($result);
        });

        // A single typed "yes" confirmation must cover both database and files.
        // If the command asked twice, the unanswered second prompt would fail this test.
        $this->artisan('remote-sync:pull', [
            'remote' => 'production',
            '--no-backup' => true,
            '--full' => true,
            '--path' => 'app/public',
            '--delete' => true,
            '--no-clear-cache' => true,
        ])
            ->expectsQuestion('What would you like to pull?', ['database', 'files'])
            ->expectsQuestion('Files: Preview changes first?', 'no')
            ->expectsQuestion('This will replace your local database and files with data from [production]. Type "yes" to continue', 'yes')
            ->expectsOutputToContain('Database pulled from [production]')
            ->expectsOutputToContain('Files pulled from [production]')
            ->assertSuccessful();
    });

    it('warns and asks for confirmation in production environment', function () {
        app()->detectEnvironment(fn () => 'production');
        $this->setUpProductionRemote();

        $this->artisan('remote-sync:pull', ['remote' => 'production'])
            ->expectsOutputToContain('PRODUCTION environment')
            ->expectsConfirmation('Are you sure you want to continue in production?', 'no')
            ->assertSuccessful();
    });

    it('fails when no remotes are configured', function () {
        config()->set('remote-sync.remotes', []);

        $this->artisan('remote-sync:pull')
            ->assertFailed()
            ->expectsOutputToContain('No remote environment selected');
    });
});
