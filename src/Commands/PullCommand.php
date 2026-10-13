<?php

namespace Noo\LaravelRemoteSync\Commands;

use Noo\LaravelRemoteSync\Sync\Direction;

class PullCommand extends SyncCommand
{
    protected $signature = 'remote-sync:pull
        {remote? : The remote to pull from}
        {--database : Pull the database}
        {--files : Pull storage files}
        {--full : Also copy the rows of excluded tables}
        {--no-backup : Skip the backup of the local database}
        {--keep-dump : Keep the transferred dump in storage/remote-sync}
        {--delete : Delete local files that do not exist on the remote}
        {--path=* : Only sync these storage-relative paths}
        {--dry-run : Show the plan without changing anything}
        {--f|force : Answer the confirmation with yes}';

    protected $description = 'Replace the local database and/or storage files with those of a remote';

    protected Direction $direction = Direction::Pull;
}
