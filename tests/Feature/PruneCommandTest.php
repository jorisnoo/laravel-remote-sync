<?php

use Noo\LaravelRemoteSync\Commands\PruneCommand;
use Noo\LaravelRemoteSync\Database\Dumps;

function localDumps(string ...$names): void
{
    Dumps::ensureDirectory();

    foreach ($names as $index => $name) {
        touch(Dumps::path($name), 1000 + $index);
    }
}

beforeEach(function () {
    syncConfig();
    useLocalInfo();
});

afterEach(function () {
    foreach (glob(storage_path('remote-sync/*.sql.gz')) ?: [] as $file) {
        unlink($file);
    }
});

describe('remote-sync:prune', function () {
    it('keeps the newest of each kind', function () {
        $dumps = [
            ['name' => 'pre-pull-2026-01-03-000000', 'mtime' => 3],
            ['name' => 'remote-sync-2026-01-03-000000-aaaaaa', 'mtime' => 3],
            ['name' => 'pre-pull-2026-01-02-000000', 'mtime' => 2],
            ['name' => 'pre-push-2026-01-02-000000', 'mtime' => 2],
            ['name' => 'pre-pull-2026-01-01-000000', 'mtime' => 1],
        ];

        expect(array_column(PruneCommand::prunable($dumps, keep: 1), 'name'))
            ->toBe(['pre-pull-2026-01-02-000000', 'pre-pull-2026-01-01-000000']);
    });

    it('prunes local dumps without touching the remote', function () {
        fakeProcesses();
        localDumps('pre-pull-2026-01-01-000000', 'pre-pull-2026-01-02-000000', 'pre-pull-2026-01-03-000000');

        $this->artisan('remote-sync:prune', ['--local' => true, '--keep' => 1, '--force' => true])
            ->expectsOutputToContain('Deleted 2 dumps.')
            ->assertSuccessful();

        expect(array_column(Dumps::list(), 'name'))->toBe(['pre-pull-2026-01-03-000000'])
            ->and(ranCommands())->toBe([]);
    });

    it('prunes remote dumps listed by the probe', function () {
        fakeProcesses(remote: ['dumps' => [
            ['name' => 'pre-push-2026-01-02-000000', 'mtime' => 2],
            ['name' => 'pre-push-2026-01-01-000000', 'mtime' => 1],
        ]]);

        $this->artisan('remote-sync:prune', ['--remote' => true, '--keep' => 1, '--force' => true])
            ->expectsOutputToContain('On [staging]:')
            ->assertSuccessful();

        expect(ranAt('ssh', "rm -f '/srv/acme/releases/7/storage/remote-sync/pre-push-2026-01-01-000000.sql.gz'"))->toBeGreaterThan(-1);
    });

    it('shows what it would delete with --dry-run', function () {
        fakeProcesses();
        localDumps('pre-pull-2026-01-01-000000', 'pre-pull-2026-01-02-000000');

        $this->artisan('remote-sync:prune', ['--local' => true, '--keep' => 1, '--dry-run' => true])
            ->expectsOutputToContain('pre-pull-2026-01-01-000000')
            ->expectsOutputToContain('Dry run: nothing was deleted.')
            ->assertSuccessful();

        expect(Dumps::list())->toHaveCount(2);
    });

    it('says when there is nothing to prune', function () {
        fakeProcesses();

        $this->artisan('remote-sync:prune', ['--force' => true])
            ->expectsOutputToContain('Nothing to prune (keeping the 5 newest of each kind).')
            ->assertSuccessful();
    });
});
