<?php

namespace Noo\LaravelRemoteSync\Commands;

use Illuminate\Console\Command;
use Noo\LaravelRemoteSync\Database\Inspector;

/**
 * Run over ssh by pull, push, prune and doctor to learn about the remote app.
 */
class InfoCommand extends Command
{
    /** The output line is KEY=json, so it parses like the probe's other lines. */
    public const KEY = 'REMOTE_SYNC_INFO';

    protected $signature = 'remote-sync:info';

    protected $description = 'Print what remote-sync needs to know about this app, as JSON';

    protected $hidden = true;

    public function handle(Inspector $inspector): int
    {
        $this->line(self::KEY.'='.json_encode($inspector->inspect()->toArray(), JSON_UNESCAPED_SLASHES));

        return self::SUCCESS;
    }
}
