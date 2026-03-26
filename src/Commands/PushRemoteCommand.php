<?php

namespace Noo\LaravelRemoteSync\Commands;

use Illuminate\Console\Command;
use Noo\LaravelRemoteSync\Concerns\InteractsWithRemote;
use Noo\LaravelRemoteSync\Data\RemoteConfig;
use Noo\LaravelRemoteSync\RemoteSyncService;
use Spatie\DbSnapshots\Commands\Create as SnapshotCreate;

use function Laravel\Prompts\multiselect;
use function Laravel\Prompts\spin;

class PushRemoteCommand extends Command
{
    use InteractsWithRemote;

    protected $signature = 'remote-sync:push
        {remote? : The remote environment to push to}
        {--dry-run : Show what would be synced without making changes}
        {--delete : Delete remote files that do not exist locally}
        {--path= : Push only a specific path (relative to storage/)}
        {--force : Skip confirmation prompt}
        {--remote-host= : SSH host for the remote (bypasses config lookup)}
        {--remote-path= : Path on the remote (bypasses config lookup)}
        {--database : Push database only}
        {--files : Push files only}';

    protected $description = 'Push database and/or files to a remote environment';

    protected bool $isDryRun;

    protected bool $shouldDelete;

    protected bool $localSnapshotCreated = false;

    public function handle(): int
    {
        if (! $this->warnIfProduction()) {
            return self::SUCCESS;
        }

        if ($this->option('remote-host') && $this->option('remote-path')) {
            $this->syncService = app(RemoteSyncService::class);

            if ($this->option('force')) {
                $this->syncService->withoutTty();
            }

            $this->remote = new RemoteConfig(
                name: 'zentrale-sync',
                host: $this->option('remote-host'),
                path: $this->option('remote-path'),
                pushAllowed: true,
            );

            if ($this->remote->isAtomic === null) {
                $isAtomic = $this->syncService->isAtomicDeployment($this->remote);
                $this->remote = $this->remote->withAtomicDetection($isAtomic);
            }
        } else {
            $remoteName = $this->argument('remote') ?? $this->selectRemote(__('remote-sync::prompts.remote.push_label'));

            if (! $remoteName) {
                $this->components->error(__('remote-sync::messages.errors.no_remote_selected'));

                return self::FAILURE;
            }

            try {
                if (! $this->initializeRemote($remoteName)) {
                    return self::FAILURE;
                }
            } catch (\InvalidArgumentException $e) {
                $this->components->error($e->getMessage());

                return self::FAILURE;
            }

            if ($this->option('force')) {
                $this->syncService->withoutTty();
            }

            if (! $this->ensurePushAllowed()) {
                return self::FAILURE;
            }
        }

        $operations = $this->selectOperations();

        if (empty($operations)) {
            $this->components->info(__('remote-sync::messages.info.no_operations_selected'));

            return self::SUCCESS;
        }

        $pushDatabase = in_array('database', $operations);
        $pushFiles = in_array('files', $operations);

        if ($pushFiles) {
            $this->specificPath = $this->promptPathSelection();
            $this->isDryRun = $this->promptDryRunOption();
            $this->shouldDelete = $this->promptDeleteOption('remote');
        }

        $exitCode = self::SUCCESS;

        if ($pushDatabase) {
            $exitCode = $this->executePushDatabase();

            if ($exitCode !== self::SUCCESS) {
                return $exitCode;
            }
        }

        if ($pushFiles) {
            $exitCode = $this->executePushFiles();
        }

        return $exitCode;
    }

    protected function selectOperations(): array
    {
        if ($this->option('database') || $this->option('files')) {
            return array_filter([
                $this->option('database') ? 'database' : null,
                $this->option('files') ? 'files' : null,
            ]);
        }

        $options = [
            'database' => __('remote-sync::prompts.operations.database'),
        ];

        if (! empty(config('remote-sync.paths', []))) {
            $options['files'] = __('remote-sync::prompts.operations.files');
        }

        if ($this->shouldSkipPrompts()) {
            return array_keys($options);
        }

        return multiselect(
            label: __('remote-sync::prompts.operations.push_label'),
            options: $options,
            default: ['database'],
            required: true,
        );
    }

