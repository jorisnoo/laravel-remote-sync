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
            $this->components->warn(__('remote-sync::messages.warnings.no_paths_push'));

            return self::SUCCESS;
        }

        $this->isDryRun = $this->promptDryRunOption();
        $this->shouldDelete = $this->promptDeleteOption('remote');

        if ($this->isDryRun) {
            $this->components->info(__('remote-sync::messages.info.dry_run_mode'));

            return $this->performDryRun($paths);
        }

        if (! $this->option('force')) {
            if ($this->shouldDelete) {
                if (! $this->confirmDeleteOnRemote()) {
                    $this->components->info(__('remote-sync::messages.info.operation_cancelled'));

                    return self::SUCCESS;
                }
            } elseif (! $this->confirmPush('files')) {
                $this->components->info(__('remote-sync::messages.info.operation_cancelled'));

                return self::SUCCESS;
            }
        }

        foreach ($paths as $path) {
            if (! $this->pushPath($path)) {
                return self::FAILURE;
            }
        }

        $this->components->success(__('remote-sync::messages.success.files_pushed', ['name' => $this->remote->name]));

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
        $this->components->warn(__('remote-sync::messages.warnings.delete_warning', ['name' => $this->remote->name]));

        return $this->confirmWithTypedYes(
            __('remote-sync::prompts.confirm.delete_remote', ['name' => $this->remote->name])
        );
    }

    protected function performDryRun(array $paths): int
    {
        foreach ($paths as $path) {
            $validationError = $this->validateStoragePath($path);

            if ($validationError !== null) {
                $this->components->error($validationError);

                return self::FAILURE;
            }

            $localPath = storage_path($path);

            if (! is_dir($localPath)) {
                $this->components->warn(__('remote-sync::messages.warnings.local_path_not_exists', ['path' => $path]));

                continue;
            }

            $localPath = rtrim($localPath, '/').'/';
            $remotePath = "{$this->remote->storagePath()}/{$path}/";

            $this->components->info(__('remote-sync::messages.info.would_sync_path', ['path' => $path]));

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
                $this->components->error(__('remote-sync::messages.errors.failed_dry_run', ['path' => $path, 'error' => $result->errorOutput()]));

                return self::FAILURE;
            }
        }

        return self::SUCCESS;
    }

    protected function pushPath(string $path): bool
    {
        $validationError = $this->validateStoragePath($path);

        if ($validationError !== null) {
            $this->components->error($validationError);

            return false;
        }

        $localPath = storage_path($path);

        if (! is_dir($localPath)) {
            $this->components->warn(__('remote-sync::messages.warnings.local_path_not_exists', ['path' => $path]));

            return true;
        }

        $localPath = rtrim($localPath, '/').'/';
        $remotePath = "{$this->remote->storagePath()}/{$path}/";

        $this->components->info(__('remote-sync::messages.info.pushing_path', ['path' => $path]));

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
            $this->components->error(__('remote-sync::messages.errors.failed_push_path', ['path' => $path, 'error' => $result->errorOutput()]));

            return false;
        }

        return true;
    }
}
