<?php

namespace Noo\LaravelRemoteSync\Commands;

use Illuminate\Console\Command;
use Noo\LaravelRemoteSync\Snapshots\Importer;
use Throwable;

class LoadCommand extends Command
{
    protected $signature = 'remote-sync:load
        {name : Snapshot name on the configured local snapshots disk (without .sql.gz)}
        {--f|force : Restore without confirmation}';

    protected $description = 'Restore a compressed database snapshot with the native database client';

    public function handle(Importer $importer): int
    {
        if (! $this->option('force')) {
            if (! $this->input->isInteractive()) {
                $this->components->error(__('remote-sync::messages.errors.confirmation_required'));

                return self::FAILURE;
            }

            if (! $this->confirm('Restore the snapshot and overwrite data in the configured database?')) {
                $this->components->info(__('remote-sync::messages.info.operation_cancelled'));

                return self::FAILURE;
            }
        }

        try {
            $result = $importer->import($this->argument('name'));

            if ($result->failed()) {
                $this->components->error(__('remote-sync::messages.errors.failed_import', ['error' => trim($result->errorOutput())]));

                return self::FAILURE;
            }
        } catch (Throwable $e) {
            $this->components->error($e->getMessage());

            return self::FAILURE;
        }

        $this->components->info('Snapshot restored.');

        return self::SUCCESS;
    }
}