    // Database push methods

    protected function executePushDatabase(): int
    {
        if (! $this->validateDatabaseCompatibility('push')) {
            return self::FAILURE;
        }

        $this->snapshotName = $this->generateSnapshotName();

        $this->fetchAndDisplayDatabasePreview();

        if ($this->hasMigrationMismatch()) {
            $this->includeMigrations = true;
        }

        if (! $this->option('force') && ! $this->confirmPush($this->includeMigrations ? 'database (including migrations)' : 'database')) {
            $this->components->info(__('remote-sync::messages.info.operation_cancelled'));

            return self::SUCCESS;
        }

        $this->trap([SIGTERM, SIGINT], function () {
            $this->components->warn(__('remote-sync::messages.warnings.interrupt_cleanup'));
            $this->cleanupLocalSnapshot();
            $this->cleanupRemoteSnapshot();
            exit(1);
        });

        if (! $this->createRemoteBackup()) {
            return self::FAILURE;
        }

        if (! $this->createLocalSnapshot()) {
            return self::FAILURE;
        }

        if (! $this->uploadSnapshot()) {
            $this->cleanupLocalSnapshot();

            return self::FAILURE;
        }

        if (! $this->loadRemoteSnapshot()) {
            $this->cleanupLocalSnapshot();

            return self::FAILURE;
        }

        $this->cleanupLocalSnapshot();
        $this->cleanupRemoteSnapshot();

        $this->runRemoteMigrations();

        $this->components->success(__('remote-sync::messages.success.database_pushed', ['name' => $this->remote->name]));

        return self::SUCCESS;
    }

    protected function fetchAndDisplayDatabasePreview(): void
    {
        $this->localTables = $this->syncService->getLocalTableNames();
        $this->remoteTables = $this->fetchRemoteDatabaseInfo()['tables'];

        $excludedTables = config('remote-sync.exclude_tables', []);
        $this->migrationDiff = $this->compareMigrations();

        $this->displayDatabasePreview(
            $this->localTables,
            $this->remoteTables,
            $excludedTables,
            $this->migrationDiff,
            false,
            'push'
        );
    }

    protected function createRemoteBackup(): bool
    {
        $backupName = 'pre-push-backup-'.date('Y-m-d-H-i-s');

        $result = spin(
            callback: fn () => $this->syncService->createRemoteBackup($this->remote, $backupName, $this->includeMigrations),
            message: __('remote-sync::messages.spinners.creating_remote_backup', ['name' => $this->remote->name])
        );

        if (! $result->successful()) {
            $this->components->error(__('remote-sync::messages.errors.failed_remote_backup', ['error' => $result->errorOutput()]));

            return false;
        }

        $this->components->info(__('remote-sync::messages.info.remote_backup_created', ['name' => $backupName]));

        return true;
    }

    protected function createLocalSnapshot(): bool
    {
        $this->components->info(__('remote-sync::messages.info.creating_local_snapshot', ['name' => $this->snapshotName]));

        $preservedTables = $this->includeMigrations ? [] : RemoteSyncService::ALWAYS_PRESERVED_TABLES;
        $excludeTables = array_unique(array_merge(
            config('remote-sync.exclude_tables', []),
            $preservedTables
        ));

        $exitCode = $this->call(SnapshotCreate::class, [
            'name' => $this->snapshotName,
            '--compress' => true,
            '--exclude' => $excludeTables,
        ]);

        if ($exitCode !== 0) {
            $this->components->error(__('remote-sync::messages.errors.failed_local_snapshot'));

            return false;
        }

        $this->localSnapshotCreated = true;
        $this->components->info(__('remote-sync::messages.info.local_snapshot_created'));

        return true;
    }

