<?php

namespace Noo\LaravelRemoteSync\Commands;

use Illuminate\Console\Command;
use Noo\LaravelRemoteSync\Database\Dumps;
use Noo\LaravelRemoteSync\Database\Loader;
use Throwable;

use function Laravel\Prompts\confirm;

/**
 * Loads a dump from storage/remote-sync. Pull and push use it for the
 * transfer dump; people use it to restore a pre-pull or pre-push backup.
 */
class LoadCommand extends Command
{
    protected $signature = 'remote-sync:load
        {name : Dump name, e.g. pre-pull-2026-10-13-120000}
        {--fresh : Drop all tables first, so the database matches the dump exactly}
        {--f|force : Skip the confirmation}';

    protected $description = 'Load a remote-sync dump (or restore a backup) into the database';

    public function handle(Loader $loader): int
    {
        try {
            $path = Dumps::path($this->argument('name'));
        } catch (Throwable $e) {
            $this->components->error($e->getMessage());

            return self::FAILURE;
        }

        if (! $this->option('force')) {
            if (! $this->input->isInteractive()) {
                $this->components->error('Confirmation required: re-run with --force.');

                return self::FAILURE;
            }

            $replace = $this->option('fresh') ? 'Replace the whole database' : 'Replace the tables in the dump';

            if (! confirm("{$replace} with {$this->argument('name')}?", default: false)) {
                return self::SUCCESS;
            }
        }

        try {
            $loader->load($path, fresh: (bool) $this->option('fresh'));
        } catch (Throwable $e) {
            $this->components->error($e->getMessage());

            return self::FAILURE;
        }

        $this->components->info("Loaded {$this->argument('name')}.");

        return self::SUCCESS;
    }
}
