<?php

namespace Noo\LaravelRemoteSync\Commands;

use Illuminate\Console\Command;
use Noo\LaravelRemoteSync\Concerns\InteractsWithRemote;

class SyncFilesCommand extends Command
{
    use InteractsWithRemote;

    protected $signature = 'remote-sync:pull-files
        {remote? : The remote environment to sync from}
        {--path= : Sync only a specific path (relative to storage/)}
        {--delete : Delete local files that do not exist on remote}
        {--force : Skip confirmation prompt}';

    protected $description = 'Sync storage files from a remote environment';

    protected ?string $specificPath = null;

    protected bool $shouldDelete;

    public function handle(): int
    {
        if (! $this->ensureNotProduction()) {
            return self::FAILURE;
        }

        try {
            $this->initializeRemote($this->argument('remote'));
        } catch (\InvalidArgumentException $e) {
            $this->components->error($e->getMessage());

            return self::FAILURE;
        }

        $this->specificPath = $this->promptPathSelection();
        $paths = $this->getPathsToSync();

        if (empty($paths)) {
            $this->components->warn('No paths configured for syncing.');

            return self::SUCCESS;
        }

        $this->shouldDelete = $this->promptDeleteOption('local');

        if (! $this->option('force') && ! $this->confirmSync('files')) {
            $this->components->info('Operation cancelled.');

            return self::SUCCESS;
        }

        foreach ($paths as $path) {
            if (! $this->syncPath($path)) {
                return self::FAILURE;
            }
        }

        $this->components->success("Files synced from [{$this->remote->name}].");

        return self::SUCCESS;
    }

    protected function getPathsToSync(): array
    {
        if ($this->specificPath !== null) {
            return [$this->specificPath];
        }

        return config('remote-sync.paths', []);
    }

    protected function syncPath(string $path): bool
    {
        $remotePath = "{$this->remote->storagePath()}/{$path}/";
        $localPath = storage_path($path);

        if (! is_dir($localPath)) {
            mkdir($localPath, 0755, true);
        }

        $localPath = rtrim($localPath, '/').'/';

        $this->components->info("Syncing: {$path}");

        $options = ['--partial', '--info=progress2'];

        if ($this->shouldDelete) {
            $options[] = '--delete';
        }

        $result = $this->syncService->rsync(
            $this->remote,
            $remotePath,
            $localPath,
            $options
        );

        if (! $result->successful()) {
            $this->components->error("Failed to sync {$path}: {$result->errorOutput()}");

            return false;
        }

        return true;
    }
}
