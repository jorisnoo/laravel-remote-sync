<?php

namespace Noo\LaravelRemoteSync\Commands;

use Illuminate\Console\Command;
use Noo\LaravelRemoteSync\Concerns\InteractsWithRemote;

use function Laravel\Prompts\multiselect;
use function Laravel\Prompts\select;

class SyncRemoteCommand extends Command
{
    use InteractsWithRemote;

    protected $signature = 'remote-sync:pull
        {remote? : The remote environment to sync from}
        {--no-backup : Skip creating a local backup before syncing database}
        {--keep-snapshot : Keep the downloaded snapshot file after loading}';

    protected $description = 'Sync database and/or files from a remote environment';

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

        $exitCode = self::SUCCESS;

        if (in_array('database', $operations)) {
            $exitCode = $this->syncDatabase();

            if ($exitCode !== self::SUCCESS) {
                return $exitCode;
            }
        }

        if (in_array('files', $operations)) {
            $exitCode = $this->syncFiles();
        }

        return $exitCode;
    }

    protected function selectRemote(): ?string
    {
        $remotes = $this->syncService->getAvailableRemotes();

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
        return multiselect(
            label: 'What would you like to sync?',
            options: [
                'database' => 'Database',
                'files' => 'Files',
            ],
            default: ['database', 'files'],
            required: true,
        );
    }

    protected function syncDatabase(): int
    {
        $options = [
            'remote' => $this->remote->name,
        ];

        if ($this->option('no-backup')) {
            $options['--no-backup'] = true;
        }

        if ($this->option('keep-snapshot')) {
            $options['--keep-snapshot'] = true;
        }

        return $this->call(SyncDatabaseCommand::class, $options);
    }

    protected function syncFiles(): int
    {
        return $this->call(SyncFilesCommand::class, [
            'remote' => $this->remote->name,
        ]);
    }
}
