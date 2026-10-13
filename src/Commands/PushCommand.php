<?php

namespace Noo\LaravelRemoteSync\Commands;

use Noo\LaravelRemoteSync\Sync\Direction;

class PushCommand extends SyncCommand
{
    protected $signature = 'remote-sync:push
        {remote? : The remote to push to}
        {--database : Push the database}
        {--files : Push storage files}
        {--no-backup : Skip the backup of the remote database}
        {--keep-dump : Keep the transferred dump in storage/remote-sync on the remote}
        {--delete : Delete remote files that do not exist locally}
        {--path=* : Only sync these storage-relative paths}
        {--dry-run : Show the plan without changing anything}
        {--f|force : Answer the confirmation with yes}';

    protected $description = 'Replace a remote\'s database and/or storage files with the local ones';

    protected Direction $direction = Direction::Push;
}
