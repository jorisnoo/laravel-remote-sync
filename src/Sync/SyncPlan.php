<?php

namespace Noo\LaravelRemoteSync\Sync;

readonly class SyncPlan
{
    /**
     * @param  list<string>  $paths  Storage-relative paths in the files scope.
     * @param  list<string>  $tablesToSync
     * @param  list<string>  $tablesToTruncate  Truncated locally after a pull / preserved on the remote for a push.
     * @param  array<string, FileChanges>  $fileChanges  Keyed by storage-relative path.
     * @param  array<string, list<string>>  $fileExcludes  Extra rsync excludes, keyed by storage-relative path.
     * @param  list<string>|null  $filterUsers
     */
    public function __construct(
        public Direction $direction,
        public bool $database,
        public bool $files,
        public bool $full = false,
        public bool $backup = true,
        public bool $keepSnapshot = false,
        public bool $delete = false,
        public array $paths = [],
        public array $tablesToSync = [],
        public array $tablesToTruncate = [],
        public array $fileChanges = [],
        public array $fileExcludes = [],
        public ?array $filterUsers = null,
        public string $snapshotName = '',
        public string $backupName = '',
    ) {}

    public function scopeSummary(): string
    {
        return implode(' and ', array_filter([
            $this->database ? 'database' : null,
            $this->files ? 'files' : null,
        ]));
    }

    public function totalTransfers(): int
    {
        return array_sum(array_map(
            fn (FileChanges $changes) => $changes->transferCount(),
            $this->fileChanges
        ));
    }

    public function totalDeletions(): int
    {
        return array_sum(array_map(
            fn (FileChanges $changes) => $changes->deletionCount(),
            $this->fileChanges
        ));
    }
}
