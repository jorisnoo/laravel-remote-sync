<?php

namespace Noo\LaravelRemoteSync\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Noo\LaravelRemoteSync\Concerns\InteractsWithRemote;
use Noo\LaravelRemoteSync\RemoteSyncService;
use Spatie\DbSnapshots\Commands\Create as SnapshotCreate;

use function Laravel\Prompts\confirm;
use function Laravel\Prompts\multiselect;
use function Laravel\Prompts\spin;

class PullRemoteCommand extends Command
{
    use InteractsWithRemote;

    protected $signature = 'remote-sync:pull
        {remote? : The remote environment to pull from}
        {--no-backup : Skip creating a local backup before pulling database}
        {--keep-snapshot : Keep the downloaded snapshot file after loading}
        {--full : Include all tables (no exclusions) and drop tables before loading}
        {--delete : Delete local files that do not exist on remote}
        {--path= : Pull only a specific path (relative to storage/)}
        {--dry-run : Show what would be transferred without making changes}
        {--no-clear-cache : Skip clearing application cache after database pull}
        {--force : Skip confirmation prompt}';

    protected $description = 'Pull database and/or files from a remote environment';

    protected bool $shouldBackup;

    protected bool $fullImport;

    protected bool $keepSnapshot;

    protected bool $shouldDelete;

    protected bool $isDryRun = false;

    public function handle(): int
    {
        if (! $this->ensureNotProduction()) {
            return self::FAILURE;
        }

        $remoteName = $this->argument('remote') ?? $this->selectRemote();

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

        $operations = $this->selectOperations();

        if (empty($operations)) {
            $this->components->info(__('remote-sync::messages.info.no_operations_selected'));

            return self::SUCCESS;
        }

        $pullDatabase = in_array('database', $operations);
        $pullFiles = in_array('files', $operations);

        if ($pullDatabase) {
            $this->shouldBackup = $this->promptBackupOption();
            $this->fullImport = $this->promptImportMode();
            $this->keepSnapshot = $this->promptKeepSnapshot();
        }

        if ($pullFiles) {
            $this->specificPath = $this->promptPathSelection();
            $this->isDryRun = $this->promptDryRunOption();
            $this->shouldDelete = $this->promptDeleteOption('local');
        }

        if (! $this->option('force') && ! $this->confirmPull($this->getOperationsSummary($pullDatabase, $pullFiles))) {
            $this->components->info(__('remote-sync::messages.info.operation_cancelled'));

            return self::SUCCESS;
        }

        $exitCode = self::SUCCESS;

        if ($pullDatabase) {
            $exitCode = $this->executePullDatabase();

            if ($exitCode !== self::SUCCESS) {
                return $exitCode;
            }
        }

        if ($pullFiles) {
            $exitCode = $this->executePullFiles();
        }

        return $exitCode;
    }

