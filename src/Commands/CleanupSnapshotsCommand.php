<?php

namespace Noo\LaravelRemoteSync\Commands;

use Illuminate\Console\Command;
use Noo\LaravelRemoteSync\Concerns\InteractsWithRemote;
use Noo\LaravelRemoteSync\RemoteSyncService;

use function Laravel\Prompts\confirm;
use function Laravel\Prompts\select;

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

    public function handle(): int
    {
        $shouldCleanupLocal = $this->shouldCleanupLocal();
        $shouldCleanupRemote = $this->shouldCleanupRemote();
        $keep = (int) $this->option('keep');
        $isDryRun = $this->option('dry-run');
        $force = $this->option('force');

        $this->syncService = app(RemoteSyncService::class);

        if ($shouldCleanupRemote) {
            $remoteName = $this->argument('remote') ?? $this->selectRemote();

            if (! $remoteName) {
                $this->components->error('No remote environment selected.');

                return self::FAILURE;
            }

            try {
                $this->initializeRemote($remoteName);
            } catch (\InvalidArgumentException $e) {
                $this->components->error($e->getMessage());

                return self::FAILURE;
            }
        }

        $localSnapshots = [];
        $remoteSnapshots = [];
        $localToDelete = [];
        $remoteToDelete = [];

        if ($shouldCleanupLocal) {
            $localSnapshots = $this->getLocalSnapshots();
            $localToDelete = $this->filterSnapshotsToDelete($localSnapshots, $keep);
        }

        if ($shouldCleanupRemote) {
            $remoteSnapshots = $this->getRemoteSnapshots();
            $remoteToDelete = $this->filterSnapshotsToDelete($remoteSnapshots, $keep);
        }

        if (empty($localToDelete) && empty($remoteToDelete)) {
            $this->components->info('No snapshots to cleanup.');

            return self::SUCCESS;
        }

        $this->displaySnapshotsToDelete($localToDelete, $remoteToDelete, $keep);

        if ($isDryRun) {
            $this->components->warn('Dry run mode - no files were deleted.');

            return self::SUCCESS;
        }

        if (! $force && ! $this->confirmCleanup(count($localToDelete), count($remoteToDelete))) {
            $this->components->info('Cleanup cancelled.');

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

    protected function shouldCleanupLocal(): bool
    {
        if ($this->option('local') && $this->option('remote')) {
            return true;
        }

        if ($this->option('remote')) {
            return false;
        }

        return true;
    }

    protected function shouldCleanupRemote(): bool
    {
        if ($this->option('local') && $this->option('remote')) {
            return true;
        }

        if ($this->option('local')) {
            return false;
        }

        return true;
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
            label: 'Select remote environment',
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
            $this->components->warn('Failed to list remote snapshots: '.$result->errorOutput());

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
            $this->components->info("Local snapshots to delete (keeping {$keep} most recent):");

            foreach ($localSnapshots as $snapshot) {
                $date = date('Y-m-d H:i:s', $snapshot['mtime']);
                $this->components->bulletList(["{$snapshot['name']} ({$date})"]);
            }

            $this->newLine();
        }

        if (! empty($remoteSnapshots)) {
            $this->components->info("Remote snapshots to delete from [{$this->remote->name}] (keeping {$keep} most recent):");

            foreach ($remoteSnapshots as $snapshot) {
                $date = date('Y-m-d H:i:s', $snapshot['mtime']);
                $this->components->bulletList(["{$snapshot['name']} ({$date})"]);
            }

            $this->newLine();
        }
    }

    protected function confirmCleanup(int $localCount, int $remoteCount): bool
    {
        $message = 'Delete ';
        $parts = [];

        if ($localCount > 0) {
            $parts[] = "{$localCount} local snapshot".($localCount > 1 ? 's' : '');
        }

        if ($remoteCount > 0) {
            $parts[] = "{$remoteCount} remote snapshot".($remoteCount > 1 ? 's' : '');
        }

        $message .= implode(' and ', $parts).'?';

        return confirm($message, default: false);
    }

    /**
     * @param  array<int, array{path: string, name: string, mtime: int}>  $snapshots
     */
    protected function cleanupLocalSnapshots(array $snapshots): int
    {
        $this->components->task('Cleaning up local snapshots', function () use ($snapshots) {
            foreach ($snapshots as $snapshot) {
                if (file_exists($snapshot['path'])) {
                    unlink($snapshot['path']);
                }
            }

            return true;
        });

        $this->components->info('Deleted '.count($snapshots).' local snapshot'.(count($snapshots) > 1 ? 's' : '').'.');

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
                $this->components->warn("Failed to delete remote snapshot: {$snapshot['name']}");
                $failed++;
            }
        }

        $deleted = count($snapshots) - $failed;

        if ($deleted > 0) {
            $this->components->info("Deleted {$deleted} remote snapshot".($deleted > 1 ? 's' : '').'.');
        }

        return $failed > 0 ? self::FAILURE : self::SUCCESS;
    }
}
