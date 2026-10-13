<?php

namespace Noo\LaravelRemoteSync\Remotes;

use Noo\LaravelRemoteSync\Database\AppInfo;

readonly class RemoteInfo
{
    /**
     * @param  string  $workingPath  The app root; path/current for atomic deployments.
     */
    public function __construct(
        public string $workingPath,
        public string $phpBinary,
        public AppInfo $app,
    ) {}
}