    protected function selectOperations(): array
    {
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
            label: __('remote-sync::prompts.operations.pull_label'),
            options: $options,
            default: array_keys($options),
            required: true,
        );
    }

    protected function getOperationsSummary(bool $database, bool $files): string
    {
        $parts = [];

        if ($database) {
            $parts[] = 'database';
        }

        if ($files) {
            $parts[] = 'files';
        }

        return implode(' and ', $parts);
    }

    // Database pull methods

    protected function executePullDatabase(): int
    {
        if (! $this->validateDatabaseCompatibility('pull')) {
            return self::FAILURE;
        }

        $this->snapshotName = $this->generateSnapshotName();

        if (! $this->checkEmptyDatabaseAndOfferMigrations()) {
            return self::FAILURE;
        }

        if (! $this->fullImport) {
            $this->includeMigrations = true;
        }

        $this->fetchAndDisplayDatabasePreview();

        $this->trap([SIGTERM, SIGINT], function () {
            $this->components->warn(__('remote-sync::messages.warnings.interrupt_cleanup'));
            $this->cleanupRemoteSnapshot();
            exit(1);
        });

        if ($this->shouldBackup && ! $this->createLocalBackup()) {
            return self::FAILURE;
        }

        if (! $this->createRemoteSnapshot()) {
            return self::FAILURE;
        }

        if (! $this->downloadSnapshot()) {
            $this->cleanupRemoteSnapshot();

            return self::FAILURE;
        }

        if (! $this->loadSnapshot()) {
            $this->cleanupRemoteSnapshot();

            return self::FAILURE;
        }

        if (! $this->keepSnapshot) {
            $this->cleanupLocalSnapshotFile();
        }

        $this->cleanupRemoteSnapshot();

        if (! $this->fullImport) {
            $this->runMigrations();
        }

        if (! $this->option('no-clear-cache')) {
            $this->clearApplicationCache();
        }

        $this->components->success(__('remote-sync::messages.success.database_pulled', ['name' => $this->remote->name]));

        return self::SUCCESS;
    }

    protected function clearApplicationCache(): void
    {
        $this->call('optimize:clear');
        $this->components->info(__('remote-sync::messages.info.cache_cleared'));
    }

    protected function fetchAndDisplayDatabasePreview(): void
    {
        $this->remoteTables = spin(
            callback: fn () => $this->syncService->getRemoteTableNames($this->remote),
            message: __('remote-sync::messages.spinners.fetching_remote_table_info')
        );

        $this->localTables = $this->syncService->getLocalTableNames();

        $excludedTables = config('remote-sync.exclude_tables', []);

        if ($this->fullImport) {
            $this->migrationDiff = $this->compareMigrations();
        }

        $this->displayDatabasePreview(
            $this->remoteTables,
            $this->localTables,
            $excludedTables,
            $this->migrationDiff,
            $this->fullImport,
            'pull'
        );
    }

    protected function createLocalBackup(): bool
    {
        $backupName = 'local-before-sync-'.date('Y-m-d-H-i-s');
        $this->components->info(__('remote-sync::messages.info.creating_local_backup', ['name' => $backupName]));

        $exitCode = $this->call(SnapshotCreate::class, [
            'name' => $backupName,
            '--compress' => true,
        ]);

        if ($exitCode !== 0) {
            $this->components->error(__('remote-sync::messages.errors.failed_local_backup'));

            return false;
        }

        return true;
    }

    protected function createRemoteSnapshot(): bool
    {
        $result = spin(
            callback: fn () => $this->syncService->createRemoteSnapshot($this->remote, $this->snapshotName, $this->fullImport, $this->includeMigrations),
            message: __('remote-sync::messages.spinners.creating_remote_snapshot', ['name' => $this->remote->name])
        );

        if (! $result->successful()) {
            $this->components->error(__('remote-sync::messages.errors.failed_remote_snapshot', ['error' => $result->errorOutput()]));

            return false;
        }

        $this->remoteSnapshotCreated = true;
        $this->components->info(__('remote-sync::messages.info.remote_snapshot_created'));

        return true;
    }

    protected function downloadSnapshot(): bool
    {
        $localPath = $this->syncService->getSnapshotPath();

        if (! is_dir($localPath)) {
            mkdir($localPath, 0755, true);
        }

        $this->components->info(__('remote-sync::messages.info.downloading_snapshot', ['name' => $this->remote->name]));

        $result = $this->syncService->downloadSnapshot($this->remote, $this->snapshotName, $localPath);

        if (! $result->successful()) {
            $this->components->error(__('remote-sync::messages.errors.failed_download_snapshot', ['error' => $result->errorOutput()]));

            return false;
        }

        $this->components->info(__('remote-sync::messages.info.snapshot_downloaded'));

        return true;
    }

    protected function loadSnapshot(): bool
    {
        $this->components->info(__('remote-sync::messages.info.loading_snapshot'));

        $result = spin(
            callback: fn () => $this->syncService->loadSnapshotViaCli($this->snapshotName, $this->fullImport),
            message: __('remote-sync::messages.info.loading_snapshot')
        );

        if (! $result->successful()) {
            $this->components->error(__('remote-sync::messages.errors.failed_load_snapshot'));
            $this->components->error($result->errorOutput());

            return false;
        }

        $this->components->info(__('remote-sync::messages.info.snapshot_loaded'));

        if (! $this->fullImport) {
            $this->truncateExcludedTables();
        }

        $this->filterUsersTable();

        return true;
    }

    protected function truncateExcludedTables(): void
    {
        $excludedTables = config('remote-sync.exclude_tables', []);

        if (empty($excludedTables)) {
            return;
        }

        $existingTables = $this->syncService->getLocalTableNames();

        $tablesToTruncate = array_filter(
            $excludedTables,
            fn (string $table) => in_array($table, $existingTables, true)
                && ! in_array($table, RemoteSyncService::ALWAYS_PRESERVED_TABLES, true)
        );

        if (empty($tablesToTruncate)) {
            return;
        }

        $schema = DB::connection()->getSchemaBuilder();
        $schema->disableForeignKeyConstraints();

        try {
            foreach ($tablesToTruncate as $table) {
                DB::table($table)->truncate();
            }
        } finally {
            $schema->enableForeignKeyConstraints();
        }
    }

    protected function filterUsersTable(): void
    {
        $filterUsers = config('remote-sync.filter_users', false);

        if (! is_array($filterUsers) || empty($filterUsers)) {
            return;
        }

        $usersTable = config('auth.providers.users.table', 'users');

        if (! Schema::hasTable($usersTable)) {
            return;
        }

        $exactEmails = [];
        $wildcardPatterns = [];

        foreach ($filterUsers as $entry) {
            if (str_contains($entry, '*')) {
                $wildcardPatterns[] = str_replace('*', '%', $entry);
            } else {
                $exactEmails[] = $entry;
            }
        }

        DB::table($usersTable)
            ->where(function ($outer) use ($exactEmails, $wildcardPatterns) {
                if (! empty($exactEmails)) {
                    $outer->whereNotIn('email', $exactEmails);
                }

                if (! empty($wildcardPatterns)) {
                    foreach ($wildcardPatterns as $pattern) {
                        $outer->where('email', 'not like', $pattern);
                    }
                }
            })
            ->delete();

        $this->components->info(__('remote-sync::messages.info.filter_users_applied', [
            'count' => count($filterUsers),
            'table' => $usersTable,
        ]));
    }

    protected function checkEmptyDatabaseAndOfferMigrations(): bool
    {
        if ($this->fullImport) {
            return true;
        }

        if ($this->shouldSkipPrompts()) {
            return true;
        }

        $existingTables = $this->syncService->getLocalTableNames();

        if (! empty($existingTables)) {
            return true;
        }

        $runMigrations = confirm(
            label: __('remote-sync::prompts.empty_database.label'),
            default: true,
            hint: __('remote-sync::prompts.empty_database.hint'),
        );

        if (! $runMigrations) {
            return true;
        }

        $this->components->info(__('remote-sync::messages.info.running_migrations'));

        $exitCode = $this->call('migrate', ['--force' => true]);

        if ($exitCode !== 0) {
            $this->components->error(__('remote-sync::messages.errors.migrations_failed'));

            return false;
        }

        $this->components->info(__('remote-sync::messages.info.migrations_completed'));

        return true;
    }

    protected function runMigrations(): void
    {
        $this->components->info(__('remote-sync::messages.info.running_migrations'));

        try {
            $exitCode = $this->call('migrate', ['--force' => true]);

            if ($exitCode !== 0) {
                $this->components->warn(__('remote-sync::messages.errors.migrations_failed'));
            }
        } catch (\Exception $e) {
            $this->components->warn(__('remote-sync::messages.errors.migrations_failed'));
        }
    }

    // Files pull methods

    protected function executePullFiles(): int
    {
        $paths = $this->getConfiguredPaths();

        if (empty($paths)) {
            $this->components->warn(__('remote-sync::messages.warnings.no_paths_pull'));

            return self::SUCCESS;
        }

        $this->analyzeAndDisplayFilesPreview($paths);

        if ($this->isDryRun) {
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
                        $this->transferFiles = array_merge($this->transferFiles, $counts['transfer_files']);
                        $this->deleteFiles = array_merge($this->deleteFiles, $counts['delete_files']);
                    }
                }
            },
            message: __('remote-sync::messages.spinners.analyzing_files_to_pull')
        );

        $this->displayFilesPreview($this->filesToTransfer, $this->filesToDelete, 'pull', $this->transferFiles, $this->deleteFiles);
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
