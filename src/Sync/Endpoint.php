<?php

namespace Noo\LaravelRemoteSync\Sync;

use Illuminate\Contracts\Process\ProcessResult;
use Noo\LaravelRemoteSync\Database\AppInfo;
use Noo\LaravelRemoteSync\Database\Dumps;

/**
 * One side of a sync: this machine or a remote. Pull and push are the same
 * steps with source and target swapped, so everything a step needs from a
 * side goes through this interface.
 */
abstract class Endpoint
{
    /** How the side appears in messages: "local" or "[staging]". */
    abstract public function label(): string;

    abstract public function info(): AppInfo;

    /**
     * Run an artisan command in the side's app.
     *
     * @param  list<string>  $arguments
     */
    abstract public function artisan(array $arguments, int $timeout): ProcessResult;

    /** An absolute path on this side, as rsync addresses it. */
    abstract public function rsyncPath(string $path): string;

    abstract public function makeDirectory(string $path): void;

    abstract public function remove(string $path): bool;

    public function storagePath(string $path = ''): string
    {
        return rtrim($this->info()->storagePath.'/'.$path, '/');
    }

    public function dumpPath(string $name): string
    {
        return $this->storagePath(Dumps::relativePath($name));
    }

    /**
     * @return list<array{name: string, mtime: int}> newest first
     */
    public function dumps(): array
    {
        return $this->info()->dumps;
    }
}
