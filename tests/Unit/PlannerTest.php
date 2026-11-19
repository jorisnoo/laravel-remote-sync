<?php

use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Schema;
use Noo\LaravelRemoteSync\Remotes\Connection;
use Noo\LaravelRemoteSync\Remotes\Remote;
use Noo\LaravelRemoteSync\Remotes\RemoteInfo;
use Noo\LaravelRemoteSync\Snapshots\Importer;
use Noo\LaravelRemoteSync\Sync\Direction;
use Noo\LaravelRemoteSync\Sync\Planner;
use Noo\LaravelRemoteSync\Sync\Rsync;

function makePlanner(?RemoteInfo $info = null): Planner
{
    $connection = new Connection(new Remote(
        name: 'staging',
        host: 'forge@staging.acme.test',
        path: '/home/forge/acme',
    ));

    $info ??= plannerInfo();

    return new Planner($info, new Rsync($connection), new Importer);
}

function plannerInfo(string $snapshotDir = '/home/forge/acme/storage/snapshots'): RemoteInfo
{
    return new RemoteInfo(
        phpBinary: 'php8.4',
        isAtomic: false,
        workingPath: '/home/forge/acme',
        snapshotDir: $snapshotDir,
        driver: 'mysql',
        tables: ['users', 'posts', 'cache', 'jobs', 'migrations'],
        hasDbSnapshots: true,
        hasRsync: true,
    );
}

