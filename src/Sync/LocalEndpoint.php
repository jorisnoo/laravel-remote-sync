<?php

namespace Noo\LaravelRemoteSync\Sync;

use Illuminate\Contracts\Process\ProcessResult;
use Illuminate\Support\Facades\Process;
use Noo\LaravelRemoteSync\Database\AppInfo;
use Noo\LaravelRemoteSync\Database\Dumps;
use Noo\LaravelRemoteSync\Database\Inspector;

class LocalEndpoint extends Endpoint
{
    protected ?AppInfo $info = null;

    public function __construct(protected Inspector $inspector) {}

    public function label(): string
    {
        return 'local';
    }

    /**
     * Inspected on first use, so commands that only need the dump directory
     * (prune) work without a database connection.
     */
    public function info(): AppInfo
    {
        return $this->info ??= $this->inspector->inspect();
    }

    /**
     * Artisan runs in a child process, exactly as it does on the remote.
     */
    public function artisan(array $arguments, int $timeout): ProcessResult
    {
        return Process::path(base_path())
            ->timeout($timeout)
            ->run([PHP_BINARY, 'artisan', ...$arguments]);
    }

    public function rsyncPath(string $path): string
    {
        return $path;
    }

    public function makeDirectory(string $path): void
    {
        if (! is_dir($path)) {
            mkdir($path, 0755, true);
        }
    }

    public function remove(string $path): bool
    {
        return ! file_exists($path) || unlink($path);
    }

    public function storagePath(string $path = ''): string
    {
        return storage_path($path);
    }

    public function dumps(): array
    {
        return Dumps::list();
    }
}
