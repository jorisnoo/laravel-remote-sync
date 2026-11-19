<?php

namespace Noo\LaravelRemoteSync\Sync;

use Noo\LaravelRemoteSync\Remotes\RemoteInfo;
use Noo\LaravelRemoteSync\Snapshots\Importer;
use Noo\LaravelRemoteSync\Snapshots\Snapshots;
use RuntimeException;
use Throwable;

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
            ? array_values(array_intersect($excluded, $this->info->tables))
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

        if ($this->info->hasDbSnapshots && $this->info->snapshotDir === '') {
            throw new RuntimeException('Cannot sync files safely: the remote snapshot directory is unknown and backups cannot be excluded.');
        }

        foreach ($paths as $path) {
            $localPath = storage_path($path);
            $excludes[$path] = $this->snapshotDirExcludesFor($path);

            if ($direction === Direction::Push && ! is_dir($localPath)) {
                $changes[$path] = new FileChanges;

                continue;
            }

            $temporaryPath = null;

            try {
                if ($direction === Direction::Pull && ! is_dir($localPath)) {
                    // A first pull may target nested directories that do not exist yet.
                    // Compare with an empty directory without touching the destination.
                    $ancestor = $localPath;
                    while (! file_exists($ancestor) && ! is_link($ancestor)) {
                        $ancestor = dirname($ancestor);
                    }
                    if (! is_dir($ancestor)) {
                        throw new RuntimeException("Not a directory: {$ancestor}");
                    }

                    $localPath = sys_get_temp_dir().'/remote-sync-'.bin2hex(random_bytes(16));
                    if (! mkdir($localPath, 0700)) {
                        throw new RuntimeException('Could not create a temporary directory for the dry run.');
                    }
                    $temporaryPath = $localPath;
                }

                $changes[$path] = $this->rsync->dryRun(
                    $direction,
                    "{$this->info->storagePath()}/{$path}/",
                    rtrim($localPath, '/').'/',
                    excludes: $excludes[$path],
                    delete: true,
                );
            } catch (Throwable $e) {
                throw new RuntimeException("Could not analyze files for storage/{$path}: {$e->getMessage()}", previous: $e);
            } finally {
                if ($temporaryPath !== null) {
                    rmdir($temporaryPath);
                }
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
        $directories = [
            [$this->info->storagePath().'/'.$path, $this->info->snapshotDir],
            [storage_path($path), Snapshots::configuredLocalDir()],
        ];
        $excludes = [];

        foreach ($directories as [$syncDir, $snapshotDir]) {
            if ($snapshotDir === null || $snapshotDir === '') {
                continue;
            }

            $syncDir = rtrim(str_replace('\\', '/', $syncDir), '/').'/';
            $snapshotDir = rtrim(str_replace('\\', '/', $snapshotDir), '/').'/';
            if (str_starts_with($syncDir, $snapshotDir)) {
                return ['*'];
            }
            if (str_starts_with($snapshotDir, $syncDir)) {
                $excludes[] = '/'.rtrim(substr($snapshotDir, strlen($syncDir)), '/');
            }
        }

        return array_values(array_unique($excludes));
    }
}
