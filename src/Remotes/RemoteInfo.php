<?php

namespace Noo\LaravelRemoteSync\Remotes;

use Noo\LaravelRemoteSync\Commands\InfoCommand;

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
        public ?int $protocol = null,
        public ?string $snapshotsVersion = null,
    ) {}

    public function databaseProblem(): ?string
    {
        return match (true) {
            ! $this->hasDbSnapshots => 'spatie/laravel-db-snapshots is not installed on the remote.',
            $this->protocol !== InfoCommand::PROTOCOL => 'Install a compatible version of jorisnoo/laravel-remote-sync on the remote (0.8 protocol required).',
            version_compare($this->snapshotsVersion ?? '0', '2.7.0', '<') => 'The remote needs spatie/laravel-db-snapshots >= 2.7.0.',
            $this->driver === null => 'Could not detect the remote database driver.',
            $this->snapshotDir === '' => 'The remote snapshots disk requires a local driver and a safe absolute directory root.',
            default => null,
        };
    }

    public function storagePath(): string
    {
        return "{$this->workingPath}/storage";
    }
}
