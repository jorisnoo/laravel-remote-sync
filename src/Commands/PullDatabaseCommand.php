<?php

namespace Noo\LaravelRemoteSync\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Noo\LaravelRemoteSync\Concerns\InteractsWithRemote;
use Noo\LaravelRemoteSync\RemoteSyncService;
use Spatie\DbSnapshots\Commands\Create as SnapshotCreate;

use function Laravel\Prompts\confirm;
use function Laravel\Prompts\spin;

class PullDatabaseCommand extends Command
{
    use InteractsWithRemote;

    protected $signature = 'remote-sync:pull-db
        {remote? : The remote environment to pull from}
        {--no-backup : Skip creating a local backup before pulling}
        {--keep-snapshot : Keep the downloaded snapshot file after loading}
        {--full : Include all tables (no exclusions) and drop tables before loading}
        {--force : Skip confirmation prompt}';

    protected $description = 'Pull the database from a remote environment';

    protected string $snapshotName;

    protected bool $remoteSnapshotCreated = false;

    protected bool $shouldBackup;

    protected bool $fullImport;

    protected bool $keepSnapshot;

    /** @var array<int, string> */
    protected array $remoteTables = [];

    /** @var array<int, string> */
    protected array $localTables = [];

    /** @var array{local_only: array<int, string>, remote_only: array<int, string>} */
    protected array $migrationDiff = ['local_only' => [], 'remote_only' => []];

    protected bool $includeMigrations = false;

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
            $this->initializeRemote($remoteName);
        } catch (\InvalidArgumentException $e) {
            $this->components->error($e->getMessage());

            return self::FAILURE;
        }

        if (! $this->validateDatabaseCompatibility()) {
            return self::FAILURE;
        }

        $this->snapshotName = $this->generateSnapshotName();

        $this->shouldBackup = $this->promptBackupOption();
        $this->fullImport = $this->promptImportMode();
        $this->keepSnapshot = $this->promptKeepSnapshot();

        if (! $this->checkEmptyDatabaseAndOfferMigrations()) {
            return self::FAILURE;
        }

        $this->fetchAndDisplayPreview();

        if (! $this->option('force') && ! $this->confirmPull('database')) {
            $this->components->info(__('remote-sync::messages.info.operation_cancelled'));

            return self::SUCCESS;
        }

        if (! $this->fullImport && $this->hasMigrationMismatch()) {
            if (! $this->option('force') && ! $this->confirmMigrationMismatchPull()) {
                $this->components->info(__('remote-sync::messages.info.operation_cancelled'));

                return self::SUCCESS;
            }

            $this->includeMigrations = true;
        }

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
            $this->cleanupLocalSnapshot();
        }

        $this->cleanupRemoteSnapshot();

        $this->components->success(__('remote-sync::messages.success.database_pulled', ['name' => $this->remote->name]));

        return self::SUCCESS;
    }

    protected function fetchAndDisplayPreview(): void
    {
        $this->remoteTables = spin(
            callback: fn () => $this->syncService->getRemoteTableNames($this->remote),
            message: __('remote-sync::messages.spinners.fetching_remote_table_info')
        );

        $this->localTables = $this->syncService->getLocalTableNames();

        $excludedTables = config('remote-sync.exclude_tables', []);
        $this->migrationDiff = $this->compareMigrations();

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

        DB::table($usersTable)->whereNotIn('email', $filterUsers)->delete();

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

    protected function cleanupLocalSnapshot(): void
    {
        $snapshotPath = $this->syncService->getSnapshotPath()."/{$this->snapshotName}.sql.gz";

        if (file_exists($snapshotPath)) {
            unlink($snapshotPath);
            $this->components->info(__('remote-sync::messages.info.local_snapshot_removed'));
        }
    }

    protected function cleanupRemoteSnapshot(): void
    {
        if (! $this->remoteSnapshotCreated) {
            return;
        }

        $result = spin(
            callback: fn () => $this->syncService->deleteRemoteSnapshot($this->remote, $this->snapshotName),
            message: __('remote-sync::messages.spinners.cleaning_remote_snapshot')
        );

        if (! $result->successful()) {
            $this->components->warn(__('remote-sync::messages.warnings.manual_cleanup_needed', ['name' => $this->snapshotName]));
        }
    }

    protected function validateDatabaseCompatibility(): bool
    {
        $localDriver = config('database.connections.'.config('database.default').'.driver');

        $remoteDriver = spin(
            callback: fn () => $this->syncService->getRemoteDatabaseDriver($this->remote),
            message: __('remote-sync::messages.spinners.detecting_driver')
        );

        if ($remoteDriver === null) {
            $this->components->warn(__('remote-sync::messages.warnings.driver_detection_failed'));

            if (! $this->shouldSkipPrompts()
                && ! confirm(label: __('remote-sync::prompts.confirm.continue_without_driver'), default: false)) {
                return false;
            }

            return true;
        }

        $normalizedLocal = $this->normalizeDriver($localDriver);
        $normalizedRemote = $this->normalizeDriver($remoteDriver);

        if ($normalizedLocal !== $normalizedRemote) {
            $this->components->error(
                __('remote-sync::messages.errors.driver_mismatch_pull', ['remote' => $remoteDriver, 'local' => $localDriver])
            );
            $this->components->error(
                __('remote-sync::messages.errors.cross_database_not_supported')
            );

            return false;
        }

        return true;
    }

    protected function normalizeDriver(string $driver): string
    {
        return match (strtolower($driver)) {
            'mariadb' => 'mysql',
            default => strtolower($driver),
        };
    }

    protected function hasMigrationMismatch(): bool
    {
        return ! empty($this->migrationDiff['local_only']) || ! empty($this->migrationDiff['remote_only']);
    }

    protected function confirmMigrationMismatchPull(): bool
    {
        $this->newLine();
        $this->components->error(__('remote-sync::messages.pull.migration_mismatch_warning', ['name' => $this->remote->name]));

        return $this->confirmWithTypedYes(
            __('remote-sync::prompts.confirm.pull_migration_mismatch')
        );
    }
}
