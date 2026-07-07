<?php

use Illuminate\Support\Facades\Process;
use Noo\LaravelRemoteSync\Snapshots\Snapshots;

function pruneTestConfig(): void
{
    config()->set('remote-sync.remotes', [
        'staging' => [
            'host' => 'forge@staging.acme.test',
            'path' => '/home/forge/acme',
        ],
    ]);
}

/**
 * @param  array<string, int>  $files  name => age in seconds
 */
function createLocalSnapshots(array $files): void
{
    $dir = Snapshots::localDir();

    if (! is_dir($dir)) {
        mkdir($dir, 0755, true);
    }

    foreach ($files as $name => $age) {
        $path = "{$dir}/{$name}.sql.gz";
        file_put_contents($path, 'x');
        touch($path, time() - $age);
    }
}

function localSnapshotNames(): array
{
    return array_map(
        fn (array $snapshot) => $snapshot['name'],
        Snapshots::listLocal()
    );
}

beforeEach(function () {
    pruneTestConfig();
});

afterEach(function () {
    foreach (glob(Snapshots::localDir().'/*.sql.gz') ?: [] as $file) {
        unlink($file);
    }
});

describe('remote-sync:prune', function () {
    it('keeps the most recent snapshots and deletes the rest', function () {
        createLocalSnapshots([
            'remote-sync-newest' => 10,
            'remote-sync-middle' => 20,
            'pre-pull-old' => 30,
            'pre-push-oldest' => 40,
        ]);

        $this->artisan('remote-sync:prune', ['--local' => true, '--keep' => 2, '--force' => true])
            ->expectsOutputToContain('Deleted 2 local snapshots.')
            ->assertSuccessful();

        expect(localSnapshotNames())->toBe(['remote-sync-newest', 'remote-sync-middle']);
    });

    it('leaves snapshots it did not create alone', function () {
        createLocalSnapshots([
            'remote-sync-a' => 10,
            'my-precious-snapshot' => 20,
            'pre-pull-b' => 30,
        ]);

        $this->artisan('remote-sync:prune', ['--local' => true, '--keep' => 1, '--force' => true])
            ->assertSuccessful();

        expect(localSnapshotNames())->toBe(['remote-sync-a', 'my-precious-snapshot']);
    });

    it('prunes foreign snapshots too with --all', function () {
        createLocalSnapshots([
            'remote-sync-a' => 10,
            'my-precious-snapshot' => 20,
        ]);

        $this->artisan('remote-sync:prune', ['--local' => true, '--keep' => 1, '--all' => true, '--force' => true])
            ->assertSuccessful();

        expect(localSnapshotNames())->toBe(['remote-sync-a']);
    });

    it('deletes nothing on a dry run', function () {
        createLocalSnapshots([
            'remote-sync-a' => 10,
            'remote-sync-b' => 20,
        ]);

        $this->artisan('remote-sync:prune', ['--local' => true, '--keep' => 0, '--dry-run' => true])
            ->expectsOutputToContain('remote-sync-b')
            ->expectsOutputToContain('Dry run - nothing was changed.')
            ->assertSuccessful();

        expect(localSnapshotNames())->toHaveCount(2);
    });

    it('requires --force when running non-interactively', function () {
        createLocalSnapshots(['remote-sync-a' => 10, 'remote-sync-b' => 20]);

        $this->artisan('remote-sync:prune', ['--local' => true, '--keep' => 0, '--no-interaction' => true])
            ->expectsOutputToContain('Confirmation required: re-run with --force.')
            ->assertFailed();

        expect(localSnapshotNames())->toHaveCount(2);
    });

    it('reports when there is nothing to prune', function () {
        $this->artisan('remote-sync:prune', ['--local' => true, '--force' => true])
            ->expectsOutputToContain('Nothing to prune')
            ->assertSuccessful();
    });

    it('never talks to the remote when pruning locally', function () {
        Process::fake();
        createLocalSnapshots(['remote-sync-a' => 10]);

        $this->artisan('remote-sync:prune', ['--local' => true, '--keep' => 0, '--force' => true])
            ->assertSuccessful();

        Process::assertNothingRan();
    });

    it('prunes package-prefixed snapshots on the remote', function () {
        fakeSyncProcesses();
        $snapshots = mockSnapshots();
        $snapshots->shouldReceive('listRemote')->andReturn([
            ['name' => 'remote-sync-new', 'path' => '/s/remote-sync-new.sql.gz', 'mtime' => time() - 10],
            ['name' => 'pre-push-old', 'path' => '/s/pre-push-old.sql.gz', 'mtime' => time() - 20],
            ['name' => 'someone-elses', 'path' => '/s/someone-elses.sql.gz', 'mtime' => time() - 30],
        ]);
        $snapshots->shouldReceive('deleteRemote')->once()->with('pre-push-old')->andReturn(Process::result());

        $this->artisan('remote-sync:prune', ['--remote' => true, '--keep' => 1, '--force' => true])
            ->expectsOutputToContain('Deleted 1 remote snapshot.')
            ->assertSuccessful();
    });

    it('exits non-zero when a remote deletion fails', function () {
        fakeSyncProcesses();
        $snapshots = mockSnapshots();
        $snapshots->shouldReceive('listRemote')->andReturn([
            ['name' => 'remote-sync-new', 'path' => '/s/a.sql.gz', 'mtime' => time() - 10],
            ['name' => 'remote-sync-old', 'path' => '/s/b.sql.gz', 'mtime' => time() - 20],
        ]);
        $snapshots->shouldReceive('deleteRemote')->once()->andReturn(Process::result(exitCode: 1, errorOutput: 'nope'));

        $this->artisan('remote-sync:prune', ['--remote' => true, '--keep' => 1, '--force' => true])
            ->expectsOutputToContain('Failed to delete remote snapshot: remote-sync-old')
            ->assertFailed();
    });
});
