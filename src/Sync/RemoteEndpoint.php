<?php

namespace Noo\LaravelRemoteSync\Sync;

use Illuminate\Contracts\Process\ProcessResult;
use Noo\LaravelRemoteSync\Database\AppInfo;
use Noo\LaravelRemoteSync\Remotes\Connection;
use Noo\LaravelRemoteSync\Remotes\RemoteInfo;

class RemoteEndpoint extends Endpoint
{
    public function __construct(
        public readonly Connection $connection,
        public readonly RemoteInfo $remote,
    ) {}

    public function label(): string
    {
        return "[{$this->connection->remote->name}]";
    }

    public function info(): AppInfo
    {
        return $this->remote->app;
    }

    public function artisan(array $arguments, int $timeout): ProcessResult
    {
        $command = array_map('escapeshellarg', [$this->remote->phpBinary, 'artisan', ...$arguments]);

        return $this->connection->run('cd '.escapeshellarg($this->remote->workingPath).' && '.implode(' ', $command), $timeout);
    }

    public function rsyncPath(string $path): string
    {
        return "{$this->connection->remote->host}:{$path}";
    }

    public function makeDirectory(string $path): void
    {
        $this->connection->run('mkdir -p '.escapeshellarg($path));
    }

    public function remove(string $path): bool
    {
        return $this->connection->run('rm -f '.escapeshellarg($path))->successful();
    }
}
