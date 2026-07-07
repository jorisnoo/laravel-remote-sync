<?php

use Illuminate\Support\Facades\Process;

function pullTestConfig(): void
{
    config()->set('remote-sync.remotes', [
        'staging' => [
            'host' => 'forge@staging.acme.test',
            'path' => '/home/forge/acme',
        ],
    ]);
    config()->set('remote-sync.paths', ['app']);
    config()->set('remote-sync.exclude_tables', ['cache', 'jobs']);
    config()->set('remote-sync.filter_users', false);
    config()->set('cache.default', 'array');
}

beforeEach(function () {
    pullTestConfig();
});

describe('remote-sync:pull', function () {
    describe('guards', function () {
        it('refuses to run in production', function () {
            $this->app['env'] = 'production';

            $this->artisan('remote-sync:pull', ['--database' => true, '--force' => true])
                ->expectsOutputToContain('Refusing to run in production')
                ->assertFailed();

            $this->app['env'] = 'testing';
        });

        it('rejects placeholder configuration with the env var to set', function () {
            config()->set('remote-sync.remotes', [
                'production' => ['host' => 'forge@your-server', 'path' => '/home/forge/acme'],
            ]);

            $this->artisan('remote-sync:pull', ['--database' => true, '--force' => true])
                ->expectsOutputToContain('REMOTE_SYNC_PRODUCTION_HOST')
                ->assertFailed();
        });

        it('fails non-interactively when the host key is unknown', function () {
            fakeSyncProcesses(custom: [
                'BatchMode=yes' => Process::result(exitCode: 255, errorOutput: 'Host key verification failed.'),
            ]);

            $this->artisan('remote-sync:pull', ['--database' => true, '--force' => true, '--no-interaction' => true])
                ->expectsOutputToContain('StrictHostKeyChecking=accept-new')
                ->assertFailed();
        });

        it('refuses when the host key has changed', function () {
            fakeSyncProcesses(custom: [
                'BatchMode=yes' => Process::result(exitCode: 255, errorOutput: 'REMOTE HOST IDENTIFICATION HAS CHANGED'),
            ]);

            $this->artisan('remote-sync:pull', ['--database' => true, '--force' => true])
                ->expectsOutputToContain('man-in-the-middle')
                ->assertFailed();
        });

        it('aborts on a database driver mismatch before touching anything', function () {
            fakeSyncProcesses(probeOverrides: ['json' => ['driver' => 'mysql']]);
            $snapshots = mockSnapshots();
            $snapshots->shouldNotReceive('createRemote');
            mockImporter();

            $this->artisan('remote-sync:pull', ['--database' => true, '--force' => true])
                ->expectsOutputToContain('Database driver mismatch')
                ->assertFailed();
        });

        it('requires spatie/laravel-db-snapshots on the remote', function () {
            fakeSyncProcesses(probeOverrides: ['snapshots' => '0']);
            mockSnapshots();
            mockImporter();

            $this->artisan('remote-sync:pull', ['--database' => true, '--force' => true])
                ->expectsOutputToContain('spatie/laravel-db-snapshots is not installed')
                ->expectsOutputToContain('remote-sync:doctor')
                ->assertFailed();
        });

        it('rejects invalid --path values', function () {
            fakeSyncProcesses();
            mockSnapshots();
            mockImporter();

            $this->artisan('remote-sync:pull', [
                '--files' => true,
                '--force' => true,
                '--path' => ['app;rm -rf /'],
            ])
                ->expectsOutputToContain('Invalid path')
                ->assertFailed();
        });

        it('requires --force when running non-interactively', function () {
            fakeSyncProcesses();
            mockSnapshots();
            $importer = mockImporter();
            $importer->shouldNotReceive('import');

            $this->artisan('remote-sync:pull', ['--database' => true, '--no-interaction' => true])
                ->expectsOutputToContain('Confirmation required: re-run with --force.')
                ->assertFailed();
        });
    });

    describe('dry run', function () {
        it('prints the plan and changes nothing', function () {
            fakeSyncProcesses();
            $snapshots = mockSnapshots();
            $snapshots->shouldNotReceive('createRemote');
            $importer = mockImporter();
            $importer->shouldNotReceive('import');

            $this->artisan('remote-sync:pull', [
                '--database' => true,
                '--files' => true,
                '--dry-run' => true,
                '--force' => true,
            ])
                ->expectsOutputToContain('Pull from staging')
                ->expectsOutputToContain('Dry run - nothing was changed.')
                ->assertSuccessful();
        });

        it('points at --delete when deletable files exist but deletion is off', function () {
            fakeSyncProcesses();
            mockSnapshots();
            mockImporter();

            $this->artisan('remote-sync:pull', ['--files' => true, '--dry-run' => true, '--force' => true])
                ->expectsOutputToContain('pass --delete to remove 1 local-only files')
                ->assertSuccessful();
        });
    });

    describe('execution', function () {
        it('pulls database and files end to end', function () {
            fakeSyncProcesses();
            $snapshots = mockSnapshots();
            $importer = mockImporter();

            $snapshots->shouldReceive('createLocal')->once()->withArgs(fn ($name) => str_starts_with($name, 'pre-pull-'))->andReturn(0);
            $snapshots->shouldReceive('createRemote')->once()->withArgs(
                fn ($name, $excludes) => str_starts_with($name, 'remote-sync-') && $excludes === ['cache', 'jobs']
            )->andReturn(Process::result());
            $snapshots->shouldReceive('download')->once()->andReturn(Process::result());
            $importer->shouldReceive('import')->once()->withArgs(
                fn ($name, $dropTables) => str_starts_with($name, 'remote-sync-') && $dropTables === false
            )->andReturn(Process::result());
            $snapshots->shouldReceive('deleteRemote')->once()->andReturn(Process::result());

            $this->artisan('remote-sync:pull', ['--database' => true, '--files' => true, '--force' => true])
                ->expectsOutputToContain('Pulled database and files from [staging].')
                ->assertSuccessful();

            // The real file transfer ran, without --delete (--force must not imply it).
            Process::assertRan(function ($process) {
                $command = is_array($process->command) ? implode(' ', $process->command) : $process->command;

                return str_contains($command, 'rsync')
                    && str_contains($command, '/home/forge/acme/storage/app/')
                    && ! str_contains($command, '--dry-run')
                    && ! str_contains($command, '--delete');
            });
        });

        it('mirrors deletions only with an explicit --delete', function () {
            fakeSyncProcesses();
            mockSnapshots();
            mockImporter();

            $this->artisan('remote-sync:pull', ['--files' => true, '--delete' => true, '--force' => true])
                ->assertSuccessful();

            Process::assertRan(function ($process) {
                $command = is_array($process->command) ? implode(' ', $process->command) : $process->command;

                return str_contains($command, 'rsync')
                    && ! str_contains($command, '--dry-run')
                    && str_contains($command, '--delete');
            });
        });

        it('skips the backup with --no-backup', function () {
            fakeSyncProcesses();
            $snapshots = mockSnapshots();
            $snapshots->shouldNotReceive('createLocal');
            mockImporter();

            $this->artisan('remote-sync:pull', ['--database' => true, '--no-backup' => true, '--force' => true])
                ->assertSuccessful();
        });

        it('imports with dropped tables in full mode', function () {
            fakeSyncProcesses();
            $snapshots = mockSnapshots();
            $snapshots->shouldReceive('createRemote')->once()->withArgs(fn ($name, $excludes) => $excludes === [])->andReturn(Process::result());
            $importer = mockImporter();
            $importer->shouldReceive('import')->once()->withArgs(fn ($name, $dropTables) => $dropTables === true)->andReturn(Process::result());
            $importer->shouldNotReceive('truncateExcluded');

            $this->artisan('remote-sync:pull', ['--database' => true, '--full' => true, '--force' => true])
                ->expectsOutputToContain('All local tables will be DROPPED')
                ->assertSuccessful();
        });

        it('keeps the snapshot and prints restore guidance when the import fails', function () {
            fakeSyncProcesses();
            $snapshots = mockSnapshots();
            $importer = mockImporter();
            $importer->shouldReceive('import')->once()->andReturn(
                Process::result(exitCode: 1, errorOutput: 'ERROR 1064 at line 2041')
            );
            // The temporary remote snapshot is still cleaned up.
            $snapshots->shouldReceive('deleteRemote')->once()->andReturn(Process::result());

            $this->artisan('remote-sync:pull', ['--database' => true, '--force' => true])
                ->expectsOutputToContain('Import failed: ERROR 1064')
                ->expectsOutputToContain('php artisan snapshot:load pre-pull-')
                ->expectsOutputToContain('kept for inspection')
                ->assertFailed();
        });

        it('aborts before importing when the snapshot is corrupt', function () {
            fakeSyncProcesses(custom: [
                'gzip -t' => Process::result(exitCode: 1, errorOutput: 'unexpected end of file'),
            ]);
            mockSnapshots();
            $importer = mockImporter();
            $importer->shouldNotReceive('import');

            $this->artisan('remote-sync:pull', ['--database' => true, '--force' => true])
                ->expectsOutputToContain('integrity check')
                ->assertFailed();
        });

        it('warns instead of deleting when filter_users matches nobody', function () {
            fakeSyncProcesses();
            mockSnapshots();
            $importer = mockImporter();
            $importer->shouldReceive('filterUsers')->once()->andReturn(['kept' => 0, 'deleted' => 0, 'skipped' => true]);

            $this->artisan('remote-sync:pull', ['--database' => true, '--force' => true])
                ->expectsOutputToContain('filter_users matched no users')
                ->assertSuccessful();
        });
    });

    describe('interactive flow', function () {
        it('asks for scope and one confirmation, defaulting to both', function () {
            fakeSyncProcesses();
            mockSnapshots();
            mockImporter();

            $this->artisan('remote-sync:pull')
                ->expectsChoice('What would you like to pull?', ['database', 'files'], [
                    'database' => 'Database',
                    'files' => 'Files',
                ])
                ->expectsConfirmation('Replace your local database and files with data from [staging]?', 'yes')
                ->expectsOutputToContain('Pulled database and files from [staging].')
                ->assertSuccessful();
        });

        it('selects the remote when several are configured', function () {
            config()->set('remote-sync.remotes', [
                'production' => ['host' => 'forge@prod.acme.test', 'path' => '/home/forge/acme'],
                'staging' => ['host' => 'forge@staging.acme.test', 'path' => '/home/forge/acme'],
            ]);
            fakeSyncProcesses();
            mockSnapshots();
            mockImporter();

            $this->artisan('remote-sync:pull', ['--database' => true])
                ->expectsChoice('Select remote environment', 'staging', ['production', 'staging'])
                ->expectsConfirmation('Replace your local database with data from [staging]?', 'yes')
                ->assertSuccessful();
        });
    });
});
