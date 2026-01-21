<?php

namespace Noo\LaravelRemoteSync\Commands;

use Illuminate\Console\Command;
use Noo\LaravelRemoteSync\Concerns\InteractsWithRemote;
use Noo\LaravelRemoteSync\RemoteSyncService;

use function Laravel\Prompts\multiselect;
use function Laravel\Prompts\select;

class PushRemoteCommand extends Command
{
    use InteractsWithRemote;

    protected $signature = 'remote-sync:push
        {remote? : The remote environment to push to}
        {--dry-run : Show what would be synced without making changes}
        {--delete : Delete remote files that do not exist locally}';

    protected $description = 'Push database and/or files to a remote environment';

    protected bool $isDryRun;

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

        if (! $this->ensurePushAllowed()) {
            return self::FAILURE;
        }

        $operations = $this->selectOperations();

        if (empty($operations)) {
            $this->components->info(__('remote-sync::messages.info.no_operations_selected'));

            return self::SUCCESS;
        }

        $pushDatabase = in_array('database', $operations);
        $pushFiles = in_array('files', $operations);

        if ($pushFiles) {
            $this->isDryRun = $this->promptDryRunOption();
            $this->shouldDelete = $this->promptDeleteOption('remote');
        }

        $exitCode = self::SUCCESS;

        if ($pushDatabase) {
            $exitCode = $this->pushDatabase();

            if ($exitCode !== self::SUCCESS) {
                return $exitCode;
            }
        }

        if ($pushFiles) {
            $exitCode = $this->pushFiles();
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
            label: __('remote-sync::prompts.remote.push_label'),
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
            label: __('remote-sync::prompts.operations.push_label'),
            options: $options,
            default: ['database'],
            required: true,
        );
    }

    protected function pushDatabase(): int
    {
        return $this->call(PushDatabaseCommand::class, [
            'remote' => $this->remote->name,
        ]);
    }

    protected function pushFiles(): int
    {
        return $this->call(PushFilesCommand::class, [
            'remote' => $this->remote->name,
            '--dry-run' => $this->isDryRun,
            '--delete' => $this->shouldDelete,
            '--force' => true,
        ]);
    }
}
