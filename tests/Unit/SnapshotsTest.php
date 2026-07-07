<?php

use Illuminate\Support\Facades\Process;
use Noo\LaravelRemoteSync\Remotes\Connection;
use Noo\LaravelRemoteSync\Remotes\Remote;
use Noo\LaravelRemoteSync\Remotes\RemoteInfo;
use Noo\LaravelRemoteSync\Snapshots\Snapshots;

function snapshotsFixture(): Snapshots
{
    $connection = new Connection(new Remote(
        name: 'production',
        host: 'forge@prod.acme.test',
        path: '/home/forge/acme',
    ));

    $info = new RemoteInfo(
        phpBinary: 'php8.4',
        isAtomic: true,
        workingPath: '/home/forge/acme/current',
        snapshotDir: '/home/forge/acme/current/storage/snapshots',
        driver: 'mysql',
        tables: ['users'],
        hasDbSnapshots: true,
        hasRsync: true,
    );

    return new Snapshots($connection, $info);
}

describe('Snapshots', function () {
    describe('naming', function () {
        it('generates prefixed names', function () {
            expect(Snapshots::transferName())->toMatch('/^remote-sync-\d{4}-\d{2}-\d{2}-\d{2}-\d{2}-\d{2}-[0-9a-f]{8}$/')
                ->and(Snapshots::pullBackupName())->toStartWith('pre-pull-')
                ->and(Snapshots::pushBackupName())->toStartWith('pre-push-');
        });

        it('recognizes its own snapshots by prefix', function () {
            expect(Snapshots::isOwnSnapshot('remote-sync-2026-07-07-10-00-00-a1b2c3d4'))->toBeTrue()
                ->and(Snapshots::isOwnSnapshot('pre-pull-2026-07-07-10-00-00'))->toBeTrue()
                ->and(Snapshots::isOwnSnapshot('pre-push-2026-07-07-10-00-00'))->toBeTrue()
                ->and(Snapshots::isOwnSnapshot('my-manual-snapshot'))->toBeFalse();
        });
    });

    describe('local storage', function () {
        it('uses the db-snapshots disk root', function () {
            config()->set('db-snapshots.disk', 'snapshots');
            config()->set('filesystems.disks.snapshots.root', '/custom/snapshots');

            expect(Snapshots::localDir())->toBe('/custom/snapshots')
                ->and(Snapshots::localPath('foo'))->toBe('/custom/snapshots/foo.sql.gz');
        });

        it('falls back to storage/snapshots when the disk has no root', function () {
            config()->set('db-snapshots.disk', 'missing');
            config()->set('filesystems.disks.missing', null);

            expect(Snapshots::localDir())->toBe(storage_path('snapshots'));
        });

        it('deletes a local snapshot file when present', function () {
            $dir = Snapshots::localDir();

            if (! is_dir($dir)) {
                mkdir($dir, 0755, true);
            }

            touch("{$dir}/doomed.sql.gz");

            expect(Snapshots::deleteLocal('doomed'))->toBeTrue()
                ->and(file_exists("{$dir}/doomed.sql.gz"))->toBeFalse()
                ->and(Snapshots::deleteLocal('doomed'))->toBeFalse();
        });

        it('verifies gzip integrity via gzip -t', function () {
            Process::fake(['*' => Process::result()]);

            expect(Snapshots::verifyGzip('snap'))->toBeTrue();

            Process::assertRan(fn ($process) => $process->command === ['gzip', '-t', Snapshots::localPath('snap')]);
        });

        it('reports a corrupt snapshot', function () {
            Process::fake(['*' => Process::result(exitCode: 1, errorOutput: 'unexpected end of file')]);

            expect(Snapshots::verifyGzip('snap'))->toBeFalse();
        });
    });

    describe('remote operations', function () {
        it('creates a remote snapshot with excluded tables', function () {
            Process::fake();

            snapshotsFixture()->createRemote('remote-sync-snap', ['cache', 'jobs']);

            Process::assertRan(fn ($process) => $process->command === [
                'ssh',
                'forge@prod.acme.test',
                "cd '/home/forge/acme/current' && 'php8.4' artisan snapshot:create 'remote-sync-snap' --exclude='cache' --exclude='jobs' --compress",
            ]);
        });

        it('creates a full remote snapshot without excludes', function () {
            Process::fake();

            snapshotsFixture()->createRemote('remote-sync-snap');

            Process::assertRan(fn ($process) => str_contains($process->command[2], "snapshot:create 'remote-sync-snap' --compress"));
        });

        it('loads a snapshot on the remote without dropping tables', function () {
            Process::fake();

            snapshotsFixture()->loadRemote('remote-sync-snap');

            Process::assertRan(fn ($process) => str_contains(
                $process->command[2],
                "snapshot:load 'remote-sync-snap' --force --drop-tables=0"
            ));
        });

        it('deletes a remote snapshot non-interactively', function () {
            Process::fake();

            snapshotsFixture()->deleteRemote('remote-sync-snap');

            Process::assertRan(fn ($process) => str_contains(
                $process->command[2],
                "snapshot:delete 'remote-sync-snap' --no-interaction"
            ));
        });

        it('downloads from the discovered remote snapshot dir into the local dir', function () {
            Process::fake();

            snapshotsFixture()->download('remote-sync-snap');

            Process::assertRan(fn ($process) => $process->command === [
                'rsync', '-az', '--partial',
                'forge@prod.acme.test:/home/forge/acme/current/storage/snapshots/remote-sync-snap.sql.gz',
                Snapshots::localDir().'/',
            ]);
        });

        it('uploads into the discovered remote snapshot dir', function () {
            Process::fake();

            snapshotsFixture()->upload('remote-sync-snap');

            Process::assertRan(fn ($process) => $process->command === [
                'rsync', '-az', '--partial',
                Snapshots::localPath('remote-sync-snap'),
                'forge@prod.acme.test:/home/forge/acme/current/storage/snapshots/',
            ]);
        });

        it('lists remote snapshots newest first', function () {
            Process::fake(['*' => Process::result(output: implode("\n", [
                '1751900000 /snapshots/remote-sync-b.sql.gz',
                '1751800000 /snapshots/pre-push-a.sql.gz',
            ]))]);

            $snapshots = snapshotsFixture()->listRemote();

            expect($snapshots)->toHaveCount(2)
                ->and($snapshots[0]['name'])->toBe('remote-sync-b')
                ->and($snapshots[0]['mtime'])->toBe(1751900000)
                ->and($snapshots[1]['name'])->toBe('pre-push-a');
        });

        it('returns an empty list when the remote listing fails', function () {
            Process::fake(['*' => Process::result(exitCode: 255, errorOutput: 'ssh: connection refused')]);

            expect(snapshotsFixture()->listRemote())->toBe([]);
        });
    });
});
