<?php

namespace Noo\LaravelRemoteSync\Remotes;

readonly class RemoteInfo
{
    /**
     * @param  string|null  $phpBinary  Detected or configured PHP binary; null when detection failed.
     * @param  string  $snapshotDir  Absolute path where spatie/laravel-db-snapshots writes on the remote.
     * @param  list<string>  $tables
     */
    public function __construct(
        public ?string $phpBinary,
        public bool $isAtomic,
        public string $workingPath,
        public string $snapshotDir,
        public ?string $driver,
        public array $tables,
        public bool $hasDbSnapshots,
        public bool $hasRsync,
    ) {}

    public function storagePath(): string
    {
        return "{$this->workingPath}/storage";
    }
}
