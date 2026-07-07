<?php

namespace Noo\LaravelRemoteSync\Commands;

use Illuminate\Console\Command;
use Noo\LaravelRemoteSync\Commands\Concerns\ResolvesRemote;
use Noo\LaravelRemoteSync\Remotes\Connection;
use Noo\LaravelRemoteSync\Snapshots\Snapshots;

use function Laravel\Prompts\confirm;

class PruneCommand extends Command
{
    use ResolvesRemote;

    protected $signature = 'remote-sync:prune
        {remote? : The remote environment to prune snapshots on}
        {--local : Only prune local snapshots}
        {--remote : Only prune remote snapshots}
        {--keep=5 : Number of most recent snapshots to keep}
        {--all : Also prune snapshots not created by this package}
        {--dry-run : Show what would be deleted without deleting}
        {--f|force : Answer the confirmation with yes}';

    protected $description = 'Prune old database snapshots created by remote-sync, locally and/or on a remote';

    public function handle(): int
    {
        $pruneLocal = $this->option('local') || ! $this->option('remote');
        $pruneRemote = $this->option('remote') || ! $this->option('local');
        $keep = max(0, (int) $this->option('keep'));

        $localVictims = $pruneLocal ? $this->prunable(Snapshots::listLocal(), $keep) : [];

        $snapshots = null;
        $remoteName = null;
        $remoteVictims = [];

        if ($pruneRemote) {
            $remote = $this->resolveRemote();

            if ($remote === null) {
                return self::FAILURE;
            }

            $connection = new Connection($remote);

            if (! $this->verifyHostKey($connection)) {
                return self::FAILURE;
            }

            $info = $this->probeRemote($connection);

            if ($info === null) {
                return self::FAILURE;
            }

            $remoteName = $remote->name;
            $snapshots = app(Snapshots::class, ['connection' => $connection, 'info' => $info]);
            $remoteVictims = $this->prunable($snapshots->listRemote(), $keep);
        }

        if ($localVictims === [] && $remoteVictims === []) {
            $this->components->info(__('remote-sync::messages.info.nothing_to_prune', ['keep' => $keep]));

            return self::SUCCESS;
        }

        $this->renderVictims($localVictims, $remoteVictims, $remoteName, $keep);

        if ($this->option('dry-run')) {
            $this->components->info(__('remote-sync::messages.info.dry_run_done'));

            return self::SUCCESS;
        }

        if (! $this->confirmPrune(count($localVictims) + count($remoteVictims))) {
            if ($this->isInteractive()) {
                $this->components->info(__('remote-sync::messages.info.operation_cancelled'));

                return self::SUCCESS;
            }

            return self::FAILURE;
        }

        $failures = 0;

        foreach ($localVictims as $snapshot) {
            if (! Snapshots::deleteLocal($snapshot['name'])) {
                $this->components->warn(__('remote-sync::messages.errors.failed_delete_snapshot', ['name' => $snapshot['name']]));
                $failures++;
            }
        }

        if ($localVictims !== []) {
            $deleted = count($localVictims) - $failures;
            $this->components->info(trans_choice('remote-sync::messages.info.deleted_local_snapshots', $deleted, ['count' => $deleted]));
        }

        $remoteFailures = 0;

        if ($snapshots !== null) {
            foreach ($remoteVictims as $snapshot) {
                if (! $snapshots->deleteRemote($snapshot['name'])->successful()) {
                    $this->components->warn(__('remote-sync::messages.errors.failed_delete_snapshot', ['name' => $snapshot['name']]));
                    $remoteFailures++;
                }
            }
        }

        if ($remoteVictims !== []) {
            $deleted = count($remoteVictims) - $remoteFailures;
            $this->components->info(trans_choice('remote-sync::messages.info.deleted_remote_snapshots', $deleted, ['count' => $deleted]));
        }

        return ($failures + $remoteFailures) > 0 ? self::FAILURE : self::SUCCESS;
    }

    /**
     * Keep the N most recent snapshots and return the rest. Snapshots not
     * created by this package are left alone unless --all is given.
     *
     * @param  array<int, array{name: string, path: string, mtime: int}>  $snapshots
     * @return array<int, array{name: string, path: string, mtime: int}>
     */
    protected function prunable(array $snapshots, int $keep): array
    {
        if (! $this->option('all')) {
            $snapshots = array_values(array_filter(
                $snapshots,
                fn (array $snapshot) => Snapshots::isOwnSnapshot($snapshot['name'])
            ));
        }

        return array_slice($snapshots, $keep);
    }

    /**
     * @param  array<int, array{name: string, path: string, mtime: int}>  $localVictims
     * @param  array<int, array{name: string, path: string, mtime: int}>  $remoteVictims
     */
    protected function renderVictims(array $localVictims, array $remoteVictims, ?string $remoteName, int $keep): void
    {
        if ($localVictims !== []) {
            $this->components->info(__('remote-sync::messages.info.local_prune_header', ['keep' => $keep]));

            foreach ($localVictims as $snapshot) {
                $this->components->twoColumnDetail($snapshot['name'], date('Y-m-d H:i:s', $snapshot['mtime']));
            }
        }

        if ($remoteVictims !== []) {
            $this->components->info(__('remote-sync::messages.info.remote_prune_header', ['name' => $remoteName, 'keep' => $keep]));

            foreach ($remoteVictims as $snapshot) {
                $this->components->twoColumnDetail($snapshot['name'], date('Y-m-d H:i:s', $snapshot['mtime']));
            }
        }
    }

    protected function confirmPrune(int $count): bool
    {
        if ($this->option('force')) {
            return true;
        }

        if (! $this->isInteractive()) {
            $this->components->error(__('remote-sync::messages.errors.confirmation_required'));

            return false;
        }

        return confirm(
            label: trans_choice('remote-sync::prompts.confirm.prune', $count, ['count' => $count]),
            default: true,
        );
    }
}
