<?php

namespace Noo\LaravelRemoteSync\Commands;

use Composer\InstalledVersions;
use Illuminate\Console\Command;
use Noo\LaravelRemoteSync\Snapshots\Importer;
use Noo\LaravelRemoteSync\Snapshots\Snapshots;

class InfoCommand extends Command
{
    public const PROTOCOL = 1;

    protected $signature = 'remote-sync:info';

    protected $description = 'Report database capabilities without credentials';

    public function handle(Importer $importer): int
    {
        $driver = Importer::localDriver();
        $this->line('REMOTE_SYNC_JSON='.json_encode([
            'protocol' => self::PROTOCOL,
            'snapshots_version' => InstalledVersions::getVersion('spatie/laravel-db-snapshots'),
            'driver' => in_array($driver, ['mysql', 'pgsql'], true) ? $driver : null,
            'tables' => $importer->localTables(),
            'snapshot_dir' => Snapshots::configuredLocalDir(),
        ], JSON_THROW_ON_ERROR));

        return self::SUCCESS;
    }
}
