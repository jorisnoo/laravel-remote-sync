<?php

namespace Noo\LaravelRemoteSync\Commands;

use Illuminate\Console\Command;
use Noo\LaravelRemoteSync\Concerns\InteractsWithRemote;
use Noo\LaravelRemoteSync\RemoteSyncService;

use function Laravel\Prompts\multiselect;
use function Laravel\Prompts\select;
use function Laravel\Prompts\text;

class CleanupSnapshotsCommand extends Command
{
    use InteractsWithRemote;

    protected $signature = 'remote-sync:cleanup-snapshots
        {remote? : The remote environment to cleanup snapshots from}
        {--local : Only cleanup local snapshots}
        {--remote : Only cleanup remote snapshots}
        {--keep=5 : Number of most recent snapshots to keep}
        {--force : Skip confirmation prompt}
        {--dry-run : Show what would be deleted without actually deleting}';

    protected $description = 'Cleanup old database snapshots from local and/or remote storage';

    protected bool $shouldCleanupLocal;

    protected bool $shouldCleanupRemote;

    protected int $keep;

    protected bool $isDryRun;

    public function handle(): int
    {
        $this->syncService = app(RemoteSyncService::class);

        $targets = $this->promptCleanupTargets();
        $this->shouldCleanupLocal = in_array('local', $targets);
        $this->shouldCleanupRemote = in_array('remote', $targets);

        if (! $this->shouldCleanupLocal && ! $this->shouldCleanupRemote) {
            $this->components->info(__('remote-sync::messages.info.no_cleanup_targets'));

            return self::SUCCESS;
        }

        if ($this->shouldCleanupRemote) {
            $remoteName = $this->argument('remote') ?? $this->selectRemote();

            if (! $remoteName) {
                $this->components->error(__('remote-sync::messages.errors.no_remote_selected'));

                return self::FAILURE;
            }

            try {
                $this->initializeRemote($remoteName);
            } catch (\InvalidArgumentException $e) {
                $this->components->error($e->getMessage());

                return self::FAILURE;
            }
        }

        $this->keep = $this->promptKeepCount();
        $this->isDryRun = $this->promptPreviewOption();

        $localSnapshots = [];
        $remoteSnapshots = [];
        $localToDelete = [];
        $remoteToDelete = [];

        if ($this->shouldCleanupLocal) {
            $localSnapshots = $this->getLocalSnapshots();
            $localToDelete = $this->filterSnapshotsToDelete($localSnapshots, $this->keep);
        }

        if ($this->shouldCleanupRemote) {
            $remoteSnapshots = $this->getRemoteSnapshots();
            $remoteToDelete = $this->filterSnapshotsToDelete($remoteSnapshots, $this->keep);
        }

        if (empty($localToDelete) && empty($remoteToDelete)) {
            $this->components->info(__('remote-sync::messages.info.no_snapshots_to_cleanup'));

            return self::SUCCESS;
        }

        $this->displaySnapshotsToDelete($localToDelete, $remoteToDelete, $this->keep);

        if ($this->isDryRun) {
            $this->components->warn(__('remote-sync::messages.warnings.dry_run_no_delete'));

            return self::SUCCESS;
        }

        if (! $this->option('force') && ! $this->confirmCleanup(count($localToDelete), count($remoteToDelete))) {
            $this->components->info(__('remote-sync::messages.info.cleanup_cancelled'));

            return self::SUCCESS;
        }

        $exitCode = self::SUCCESS;

        if (! empty($localToDelete)) {
            $result = $this->cleanupLocalSnapshots($localToDelete);

            if ($result !== self::SUCCESS) {
                $exitCode = $result;
            }
        }

        if (! empty($remoteToDelete)) {
            $result = $this->cleanupRemoteSnapshots($remoteToDelete);

            if ($result !== self::SUCCESS) {
                $exitCode = $result;
            }
        }

        return $exitCode;
    }

    protected function shouldSkipPrompts(): bool
    {
        return $this->option('force') === true
            || $this->option('dry-run') === true
            || $this->option('local')
            || $this->option('remote');
    }

    protected function promptCleanupTargets(): array
    {
        if ($this->shouldSkipPrompts()) {
            $targets = [];

            if ($this->option('local')) {
                $targets[] = 'local';
            }

            if ($this->option('remote')) {
                $targets[] = 'remote';
            }

            if (empty($targets)) {
                $targets = ['local', 'remote'];
            }

            return $targets;
        }

        return multiselect(
            label: __('remote-sync::prompts.cleanup_targets.label'),
            options: [
                'local' => __('remote-sync::prompts.cleanup_targets.local'),
                'remote' => __('remote-sync::prompts.cleanup_targets.remote'),
            ],
            default: ['local', 'remote'],
            required: true,
        );
    }

    protected function promptKeepCount(): int
    {
        if ($this->shouldSkipPrompts()) {
            return (int) $this->option('keep');
        }

        $value = text(
            label: __('remote-sync::prompts.keep_count.label'),
            default: '5',
            required: true,
            validate: function (string $value) {
                if (! is_numeric($value) || (int) $value < 0) {
                    return __('remote-sync::prompts.keep_count.validation');
                }

                return null;
            }
        );

        return (int) $value;
    }

    protected function promptPreviewOption(): bool
    {
        if ($this->shouldSkipPrompts()) {
            return (bool) $this->option('dry-run');
        }

        $choice = select(
            label: __('remote-sync::prompts.preview.label'),
            options: [
                'yes' => __('remote-sync::prompts.preview.yes'),
                'no' => __('remote-sync::prompts.preview.no'),
            ],
            default: 'yes',
        );

        return $choice === 'yes';
    }

