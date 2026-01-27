<?php

namespace Noo\LaravelRemoteSync\Commands;

use Illuminate\Console\Command;
use Noo\LaravelRemoteSync\Concerns\InteractsWithRemote;
use Noo\LaravelRemoteSync\RemoteSyncService;

use function Laravel\Prompts\multiselect;
use function Laravel\Prompts\select;

class PullRemoteCommand extends Command
{
    use InteractsWithRemote;

    protected $signature = 'remote-sync:pull
        {remote? : The remote environment to pull from}
        {--no-backup : Skip creating a local backup before pulling database}
        {--keep-snapshot : Keep the downloaded snapshot file after loading}
        {--full : Include all tables (no exclusions) and drop tables before loading}
        {--delete : Delete local files that do not exist on remote}
        {--force : Skip confirmation prompt}';

    protected $description = 'Pull database and/or files from a remote environment';

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
            $this->components->error(__('remote-sync::messages.errors.no_remote_selected'));

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
            $this->shouldDelete = $this->promptDeleteOption('local');
        }

        if (! $this->option('force') && ! $this->confirmPull($this->getOperationsSummary($pullDatabase, $pullFiles))) {
            $this->components->info(__('remote-sync::messages.info.operation_cancelled'));

            return self::SUCCESS;
        }

        $exitCode = self::SUCCESS;

        if ($pullDatabase) {
            $exitCode = $this->pullDatabase();

            if ($exitCode !== self::SUCCESS) {
                return $exitCode;
            }
        }

        if ($pullFiles) {
            $exitCode = $this->pullFiles();
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
            label: __('remote-sync::prompts.remote.label'),
            options: $remotes,
            default: config('remote-sync.default'),
        );
    }

    protected function selectOperations(): array
    {
        $options = [
            'database' => __('remote-sync::prompts.operations.database'),
        ];

        if (! empty(config('remote-sync.paths', []))) {
            $options['files'] = __('remote-sync::prompts.operations.files');
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

    protected function pullDatabase(): int
    {
        $options = [
            'remote' => $this->remote->name,
            '--no-backup' => ! $this->shouldBackup,
            '--keep-snapshot' => $this->keepSnapshot,
            '--full' => $this->fullImport,
            '--force' => true,
        ];

        return $this->call(PullDatabaseCommand::class, $options);
    }

    protected function pullFiles(): int
    {
        return $this->call(PullFilesCommand::class, [
            'remote' => $this->remote->name,
            '--delete' => $this->shouldDelete,
            '--force' => true,
        ]);
    }
}