describe('Planner', function () {
    beforeEach(function () {
        config()->set('remote-sync.exclude_tables', ['cache', 'jobs']);
        config()->set('remote-sync.filter_users', false);
    });

    describe('pull', function () {
        it('plans a standard database pull without excluded tables', function () {
            $plan = makePlanner()->pull(database: true, files: false);

            expect($plan->direction)->toBe(Direction::Pull)
                ->and($plan->tablesToSync)->toBe(['users', 'posts', 'migrations'])
                ->and($plan->snapshotName)->toStartWith('remote-sync-')
                ->and($plan->backupName)->toStartWith('pre-pull-');
        });

        it('keeps every table in full mode', function () {
            $plan = makePlanner()->pull(database: true, files: false, full: true);

            expect($plan->tablesToSync)->toBe(['users', 'posts', 'cache', 'jobs', 'migrations'])
                ->and($plan->tablesToTruncate)->toBe([]);
        });

        it('plans empty remote tables even when they do not exist locally', function () {
            $plan = makePlanner()->pull(database: true, files: false);

            expect($plan->tablesToTruncate)->toBe(['cache', 'jobs']);
        });

        it('skips the backup name when backups are disabled', function () {
            $plan = makePlanner()->pull(database: true, files: false, backup: false);

            expect($plan->backupName)->toBe('');
        });

        it('records filter_users rules only for database pulls', function () {
            config()->set('remote-sync.filter_users', ['*@acme.test']);

            $withDb = makePlanner()->pull(database: true, files: false);
            expect($withDb->filterUsers)->toBe(['*@acme.test']);

            Process::fake(['*' => Process::result(output: '')]);
            $filesOnly = makePlanner()->pull(database: false, files: true, paths: ['app']);
            expect($filesOnly->filterUsers)->toBeNull();
        });

        it('previews a missing nested destination without creating it', function (bool $fail) {
            $root = 'preview-'.bin2hex(random_bytes(8));
            $path = "{$root}/nested";
            $temporary = null;
            Process::fake(function ($process) use (&$temporary, $fail) {
                $temporary = rtrim(last($process->command), '/');
                expect(is_dir($temporary))->toBeTrue()
                    ->and(scandir($temporary))->toBe(['.', '..']);

                return $fail
                    ? Process::result(exitCode: 23, errorOutput: 'dry run interrupted')
                    : Process::result(output: '>f+++++++++ report.csv');
            });

            $plan = fn () => makePlanner()->pull(database: false, files: true, paths: [$path]);
            if ($fail) {
                expect($plan)->toThrow(RuntimeException::class, 'dry run interrupted');
            } else {
                expect($plan()->fileChanges[$path]->transfers)->toBe(['report.csv']);
            }

            expect($temporary)->not->toBeNull()
                ->and(file_exists($temporary))->toBeFalse()
                ->and(file_exists(storage_path($root)))->toBeFalse();
        })->with([true, false]);

        it('excludes all contents when syncing a snapshot directory or its descendants', function (string $path, bool $local) {
            Process::fake();
            $info = plannerInfo(snapshotDir: $local ? '/outside/storage' : '/home/forge/acme/storage/app/backups');
            config()->set('filesystems.disks.snapshots.root', $local ? storage_path('app/backups') : '/outside/storage');

            $plan = makePlanner($info)->pull(database: false, files: true, paths: [$path]);

            expect($plan->fileExcludes[$path])->toBe(['*']);
            Process::assertRan(fn ($process) => in_array('--exclude=*', $process->command, true));
        })->with([
            ['app/backups', true],
            ['app/backups/archive', true],
            ['app/backups', false],
            ['app/backups/archive', false],
        ]);

        it('always dry-runs with --delete so deletable files are discovered', function () {
            Process::fake(['*' => Process::result(output: "*deleting   stale.jpg\n>f+++++++++ new.jpg")]);

            $plan = makePlanner()->pull(database: false, files: true, delete: false, paths: ['app']);

            Process::assertRan(fn ($process) => in_array('--dry-run', $process->command, true)
                && in_array('--delete', $process->command, true));

            expect($plan->delete)->toBeFalse()
                ->and($plan->totalDeletions())->toBe(1)
                ->and($plan->totalTransfers())->toBe(1);
        });

        it('refuses file sync when the remote snapshot directory cannot be excluded', function () {
            Process::fake();
            $planner = makePlanner(plannerInfo(snapshotDir: ''));

            expect(fn () => $planner->pull(database: false, files: true, paths: ['app']))
                ->toThrow(RuntimeException::class, 'remote snapshot directory is unknown');
            expect(fn () => $planner->push(database: false, files: true, paths: ['app']))
                ->toThrow(RuntimeException::class, 'remote snapshot directory is unknown');
            Process::assertNothingRan();
        });

        it('excludes the remote snapshot dir when it lives inside a synced path', function () {
            Process::fake(['*' => Process::result(output: '')]);

            $info = plannerInfo(snapshotDir: '/home/forge/acme/storage/app/snapshots');

            $plan = makePlanner($info)->pull(database: false, files: true, paths: ['app']);

            expect($plan->fileExcludes['app'])->toBe(['/snapshots']);

            Process::assertRan(fn ($process) => in_array('--exclude=/snapshots', $process->command, true));
        });

        it('adds no snapshot excludes when snapshots live outside storage', function () {
            Process::fake(['*' => Process::result(output: '')]);

            config()->set('filesystems.disks.snapshots.root', '/somewhere/else/snapshots');
            $info = plannerInfo(snapshotDir: '/home/forge/acme/database/snapshots');

            $plan = makePlanner($info)->pull(database: false, files: true, paths: ['app']);

            expect($plan->fileExcludes['app'])->toBe([]);
        });

        it('surfaces dry-run failures with path context', function () {
            Process::fake(['*' => Process::result(exitCode: 23, errorOutput: 'No such file or directory')]);

            expect(fn () => makePlanner()->pull(database: false, files: true, paths: ['app']))
                ->toThrow(RuntimeException::class, 'Could not analyze files for storage/app');
        });
    });

    describe('push', function () {
        it('plans a database push preserving excluded remote tables', function () {
            Schema::create('users', fn ($table) => $table->increments('id'));
            Schema::create('cache', fn ($table) => $table->increments('id'));

            $plan = makePlanner()->push(database: true, files: false);

            expect($plan->direction)->toBe(Direction::Push)
                ->and($plan->tablesToSync)->toBe(['users'])
                ->and($plan->tablesToTruncate)->toBe(['cache', 'jobs'])
                ->and($plan->backupName)->toStartWith('pre-push-');
        });

        it('treats a missing local path as an empty transfer', function () {
            Process::fake();

            $plan = makePlanner()->push(database: false, files: true, paths: ['does-not-exist-'.uniqid()]);

            expect($plan->totalTransfers())->toBe(0);

            Process::assertNothingRan();
        });
    });
});
