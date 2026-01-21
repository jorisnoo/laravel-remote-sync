<?php

namespace Noo\LaravelRemoteSync\Commands;

use Illuminate\Console\Command;
use Noo\LaravelRemoteSync\Concerns\InteractsWithRemote;

class PushFilesCommand extends Command
{
    use InteractsWithRemote;

    protected $signature = 'remote-sync:push-files
        {remote? : The remote environment to push to}
        {--path= : Push only a specific path (relative to storage/)}
        {--delete : Delete remote files that do not exist locally}
        {--dry-run : Show what would be synced without making changes}
        {--force : Skip confirmation prompt}';

    protected $description = 'Push storage files to a remote environment';

    protected ?string $specificPath = null;

    protected bool $isDryRun;

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

        if (! $this->ensurePushAllowed()) {
            return self::FAILURE;
        }

        $this->specificPath = $this->promptPathSelection();
        $paths = $this->getPathsToPush();

        if (empty($paths)) {
            $this->components->warn('No paths configured for pushing.');

            return self::SUCCESS;
        }

        $this->isDryRun = $this->promptDryRunOption();
        $this->shouldDelete = $this->promptDeleteOption('remote');

        if ($this->isDryRun) {
            $this->components->info('Dry run mode - no changes will be made.');

            return $this->performDryRun($paths);
        }

        if (! $this->option('force')) {
            if ($this->shouldDelete) {
                if (! $this->confirmDeleteOnRemote()) {
                    $this->components->info('Operation cancelled.');

                    return self::SUCCESS;
                }
            } elseif (! $this->confirmPush('files')) {
                $this->components->info('Operation cancelled.');

                return self::SUCCESS;
            }
        }

        foreach ($paths as $path) {
            if (! $this->pushPath($path)) {
                return self::FAILURE;
            }
        }

        $this->components->success("Files pushed to [{$this->remote->name}].");

        return self::SUCCESS;
    }

    protected function getPathsToPush(): array
    {
        if ($this->specificPath !== null) {
            return [$this->specificPath];
        }

        return config('remote-sync.paths', []);
    }

    protected function confirmDeleteOnRemote(): bool
    {
        $this->components->warn("WARNING: Files on [{$this->remote->name}] that don't exist locally will be DELETED.");

        return $this->confirmWithTypedYes(
            "Push local files to [{$this->remote->name}] with deletion? Type \"yes\" to continue"
        );
    }

    protected function performDryRun(array $paths): int
    {
        foreach ($paths as $path) {
            $localPath = storage_path($path);

            if (! is_dir($localPath)) {
                $this->components->warn("Local path does not exist: {$path}");

                continue;
            }

            $localPath = rtrim($localPath, '/').'/';
            $remotePath = "{$this->remote->storagePath()}/{$path}/";

            $this->components->info("Would sync: {$path}");

            $options = ['--partial', '--info=progress2', '--dry-run'];

            if ($this->shouldDelete) {
                $options[] = '--delete';
            }

            $result = $this->syncService->rsyncUpload(
                $this->remote,
                $localPath,
                $remotePath,
                $options
            );

            if (! $result->successful()) {
                $this->components->error("Dry run failed for {$path}: {$result->errorOutput()}");

                return self::FAILURE;
            }
        }

        return self::SUCCESS;
    }

    protected function pushPath(string $path): bool
    {
        $localPath = storage_path($path);

        if (! is_dir($localPath)) {
            $this->components->warn("Local path does not exist: {$path}");

            return true;
        }

        $localPath = rtrim($localPath, '/').'/';
        $remotePath = "{$this->remote->storagePath()}/{$path}/";

        $this->components->info("Pushing: {$path}");

        $options = ['--partial', '--info=progress2'];

        if ($this->shouldDelete) {
            $options[] = '--delete';
        }

        $result = $this->syncService->rsyncUpload(
            $this->remote,
            $localPath,
            $remotePath,
            $options
        );

        if (! $result->successful()) {
            $this->components->error("Failed to push {$path}: {$result->errorOutput()}");

            return false;
        }

        return true;
    }
}
