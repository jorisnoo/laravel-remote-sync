<?php

namespace Noo\LaravelRemoteSync\Sync;

use Noo\LaravelRemoteSync\Remotes\RemoteInfo;
use Noo\LaravelRemoteSync\Snapshots\Importer;
use Noo\LaravelRemoteSync\Snapshots\Snapshots;
use RuntimeException;

class Planner
{
    public function __construct(
        protected RemoteInfo $info,
        protected Rsync $rsync,
        protected Importer $importer,
    ) {}

    /**
     * @param  list<string>  $paths
     *
     * @throws RuntimeException when a files dry-run fails
     */
    public function pull(
        bool $database,
        bool $files,
        bool $full = false,
        bool $backup = true,
        bool $keepSnapshot = false,
        bool $delete = false,
        array $paths = []
    ): SyncPlan {
        $excluded = Importer::excludedTables();

        $tablesToSync = $database
            ? ($full ? $this->info->tables : array_values(array_diff($this->info->tables, $excluded)))
            : [];

        $tablesToTruncate = ($database && ! $full)
            ? array_values(array_intersect($excluded, $this->importer->localTables()))
            : [];

        [$fileChanges, $fileExcludes] = $files
            ? $this->analyzeFiles(Direction::Pull, $paths)
            : [[], []];

        $filterUsers = config('remote-sync.filter_users', false);

        return new SyncPlan(
            direction: Direction::Pull,
            database: $database,
            files: $files,
            full: $full,
            backup: $backup,
            keepSnapshot: $keepSnapshot,
            delete: $delete,
            paths: $files ? $paths : [],
            tablesToSync: $tablesToSync,
            tablesToTruncate: $tablesToTruncate,
            fileChanges: $fileChanges,
            fileExcludes: $fileExcludes,
            filterUsers: ($database && is_array($filterUsers) && $filterUsers !== []) ? array_values($filterUsers) : null,
            snapshotName: $database ? Snapshots::transferName() : '',
            backupName: ($database && $backup) ? Snapshots::pullBackupName() : '',
        );
    }

    /**
     * @param  list<string>  $paths
     *
     * @throws RuntimeException when a files dry-run fails
     */
    public function push(
        bool $database,
        bool $files,
        bool $backup = true,
        bool $delete = false,
        array $paths = []
    ): SyncPlan {
        $excluded = Importer::excludedTables();

        $localTables = $database ? $this->importer->localTables() : [];

        $tablesToSync = $database
            ? array_values(array_diff($localTables, $excluded))
            : [];

        $tablesToPreserve = $database
            ? array_values(array_intersect($excluded, $this->info->tables))
            : [];

        [$fileChanges, $fileExcludes] = $files
            ? $this->analyzeFiles(Direction::Push, $paths)
            : [[], []];

        return new SyncPlan(
            direction: Direction::Push,
            database: $database,
            files: $files,
            backup: $backup,
            delete: $delete,
            paths: $files ? $paths : [],
            tablesToSync: $tablesToSync,
            tablesToTruncate: $tablesToPreserve,
            fileChanges: $fileChanges,
            fileExcludes: $fileExcludes,
            snapshotName: $database ? Snapshots::transferName() : '',
            backupName: ($database && $backup) ? Snapshots::pushBackupName() : '',
        );
    }

    /**
     * Dry-run every path to discover transfers and deletable files. The
     * dry-run always includes --delete so the preview can report what a
     * deletion pass would remove, even when the user did not opt in.
     *
     * @param  list<string>  $paths
     * @return array{0: array<string, FileChanges>, 1: array<string, list<string>>}
     */
    protected function analyzeFiles(Direction $direction, array $paths): array
    {
        $changes = [];
        $excludes = [];

        foreach ($paths as $path) {
            $localPath = storage_path($path);

            if ($direction === Direction::Pull && ! is_dir($localPath)) {
                mkdir($localPath, 0755, true);
            }

            if ($direction === Direction::Push && ! is_dir($localPath)) {
                $changes[$path] = new FileChanges;
                $excludes[$path] = [];

                continue;
            }

            $excludes[$path] = $this->snapshotDirExcludesFor($path);

            try {
                $changes[$path] = $this->rsync->dryRun(
                    $direction,
                    "{$this->info->storagePath()}/{$path}/",
                    rtrim($localPath, '/').'/',
                    excludes: $excludes[$path],
                    delete: true,
                );
            } catch (RuntimeException $e) {
                throw new RuntimeException("Could not analyze files for storage/{$path}: {$e->getMessage()}");
            }
        }

        return [$changes, $excludes];
    }

    /**
     * Exclude patterns keeping snapshot directories out of file syncs,
     * wherever the local and remote snapshot disks actually live.
     *
     * @return list<string>
     */
    protected function snapshotDirExcludesFor(string $path): array
    {
        $candidates = [];

        $remoteStorage = $this->info->storagePath();

        if (str_starts_with($this->info->snapshotDir, "{$remoteStorage}/")) {
            $candidates[] = substr($this->info->snapshotDir, strlen($remoteStorage) + 1);
        }

        $localDir = Snapshots::localDir();
        $localStorage = storage_path();

        if (str_starts_with($localDir, $localStorage.DIRECTORY_SEPARATOR)) {
            $candidates[] = str_replace('\\', '/', substr($localDir, strlen($localStorage) + 1));
        }

        $excludes = [];

        foreach (array_unique($candidates) as $storageRelative) {
            if (str_starts_with($storageRelative, "{$path}/")) {
                $excludes[] = '/'.substr($storageRelative, strlen($path) + 1);
            }
        }

        return array_values(array_unique($excludes));
    }
}
