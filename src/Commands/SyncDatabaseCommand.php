<?php

namespace Noo\LaravelRemoteSync\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Noo\LaravelRemoteSync\Concerns\InteractsWithRemote;
use Spatie\DbSnapshots\Commands\Create as SnapshotCreate;
use Spatie\DbSnapshots\Commands\Load as SnapshotLoad;

use function Laravel\Prompts\confirm;
use function Laravel\Prompts\spin;

class SyncDatabaseCommand extends Command
{
    use InteractsWithRemote;

    protected $signature = 'remote-sync:pull-db
        {remote? : The remote environment to sync from}
        {--no-backup : Skip creating a local backup before syncing}
        {--keep-snapshot : Keep the downloaded snapshot file after loading}
        {--full : Include all tables (no exclusions) and drop tables before loading}
        {--force : Skip confirmation prompt}';

    protected $description = 'Sync the database from a remote environment';

    protected string $snapshotName;

    protected bool $remoteSnapshotCreated = false;

    protected bool $shouldBackup;

    protected bool $fullImport;

    protected bool $keepSnapshot;

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

        if (! $this->option('force') && ! $this->confirmSync('database')) {
            $this->components->info(__('remote-sync::messages.info.operation_cancelled'));

            return self::SUCCESS;
        }

        $this->trap([SIGTERM, SIGINT], function () {
            $this->components->warn(__('remote-sync::messages.warnings.interrupt_cleanup'));
            $this->cleanupRemoteSnapshot();
            exit(1);
        });

        if ($this->shouldBackup) {
            $this->createLocalBackup();
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

        $this->components->success(__('remote-sync::messages.success.database_synced', ['name' => $this->remote->name]));

        return self::SUCCESS;
    }

    protected function createLocalBackup(): void
    {
        $backupName = 'local-before-sync-'.date('Y-m-d-H-i-s');
        $this->components->info(__('remote-sync::messages.info.creating_local_backup', ['name' => $backupName]));
        $this->call(SnapshotCreate::class, ['name' => $backupName]);
    }

    protected function createRemoteSnapshot(): bool
    {
        $result = spin(
            callback: fn () => $this->syncService->createRemoteSnapshot($this->remote, $this->snapshotName, $this->fullImport),
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

        $exitCode = $this->call(SnapshotLoad::class, [
            'name' => $this->snapshotName,
            '--force' => true,
            '--drop-tables' => $this->fullImport ? 1 : 0,
        ]);

        if ($exitCode !== 0) {
            $this->components->error(__('remote-sync::messages.errors.failed_load_snapshot'));

            return false;
        }

        $this->components->info(__('remote-sync::messages.info.snapshot_loaded'));

        if (! $this->fullImport) {
            $this->truncateExcludedTables();
        }

        return true;
    }

    protected function truncateExcludedTables(): void
    {
        $excludedTables = config('remote-sync.exclude_tables', []);

        if (empty($excludedTables)) {
            return;
        }

        $schemaBuilder = DB::connection()->getSchemaBuilder();

        $existingTables = $schemaBuilder->getTableListing();

        $tablesToTruncate = array_filter(
            $excludedTables,
            fn (string $table) => in_array($table, $existingTables, true)
        );

        if (empty($tablesToTruncate)) {
            return;
        }

        $schemaBuilder->disableForeignKeyConstraints();

        foreach ($tablesToTruncate as $table) {
            DB::table($table)->truncate();
        }

        $schemaBuilder->enableForeignKeyConstraints();
    }

    protected function checkEmptyDatabaseAndOfferMigrations(): bool
    {
        if ($this->fullImport) {
            return true;
        }

        if ($this->shouldSkipPrompts()) {
            return true;
        }

        $schemaBuilder = DB::connection()->getSchemaBuilder();
        $existingTables = $schemaBuilder->getTableListing();

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

            return true;
        }

        $normalizedLocal = $this->normalizeDriver($localDriver);
        $normalizedRemote = $this->normalizeDriver($remoteDriver);

        if ($normalizedLocal !== $normalizedRemote) {
            $this->components->error(
                __('remote-sync::messages.errors.driver_mismatch_sync', ['remote' => $remoteDriver, 'local' => $localDriver])
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
}
