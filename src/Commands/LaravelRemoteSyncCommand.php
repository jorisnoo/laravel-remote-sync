<?php

namespace Noo\LaravelRemoteSync\Commands;

use Illuminate\Console\Command;

class LaravelRemoteSyncCommand extends Command
{
    public $signature = 'laravel-remote-sync';

    public $description = 'My command';

    public function handle(): int
    {
        $this->comment('All done');

        return self::SUCCESS;
    }
}
