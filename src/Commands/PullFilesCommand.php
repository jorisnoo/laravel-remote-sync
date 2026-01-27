<?php

namespace Noo\LaravelRemoteSync\Commands;

use Illuminate\Console\Command;
use Noo\LaravelRemoteSync\Concerns\InteractsWithRemote;

use function Laravel\Prompts\spin;

class PullFilesCommand extends Command
{
    use InteractsWithRemote;

    protected $signature = 'remote-sync:pull-files
        {remote? : The remote environment to pull from}
        {--path= : Pull only a specific path (relative to storage/)}
        {--delete : Delete local files that do not exist on remote}
        {--dry-run : Show what would be transferred without making changes}
        {--force : Skip confirmation prompt}';

    protected $description = 'Pull storage files from a remote environment';

    protected ?string $specificPath = null;

    protected bool $shouldDelete;

    protected int $filesToTransfer = 0;

    protected int $filesToDelete = 0;

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
            $this->components->warn(__('remote-sync::messages.warnings.no_paths_pull'));

            return self::SUCCESS;
        }

        $this->shouldDelete = $this->promptDeleteOption('local');

        $this->analyzeAndDisplayPreview($paths);

        if (! $this->option('force') && ! $this->confirmPull('files')) {
            $this->components->info(__('remote-sync::messages.info.operation_cancelled'));

            return self::SUCCESS;
        }

        if ($this->option('dry-run')) {
            $this->components->info(__('remote-sync::messages.info.dry_run_mode'));

            return self::SUCCESS;
        }

        foreach ($paths as $path) {
            if (! $this->syncPath($path)) {
                return self::FAILURE;
            }
        }

        $this->components->success(__('remote-sync::messages.success.files_pulled', ['name' => $this->remote->name]));

        return self::SUCCESS;
    }

    protected function getPathsToSync(): array
    {
        if ($this->specificPath !== null) {
            return [$this->specificPath];
        }

        return config('remote-sync.paths', []);
    }

    protected function analyzeAndDisplayPreview(array $paths): void
    {
        $this->filesToTransfer = 0;
        $this->filesToDelete = 0;

        spin(
            callback: function () use ($paths) {
                foreach ($paths as $path) {
                    $validationError = $this->validateStoragePath($path);

                    if ($validationError !== null) {
                        continue;
                    }

                    $remotePath = "{$this->remote->storagePath()}/{$path}/";
                    $localPath = storage_path($path);

                    if (! is_dir($localPath)) {
                        mkdir($localPath, 0755, true);
                    }

                    $localPath = rtrim($localPath, '/').'/';

                    $options = $this->shouldDelete ? ['--delete'] : [];

                    $result = $this->syncService->rsyncDryRun(
                        $this->remote,
                        $remotePath,
                        $localPath,
                        $options
                    );

                    if ($result->successful()) {
                        $counts = $this->parseRsyncDryRunOutput($result->output());
                        $this->filesToTransfer += $counts['transfer'];
                        $this->filesToDelete += $counts['delete'];
                    }
                }
            },
            message: __('remote-sync::messages.spinners.analyzing_files_to_pull')
        );

        $this->displayFilesPreview($this->filesToTransfer, $this->filesToDelete);
    }

    protected function syncPath(string $path): bool
    {
        $validationError = $this->validateStoragePath($path);

        if ($validationError !== null) {
            $this->components->error($validationError);

            return false;
        }

        $remotePath = "{$this->remote->storagePath()}/{$path}/";
        $localPath = storage_path($path);

        if (! is_dir($localPath)) {
            mkdir($localPath, 0755, true);
        }

        $localPath = rtrim($localPath, '/').'/';

        $this->components->info(__('remote-sync::messages.info.pulling_path', ['path' => $path]));

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
            $this->components->error(__('remote-sync::messages.errors.failed_pull_path', ['path' => $path, 'error' => $result->errorOutput()]));

            return false;
        }

        return true;
    }
}
