<?php

namespace Noo\LaravelRemoteSync\Commands;

use Illuminate\Console\Command;
use Noo\LaravelRemoteSync\Snapshots\SnapshotDump;
use Throwable;

class DumpCommand extends Command
{
    protected $signature = 'remote-sync:dump {name} {--exclude=*} {--empty=*}';

    protected $description = 'Create a compressed database snapshot for remote-sync';

    public function handle(SnapshotDump $dump): int
    {
        try {
            $dump->create($this->argument('name'), $this->option('exclude'), $this->option('empty'));
        } catch (Throwable $e) {
            $this->components->error($e->getMessage());

            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
