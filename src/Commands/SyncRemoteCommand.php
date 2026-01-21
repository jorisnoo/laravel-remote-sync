<?php

namespace Noo\LaravelRemoteSync\Commands;

use Illuminate\Console\Command;
use Noo\LaravelRemoteSync\Concerns\InteractsWithRemote;
use Noo\LaravelRemoteSync\RemoteSyncService;

use function Laravel\Prompts\multiselect;
use function Laravel\Prompts\select;

class SyncRemoteCommand extends Command
{
    use InteractsWithRemote;

    protected $signature = 'remote-sync:pull
        {remote? : The remote environment to sync from}
        {--no-backup : Skip creating a local backup before syncing database}
        {--keep-snapshot : Keep the downloaded snapshot file after loading}
        {--full : Include all tables (no exclusions) and drop tables before loading}
        {--delete : Delete local files that do not exist on remote}
        {--force : Skip confirmation prompt}';

    protected $description = 'Sync database and/or files from a remote environment';

    protected bool $shouldBackup;

    protected bool $fullImport;

    protected bool $keepSnapshot;

    protected bool $shouldDelete;

    public function handle(): int
    {
        if (! $this->ensureNotProduction()) {
            return self::FAILURE;
        }

        $remoteName = $this->argument('remote') ?? $this->selectRemote();

        if (! $remoteName) {
            $this->components->error('No remote environment selected.');

            return self::FAILURE;
        }

        try {
            $this->initializeRemote($remoteName);
        } catch (\InvalidArgumentException $e) {
            $this->components->error($e->getMessage());

            return self::FAILURE;
        }

        $operations = $this->selectOperations();

        if (empty($operations)) {
            $this->components->info('No operations selected.');

            return self::SUCCESS;
        }

        $syncDatabase = in_array('database', $operations);
        $syncFiles = in_array('files', $operations);

        if ($syncDatabase) {
            $this->shouldBackup = $this->promptBackupOption();
            $this->fullImport = $this->promptImportMode();
            $this->keepSnapshot = $this->promptKeepSnapshot();
        }

        if ($syncFiles) {
            $this->shouldDelete = $this->promptDeleteOption('local');
        }

        if (! $this->option('force') && ! $this->confirmSync($this->getOperationsSummary($syncDatabase, $syncFiles))) {
            $this->components->info('Operation cancelled.');

            return self::SUCCESS;
        }

        $exitCode = self::SUCCESS;

        if ($syncDatabase) {
            $exitCode = $this->syncDatabase();

            if ($exitCode !== self::SUCCESS) {
                return $exitCode;
            }
        }

        if ($syncFiles) {
            $exitCode = $this->syncFiles();
        }

        return $exitCode;
    }

    protected function selectRemote(): ?string
    {
        $remotes = app(RemoteSyncService::class)->getAvailableRemotes();

        if (empty($remotes)) {
            return null;
        }

        if (count($remotes) === 1) {
            return $remotes[0];
        }

        return select(
            label: 'Select remote environment',
            options: $remotes,
            default: config('remote-sync.default'),
        );
    }

    protected function selectOperations(): array
    {
        $options = [
            'database' => 'Database',
        ];

        if (! empty(config('remote-sync.paths', []))) {
            $options['files'] = 'Files';
        }

        return multiselect(
            label: 'What would you like to sync?',
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

    protected function syncDatabase(): int
    {
        $options = [
            'remote' => $this->remote->name,
            '--no-backup' => ! $this->shouldBackup,
            '--keep-snapshot' => $this->keepSnapshot,
            '--full' => $this->fullImport,
            '--force' => true,
        ];

        return $this->call(SyncDatabaseCommand::class, $options);
    }

    protected function syncFiles(): int
    {
        return $this->call(SyncFilesCommand::class, [
            'remote' => $this->remote->name,
            '--delete' => $this->shouldDelete,
            '--force' => true,
        ]);
    }
}
