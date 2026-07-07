<?php

use Illuminate\Support\Facades\Process;

function pushTestConfig(bool $pushAllowed = true): void
{
    config()->set('remote-sync.remotes', [
        'staging' => [
            'host' => 'forge@staging.acme.test',
            'path' => '/home/forge/acme',
            'push' => $pushAllowed,
        ],
    ]);
    config()->set('remote-sync.paths', ['app']);
    config()->set('remote-sync.exclude_tables', ['cache', 'jobs']);
    config()->set('remote-sync.filter_users', false);
    config()->set('cache.default', 'array');
}

beforeEach(function () {
    pushTestConfig();
});

describe('remote-sync:push', function () {
    describe('guards', function () {
        it('refuses when push is not enabled for the remote', function () {
            pushTestConfig(pushAllowed: false);

            $this->artisan('remote-sync:push', ['--database' => true, '--force' => true])
                ->expectsOutputToContain('Push is not allowed for remote [staging]')
                ->assertFailed();
        });

        it('requires an explicit scope when running non-interactively', function () {
            fakeSyncProcesses();
            mockSnapshots();
            mockImporter();

            $this->artisan('remote-sync:push', ['--force' => true, '--no-interaction' => true])
                ->expectsOutputToContain('Specify --database and/or --files')
                ->assertFailed();
        });

        it('refuses to run in production', function () {
            $this->app['env'] = 'production';

            $this->artisan('remote-sync:push', ['--database' => true, '--force' => true])
                ->expectsOutputToContain('Refusing to run in production')
                ->assertFailed();

            $this->app['env'] = 'testing';
        });

        it('requires --force when running non-interactively', function () {
            fakeSyncProcesses();
            $snapshots = mockSnapshots();
            $snapshots->shouldNotReceive('upload');
            mockImporter();

            $this->artisan('remote-sync:push', ['--database' => true, '--no-interaction' => true])
                ->expectsOutputToContain('Confirmation required: re-run with --force.')
                ->assertFailed();
        });

        it('aborts on a database driver mismatch', function () {
            fakeSyncProcesses(probeOverrides: ['json' => ['driver' => 'pgsql']]);
            $snapshots = mockSnapshots();
            $snapshots->shouldNotReceive('createRemote');
            mockImporter();

            $this->artisan('remote-sync:push', ['--database' => true, '--force' => true])
                ->expectsOutputToContain('Database driver mismatch')
                ->assertFailed();
        });
    });

    describe('dry run', function () {
        it('prints the plan and changes nothing', function () {
            fakeSyncProcesses();
            $snapshots = mockSnapshots();
            $snapshots->shouldNotReceive('upload');
            $snapshots->shouldNotReceive('loadRemote');
            mockImporter();

            $this->artisan('remote-sync:push', [
                '--database' => true,
                '--files' => true,
                '--dry-run' => true,
                '--force' => true,
            ])
                ->expectsOutputToContain('Push to staging')
                ->expectsOutputToContain('Dry run - nothing was changed.')
                ->assertSuccessful();
        });
    });

    describe('execution', function () {
        it('pushes the database end to end', function () {
            fakeSyncProcesses();
            $snapshots = mockSnapshots();
            mockImporter();

            $snapshots->shouldReceive('createRemote')->once()->withArgs(
                fn ($name, $excludes) => str_starts_with($name, 'pre-push-') && $excludes === ['cache', 'jobs']
            )->andReturn(Process::result());
            $snapshots->shouldReceive('createLocal')->once()->withArgs(
                fn ($name, $excludes) => str_starts_with($name, 'remote-sync-') && $excludes === ['cache', 'jobs']
            )->andReturn(0);
            $snapshots->shouldReceive('upload')->once()->andReturn(Process::result());
            $snapshots->shouldReceive('loadRemote')->once()->andReturn(Process::result());
            $snapshots->shouldReceive('deleteRemote')->once()->andReturn(Process::result());

            $this->artisan('remote-sync:push', ['--database' => true, '--force' => true])
                ->expectsOutputToContain('Pushed database to [staging].')
                ->assertSuccessful();

            // Remote migrations ran over ssh.
            Process::assertRan(function ($process) {
                $command = is_array($process->command) ? implode(' ', $process->command) : $process->command;

                return str_contains($command, 'artisan migrate --force');
            });
        });

        it('prints remote restore guidance when the load fails', function () {
            fakeSyncProcesses();
            $snapshots = mockSnapshots();
            mockImporter();

            $snapshots->shouldReceive('loadRemote')->once()->andReturn(
                Process::result(exitCode: 1, errorOutput: 'SQLSTATE[42S01]')
            );
            // Local and uploaded transfer snapshots are still cleaned up.
            $snapshots->shouldReceive('deleteRemote')->once()->andReturn(Process::result());

            $this->artisan('remote-sync:push', ['--database' => true, '--force' => true])
                ->expectsOutputToContain('Failed to load snapshot on remote')
                ->expectsOutputToContain('php artisan snapshot:load pre-push-')
                ->assertFailed();
        });

        it('pushes files without deleting unless --delete is given', function () {
            fakeSyncProcesses();
            mockSnapshots();
            mockImporter();

            $this->artisan('remote-sync:push', ['--files' => true, '--force' => true])
                ->expectsOutputToContain('Pushed files to [staging].')
                ->assertSuccessful();

            Process::assertRan(function ($process) {
                $command = is_array($process->command) ? implode(' ', $process->command) : $process->command;

                return str_contains($command, 'rsync')
                    && ! str_contains($command, '--dry-run')
                    && str_contains($command, 'forge@staging.acme.test:/home/forge/acme/storage/app/')
                    && ! str_contains($command, '--delete');
            });
        });

        it('mirrors deletions to the remote with an explicit --delete', function () {
            fakeSyncProcesses();
            mockSnapshots();
            mockImporter();

            $this->artisan('remote-sync:push', ['--files' => true, '--delete' => true, '--force' => true])
                ->assertSuccessful();

            Process::assertRan(function ($process) {
                $command = is_array($process->command) ? implode(' ', $process->command) : $process->command;

                return str_contains($command, 'rsync')
                    && ! str_contains($command, '--dry-run')
                    && str_contains($command, '--delete');
            });
        });

        it('skips the remote backup with --no-backup', function () {
            fakeSyncProcesses();
            $snapshots = mockSnapshots();
            $snapshots->shouldNotReceive('createRemote');
            mockImporter();

            $this->artisan('remote-sync:push', ['--database' => true, '--no-backup' => true, '--force' => true])
                ->assertSuccessful();
        });
    });

    describe('interactive flow', function () {
        it('defaults the scope to database only and pushes after a typed yes', function () {
            fakeSyncProcesses();
            mockSnapshots();
            mockImporter();

            $this->artisan('remote-sync:push')
                ->expectsChoice('What would you like to push?', ['database'], [
                    'database' => 'Database',
                    'files' => 'Files',
                ])
                ->expectsQuestion('Push your local database to [staging]? This will OVERWRITE remote data. Type "yes" to continue', 'yes')
                ->expectsOutputToContain('Pushed database to [staging].')
                ->assertSuccessful();
        });
    });
});
