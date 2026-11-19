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
    it('rejects invalid retention without listing or deleting snapshots', function (string $keep) {
        Process::fake();
        createLocalSnapshots(['pre-pull-last' => 10]);

        $this->artisan('remote-sync:prune', ['--keep' => $keep, '--force' => true])
            ->expectsOutputToContain('--keep must be a non-negative integer')
            ->assertFailed();

        expect(localSnapshotNames())->toBe(['pre-pull-last']);
        Process::assertNothingRan();
    })->with(['', 'five', '-1', '1.5']);

    it('prunes locally in implicit mode if there is no remote to select', function (array $remotes) {
        config()->set('remote-sync.remotes', $remotes);
        Process::fake();
        createLocalSnapshots(['pre-pull-first' => 20, 'pre-pull-last' => 10]);

        $this->artisan('remote-sync:prune', ['--keep' => '1', '--force' => true, '--no-interaction' => true])
            ->expectsOutputToContain('pruning local snapshots only')
            ->assertSuccessful();

        expect(localSnapshotNames())->toBe(['pre-pull-last']);
        Process::assertNothingRan();
    })->with([
        'unconfigured' => [[]],
        'ambiguous' => [['first' => [], 'second' => []]],
    ]);

    it('does not fall back to local pruning for an explicitly requested remote', function () {
        config()->set('remote-sync.remotes', []);
        Process::fake();
        createLocalSnapshots(['pre-pull-last' => 10]);

        $this->artisan('remote-sync:prune', ['remote' => 'missing', '--keep' => '0', '--force' => true])
            ->assertFailed();
        $this->artisan('remote-sync:prune', ['--remote' => true, '--keep' => '0', '--force' => true])
            ->assertFailed();

        expect(localSnapshotNames())->toBe(['pre-pull-last']);
        Process::assertNothingRan();
    });

    it('never prunes locally after a failed host check', function () {
        createLocalSnapshots(['pre-pull-last' => 10]);
        fakeSyncProcesses(custom: [
            'ConnectTimeout=5' => Process::result(exitCode: 255, errorOutput: 'Host key verification failed.'),
        ]);

        $this->artisan('remote-sync:prune', ['--keep' => '0', '--force' => true])
            ->assertFailed();

        expect(localSnapshotNames())->toBe(['pre-pull-last']);
    });

    it('refuses to list or delete snapshots without a remote disk root', function () {
        fakeSyncProcesses(probeOverrides: ['json' => ['snapshot_dir' => null]]);
        mockSnapshots()->shouldNotReceive('listRemote');
        createLocalSnapshots(['pre-pull-last' => 10]);

        $this->artisan('remote-sync:prune', ['--keep' => '0', '--force' => true])
            ->expectsOutputToContain('Cannot prune')
            ->assertFailed();

        expect(localSnapshotNames())->toBe(['pre-pull-last']);
    });

    it('does not delete local snapshots if the remote listing fails', function () {
        fakeSyncProcesses();
        mockSnapshots()->shouldReceive('listRemote')->andThrow(new RuntimeException('Cannot list remote snapshots'));
        createLocalSnapshots(['pre-pull-last' => 10]);

        $this->artisan('remote-sync:prune', ['--keep' => '0', '--force' => true])
            ->expectsOutputToContain('Cannot list remote snapshots')
            ->assertFailed();

        expect(localSnapshotNames())->toBe(['pre-pull-last']);
    });

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
