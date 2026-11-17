<?php

namespace Noo\LaravelRemoteSync\Commands;

use Illuminate\Console\Command;
use Noo\LaravelRemoteSync\Database\Dumper;
use Noo\LaravelRemoteSync\Database\Dumps;
use Throwable;

/**
 * Run by pull and push, locally or over ssh, to dump the database into
 * storage/remote-sync.
 */
class DumpCommand extends Command
{
    protected $signature = 'remote-sync:dump
        {name : Dump name, without extension}
        {--exclude=* : Leave this table out completely}
        {--exclude-data=* : Dump only the structure of this table}';

    protected $description = 'Dump the database into storage/remote-sync';

    protected $hidden = true;

    public function handle(Dumper $dumper): int
    {
        try {
            $path = Dumps::path($this->argument('name'));
            Dumps::ensureDirectory();

            $dumper->dump($path, $this->option('exclude'), $this->option('exclude-data'));
        } catch (Throwable $e) {
            if (isset($path) && is_file($path)) {
                unlink($path);
            }

            $this->components->error($e->getMessage());

            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