    protected function selectRemote(): ?string
    {
        $remotes = $this->syncService->getAvailableRemotes();

        if (empty($remotes)) {
            return null;
        }

        if (count($remotes) === 1) {
            return $remotes[0];
        }

        return select(
            label: __('remote-sync::prompts.remote.label'),
            options: $remotes,
            default: config('remote-sync.default'),
        );
    }

    /**
     * @return array<int, array{path: string, name: string, mtime: int}>
     */
    protected function getLocalSnapshots(): array
    {
        $snapshotPath = $this->syncService->getSnapshotPath();
        $files = glob("{$snapshotPath}/*.sql.gz") ?: [];

        $snapshots = [];

        foreach ($files as $file) {
            $snapshots[] = [
                'path' => $file,
                'name' => basename($file, '.sql.gz'),
                'mtime' => filemtime($file),
            ];
        }

        usort($snapshots, fn ($a, $b) => $b['mtime'] <=> $a['mtime']);

        return $snapshots;
    }

    /**
     * @return array<int, array{path: string, name: string, mtime: int}>
     */
    protected function getRemoteSnapshots(): array
    {
        $result = $this->syncService->listRemoteSnapshots($this->remote);

        if (! $result->successful()) {
            $this->components->warn(__('remote-sync::messages.errors.failed_list_snapshots', ['error' => $result->errorOutput()]));

            return [];
        }

        $output = trim($result->output());

        if (empty($output)) {
            return [];
        }

        $lines = array_filter(explode("\n", $output));
        $snapshots = [];

        foreach ($lines as $line) {
            $parts = preg_split('/\s+/', trim($line), 2);

            if (count($parts) !== 2) {
                continue;
            }

            [$mtime, $path] = $parts;
            $snapshots[] = [
                'path' => $path,
                'name' => basename($path, '.sql.gz'),
                'mtime' => (int) $mtime,
            ];
        }

        return $snapshots;
    }

    /**
     * @param  array<int, array{path: string, name: string, mtime: int}>  $snapshots
     * @return array<int, array{path: string, name: string, mtime: int}>
     */
    protected function filterSnapshotsToDelete(array $snapshots, int $keep): array
    {
        if ($keep >= count($snapshots)) {
            return [];
        }

        return array_slice($snapshots, $keep);
    }

    /**
     * @param  array<int, array{path: string, name: string, mtime: int}>  $localSnapshots
     * @param  array<int, array{path: string, name: string, mtime: int}>  $remoteSnapshots
     */
    protected function displaySnapshotsToDelete(array $localSnapshots, array $remoteSnapshots, int $keep): void
    {
        if (! empty($localSnapshots)) {
            $this->components->info(__('remote-sync::messages.info.local_snapshots_to_delete', ['count' => $keep]));

            foreach ($localSnapshots as $snapshot) {
                $date = date('Y-m-d H:i:s', $snapshot['mtime']);
                $this->components->bulletList(["{$snapshot['name']} ({$date})"]);
            }

            $this->newLine();
        }

        if (! empty($remoteSnapshots)) {
            $this->components->info(__('remote-sync::messages.info.remote_snapshots_to_delete', ['name' => $this->remote->name, 'count' => $keep]));

            foreach ($remoteSnapshots as $snapshot) {
                $date = date('Y-m-d H:i:s', $snapshot['mtime']);
                $this->components->bulletList(["{$snapshot['name']} ({$date})"]);
            }

            $this->newLine();
        }
    }

    protected function confirmCleanup(int $localCount, int $remoteCount): bool
    {
        $parts = [];

        if ($localCount > 0) {
            $parts[] = trans_choice('remote-sync::messages.info.deleted_local_snapshots', $localCount, ['count' => $localCount]);
        }

        if ($remoteCount > 0) {
            $parts[] = trans_choice('remote-sync::messages.info.deleted_remote_snapshots', $remoteCount, ['count' => $remoteCount]);
        }

        $summary = implode(' and ', $parts);

        return $this->confirmWithTypedYes(__('remote-sync::prompts.confirm.cleanup', ['summary' => $summary]));
    }

    /**
     * @param  array<int, array{path: string, name: string, mtime: int}>  $snapshots
     */
    protected function cleanupLocalSnapshots(array $snapshots): int
    {
        $this->components->task(__('remote-sync::messages.cleanup.cleaning_local'), function () use ($snapshots) {
            foreach ($snapshots as $snapshot) {
                if (file_exists($snapshot['path'])) {
                    unlink($snapshot['path']);
                }
            }

            return true;
        });

        $this->components->info(trans_choice('remote-sync::messages.info.deleted_local_snapshots', count($snapshots), ['count' => count($snapshots)]));

        return self::SUCCESS;
    }

    /**
     * @param  array<int, array{path: string, name: string, mtime: int}>  $snapshots
     */
    protected function cleanupRemoteSnapshots(array $snapshots): int
    {
        $failed = 0;

        foreach ($snapshots as $snapshot) {
            $result = $this->syncService->deleteRemoteSnapshot($this->remote, $snapshot['name']);

            if (! $result->successful()) {
                $this->components->warn(__('remote-sync::messages.errors.failed_delete_snapshot', ['name' => $snapshot['name']]));
                $failed++;
            }
        }

        $deleted = count($snapshots) - $failed;

        if ($deleted > 0) {
            $this->components->info(trans_choice('remote-sync::messages.info.deleted_remote_snapshots', $deleted, ['count' => $deleted]));
        }

        return $failed > 0 ? self::FAILURE : self::SUCCESS;
    }
}
