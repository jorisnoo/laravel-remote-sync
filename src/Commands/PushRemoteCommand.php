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
        {remote? : The remote environment to push to}';

    protected $description = 'Push database and/or files to a remote environment';

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

        if (! $this->ensurePushAllowed()) {
            return self::FAILURE;
        }

        $operations = $this->selectOperations();

        if (empty($operations)) {
            $this->components->info('No operations selected.');

            return self::SUCCESS;
        }

        $exitCode = self::SUCCESS;

        if (in_array('database', $operations)) {
            $exitCode = $this->pushDatabase();

            if ($exitCode !== self::SUCCESS) {
                return $exitCode;
            }
        }

        if (in_array('files', $operations)) {
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
            label: 'Select remote environment to push to',
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
            label: 'What would you like to push?',
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
        ]);
    }
}
