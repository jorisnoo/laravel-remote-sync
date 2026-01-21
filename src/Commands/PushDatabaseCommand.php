<?php

namespace Noo\LaravelRemoteSync\Commands;

use Illuminate\Console\Command;
use Noo\LaravelRemoteSync\Concerns\InteractsWithRemote;
use Spatie\DbSnapshots\Commands\Create as SnapshotCreate;

use function Laravel\Prompts\spin;

class PushDatabaseCommand extends Command
{
    use InteractsWithRemote;

    protected $signature = 'remote-sync:push-db
        {remote? : The remote environment to push to}
        {--force : Skip confirmation prompt}';

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

        if (! $this->validateDatabaseCompatibility()) {
            return self::FAILURE;
        }

        $this->snapshotName = $this->generateSnapshotName();

        if (! $this->option('force') && ! $this->confirmPush('database')) {
            $this->components->info(__('remote-sync::messages.info.operation_cancelled'));

            return self::SUCCESS;
        }

        if (defined('SIGTERM') && defined('SIGINT')) {
            $this->trap([SIGTERM, SIGINT], function () {
                $this->components->warn(__('remote-sync::messages.warnings.interrupt_cleanup'));
                $this->cleanupLocalSnapshot();
                exit(1);
            });
        }

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

        $this->components->success(__('remote-sync::messages.success.database_pushed', ['name' => $this->remote->name]));

        return self::SUCCESS;
    }

    protected function createRemoteBackup(): bool
    {
        $backupName = 'pre-push-backup-'.date('Y-m-d-H-i-s');

        $result = spin(
            callback: fn () => $this->syncService->createRemoteBackup($this->remote, $backupName),
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

        $exitCode = $this->call(SnapshotCreate::class, [
            'name' => $this->snapshotName,
            '--compress' => true,
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

    protected function cleanupLocalSnapshot(): void
    {
        if (! $this->localSnapshotCreated) {
            return;
        }

        $snapshotPath = $this->syncService->getSnapshotPath()."/{$this->snapshotName}.sql.gz";

        if (file_exists($snapshotPath)) {
            unlink($snapshotPath);
            $this->components->info(__('remote-sync::messages.info.local_snapshot_removed'));
        }
    }

    protected function cleanupRemoteSnapshot(): void
    {
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
                __('remote-sync::messages.errors.driver_mismatch_push', ['local' => $localDriver, 'remote' => $remoteDriver])
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