    protected function uploadSnapshot(): bool
    {
        $localPath = $this->syncService->getSnapshotPath();

        $this->components->info(__('remote-sync::messages.info.uploading_snapshot', ['name' => $this->remote->name]));

        $result = $this->syncService->uploadSnapshot($this->remote, $this->snapshotName, $localPath);

        if (! $result->successful()) {
            $this->components->error(__('remote-sync::messages.errors.failed_upload_snapshot', ['error' => $result->errorOutput()]));

            return false;
        }

        $this->remoteSnapshotCreated = true;
        $this->components->info(__('remote-sync::messages.info.snapshot_uploaded'));

        return true;
    }

    protected function loadRemoteSnapshot(): bool
    {
        $result = spin(
            callback: fn () => $this->syncService->loadRemoteSnapshot($this->remote, $this->snapshotName),
            message: __('remote-sync::messages.spinners.loading_remote_snapshot', ['name' => $this->remote->name])
        );

        if (! $result->successful()) {
            $this->components->error(__('remote-sync::messages.errors.failed_remote_load', ['error' => $result->errorOutput()]));

            return false;
        }

        $this->components->info(__('remote-sync::messages.info.remote_snapshot_loaded'));

        return true;
    }

    protected function runRemoteMigrations(): void
    {
        try {
            $result = spin(
                callback: fn () => $this->syncService->runRemoteMigrations($this->remote),
                message: __('remote-sync::messages.info.running_remote_migrations', ['name' => $this->remote->name])
            );

            if (! $result->successful()) {
                $this->components->warn(__('remote-sync::messages.errors.remote_migrations_failed'));
                $this->components->warn($result->errorOutput());
            }
        } catch (\Exception $e) {
            $this->components->warn(__('remote-sync::messages.errors.remote_migrations_failed'));
            $this->components->warn($e->getMessage());
        }
    }

    protected function cleanupLocalSnapshot(): void
    {
        if (! $this->localSnapshotCreated) {
            return;
        }

        $this->cleanupLocalSnapshotFile();
    }

    // Files push methods

    protected function executePushFiles(): int
    {
        $paths = $this->getConfiguredPaths();

        if (empty($paths)) {
            $this->components->warn(__('remote-sync::messages.warnings.no_paths_push'));

            return self::SUCCESS;
        }

        $this->analyzeAndDisplayFilesPreview($paths);

        if ($this->isDryRun) {
            $this->components->info(__('remote-sync::messages.info.dry_run_mode'));

            return self::SUCCESS;
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

    protected function confirmDeleteOnRemote(): bool
    {
        $this->components->warn(__('remote-sync::messages.warnings.delete_warning', ['name' => $this->remote->name]));

        return $this->confirmWithTypedYes(
            __('remote-sync::prompts.confirm.delete_remote', ['name' => $this->remote->name])
        );
    }

    protected function analyzeAndDisplayFilesPreview(array $paths): void
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

                    $localPath = storage_path($path);

                    if (! is_dir($localPath)) {
                        continue;
                    }

                    $localPath = rtrim($localPath, '/').'/';
                    $remotePath = "{$this->remote->storagePath()}/{$path}/";

                    $options = $this->shouldDelete ? ['--delete'] : [];

                    $result = $this->syncService->rsyncUploadDryRun(
                        $this->remote,
                        $localPath,
                        $remotePath,
                        $options
                    );

                    if ($result->successful()) {
                        $counts = $this->parseRsyncDryRunOutput($result->output());
                        $this->filesToTransfer += $counts['transfer'];
                        $this->filesToDelete += $counts['delete'];
                        $this->transferFiles = array_merge($this->transferFiles, $counts['transfer_files']);
                        $this->deleteFiles = array_merge($this->deleteFiles, $counts['delete_files']);
                    }
                }
            },
            message: __('remote-sync::messages.spinners.analyzing_files')
        );

        $this->displayFilesPreview($this->filesToTransfer, $this->filesToDelete, 'push', $this->transferFiles, $this->deleteFiles);
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
