<?php

namespace Noo\LaravelRemoteSync\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Noo\LaravelRemoteSync\Concerns\InteractsWithRemote;
use Spatie\DbSnapshots\Commands\Create as SnapshotCreate;
use Spatie\DbSnapshots\Commands\Load as SnapshotLoad;

use function Laravel\Prompts\spin;

class SyncDatabaseCommand extends Command
{
    use InteractsWithRemote;

    protected $signature = 'remote-sync:pull-database
        {remote? : The remote environment to sync from}
        {--no-backup : Skip creating a local backup before syncing}
        {--keep-snapshot : Keep the downloaded snapshot file after loading}';

    protected $description = 'Sync the database from a remote environment';

    protected string $snapshotName;

    protected bool $remoteSnapshotCreated = false;

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

        $this->snapshotName = $this->generateSnapshotName();

        if (! $this->confirmSync('database')) {
            $this->components->info('Operation cancelled.');

            return self::SUCCESS;
        }

        $this->trap([SIGTERM, SIGINT], function () {
            $this->components->warn('Received interrupt signal, cleaning up...');
            $this->cleanupRemoteSnapshot();
            exit(1);
        });

        if (! $this->option('no-backup')) {
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

        if (! $this->option('keep-snapshot')) {
            $this->cleanupLocalSnapshot();
        }

        $this->cleanupRemoteSnapshot();

        $this->components->success("Database synced from [{$this->remote->name}].");

        return self::SUCCESS;
    }

    protected function createLocalBackup(): void
    {
        $backupName = 'local-before-sync-'.date('Y-m-d-H-i-s');
        $this->components->info("Creating local backup: {$backupName}");
        $this->call(SnapshotCreate::class, ['name' => $backupName]);
    }

    protected function createRemoteSnapshot(): bool
    {
        $result = spin(
            callback: fn () => $this->syncService->createRemoteSnapshot($this->remote, $this->snapshotName),
            message: "Creating snapshot on [{$this->remote->name}]..."
        );

        if (! $result->successful()) {
            $this->components->error("Failed to create remote snapshot: {$result->errorOutput()}");

            return false;
        }

        $this->remoteSnapshotCreated = true;
        $this->components->info('Remote snapshot created.');

        return true;
    }

    protected function downloadSnapshot(): bool
    {
        $localPath = $this->syncService->getSnapshotPath();

        if (! is_dir($localPath)) {
            mkdir($localPath, 0755, true);
        }

        $this->components->info("Downloading snapshot from [{$this->remote->name}]...");

        $result = $this->syncService->downloadSnapshot($this->remote, $this->snapshotName, $localPath);

        if (! $result->successful()) {
            $this->components->error("Failed to download snapshot: {$result->errorOutput()}");

            return false;
        }

        $this->components->info('Snapshot downloaded.');

        return true;
    }

    protected function loadSnapshot(): bool
    {
        $this->components->info('Loading snapshot into database...');

        $this->dropNonExcludedTables();

        // Disable query logging to prevent memory exhaustion on large snapshots
        $connection = DB::connection();
        $wasLogging = $connection->logging();
        $connection->disableQueryLog();

        try {
            $exitCode = $this->call(SnapshotLoad::class, [
                'name' => $this->snapshotName,
                '--force' => true,
                '--drop-tables' => 0,
            ]);
        } finally {
            if ($wasLogging) {
                $connection->enableQueryLog();
            }
        }

        if ($exitCode !== 0) {
            $this->components->error('Failed to load snapshot.');

            return false;
        }

        $this->components->info('Snapshot loaded.');

        $this->truncateExcludedTables();

        return true;
    }

    protected function dropNonExcludedTables(): void
    {
        $excludedTables = config('remote-sync.exclude_tables', []);
        $schemaBuilder = DB::connection()->getSchemaBuilder();

        $allTables = $schemaBuilder->getTableListing();

        $tablesToDrop = array_filter(
            $allTables,
            fn (string $table) => ! in_array($table, $excludedTables, true)
        );

        if (empty($tablesToDrop)) {
            return;
        }

        $schemaBuilder->disableForeignKeyConstraints();

        foreach ($tablesToDrop as $table) {
            $schemaBuilder->drop($table);
        }

        $schemaBuilder->enableForeignKeyConstraints();
    }

    protected function truncateExcludedTables(): void
    {
        $excludedTables = config('remote-sync.exclude_tables', []);
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

    protected function cleanupLocalSnapshot(): void
    {
        $snapshotPath = $this->syncService->getSnapshotPath()."/{$this->snapshotName}.sql.gz";

        if (file_exists($snapshotPath)) {
            unlink($snapshotPath);
            $this->components->info('Local snapshot file removed.');
        }
    }

    protected function cleanupRemoteSnapshot(): void
    {
        if (! $this->remoteSnapshotCreated) {
            return;
        }

        $result = spin(
            callback: fn () => $this->syncService->deleteRemoteSnapshot($this->remote, $this->snapshotName),
            message: 'Cleaning up remote snapshot...'
        );

        if (! $result->successful()) {
            $this->components->warn("Failed to delete remote snapshot. You may need to manually clean up: {$this->snapshotName}");
        }
    }
}
