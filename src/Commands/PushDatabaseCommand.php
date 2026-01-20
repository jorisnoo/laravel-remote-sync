<?php

namespace Noo\LaravelRemoteSync\Commands;

use Illuminate\Console\Command;
use Noo\LaravelRemoteSync\Concerns\InteractsWithRemote;
use Spatie\DbSnapshots\Commands\Create as SnapshotCreate;

use function Laravel\Prompts\spin;

class PushDatabaseCommand extends Command
{
    use InteractsWithRemote;

    protected $signature = 'remote-sync:push-database
        {remote? : The remote environment to push to}';

    protected $description = 'Push the local database to a remote environment';

    protected string $snapshotName;

    protected bool $localSnapshotCreated = false;

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

        $this->snapshotName = $this->generateSnapshotName();

        if (! $this->confirmPush('database')) {
            $this->components->info('Operation cancelled.');

            return self::SUCCESS;
        }

        $this->trap([SIGTERM, SIGINT], function () {
            $this->components->warn('Received interrupt signal, cleaning up...');
            $this->cleanupLocalSnapshot();
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

        $this->components->success("Database pushed to [{$this->remote->name}].");

        return self::SUCCESS;
    }

    protected function createRemoteBackup(): bool
    {
        $backupName = 'pre-push-backup-'.date('Y-m-d-H-i-s');

        $result = spin(
            callback: fn () => $this->syncService->createRemoteBackup($this->remote, $backupName),
            message: "Creating backup on [{$this->remote->name}]..."
        );

        if (! $result->successful()) {
            $this->components->error("Failed to create remote backup: {$result->errorOutput()}");

            return false;
        }

        $this->components->info("Remote backup created: {$backupName}");

        return true;
    }

    protected function createLocalSnapshot(): bool
    {
        $this->components->info("Creating local snapshot: {$this->snapshotName}");

        $excludeTables = config('remote-sync.exclude_tables', []);
        $excludeFlags = collect($excludeTables)
            ->map(fn (string $table) => "--exclude={$table}")
            ->toArray();

        $arguments = array_merge(
            ['name' => $this->snapshotName],
            array_fill_keys($excludeFlags, true),
            ['--compress' => true]
        );

        $exitCode = $this->call(SnapshotCreate::class, $arguments);

        if ($exitCode !== 0) {
            $this->components->error('Failed to create local snapshot.');

            return false;
        }

        $this->localSnapshotCreated = true;
        $this->components->info('Local snapshot created.');

        return true;
    }

    protected function uploadSnapshot(): bool
    {
        $localPath = $this->syncService->getSnapshotPath();

        $this->components->info("Uploading snapshot to [{$this->remote->name}]...");

        $result = $this->syncService->uploadSnapshot($this->remote, $this->snapshotName, $localPath);

        if (! $result->successful()) {
            $this->components->error("Failed to upload snapshot: {$result->errorOutput()}");

            return false;
        }

        $this->components->info('Snapshot uploaded.');

        return true;
    }

    protected function loadRemoteSnapshot(): bool
    {
        $result = spin(
            callback: fn () => $this->syncService->loadRemoteSnapshot($this->remote, $this->snapshotName),
            message: "Loading snapshot on [{$this->remote->name}]..."
        );

        if (! $result->successful()) {
            $this->components->error("Failed to load snapshot on remote: {$result->errorOutput()}");

            return false;
        }

        $this->components->info('Snapshot loaded on remote.');

        return true;
    }

    protected function cleanupLocalSnapshot(): void
    {
        if (! $this->localSnapshotCreated) {
            return;
        }

        $snapshotPath = $this->syncService->getSnapshotPath()."/{$this->snapshotName}.sql.gz";

        if (file_exists($snapshotPath)) {
            unlink($snapshotPath);
            $this->components->info('Local snapshot file removed.');
        }
    }

    protected function cleanupRemoteSnapshot(): void
    {
        $result = spin(
            callback: fn () => $this->syncService->deleteRemoteSnapshot($this->remote, $this->snapshotName),
            message: 'Cleaning up remote snapshot...'
        );

        if (! $result->successful()) {
            $this->components->warn("Failed to delete remote snapshot. You may need to manually clean up: {$this->snapshotName}");
        }
    }
}
