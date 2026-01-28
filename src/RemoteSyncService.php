<?php

namespace Noo\LaravelRemoteSync;

use Illuminate\Contracts\Process\ProcessResult;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Process;
use InvalidArgumentException;
use Noo\LaravelRemoteSync\Data\RemoteConfig;

class RemoteSyncService
{
    public function getRemote(?string $name = null): RemoteConfig
    {
        $name ??= config('remote-sync.default');
        $remotes = config('remote-sync.remotes', []);

        if (! isset($remotes[$name])) {
            throw new InvalidArgumentException("Remote '{$name}' is not configured.");
        }

        $remote = $remotes[$name];

        if (empty($remote['host']) || empty($remote['path'])) {
            throw new InvalidArgumentException("Remote '{$name}' is missing host or path configuration.");
        }

        return new RemoteConfig(
            name: $name,
            host: $remote['host'],
            path: $remote['path'],
            pushAllowed: $remote['push_allowed'] ?? false,
            isAtomic: str_ends_with($remote['path'], '/current') ? true : null,
        );
    }

    public function getAvailableRemotes(): array
    {
        return array_keys(config('remote-sync.remotes', []));
    }

    public function getSnapshotPath(): string
    {
        $diskName = config('db-snapshots.disk', 'snapshots');
        $diskConfig = config("filesystems.disks.{$diskName}");

        if ($diskConfig && isset($diskConfig['root'])) {
            return $diskConfig['root'];
        }

        return storage_path('snapshots');
    }

    public function getSnapshotSubdirectory(): string
    {
        $diskName = config('db-snapshots.disk', 'snapshots');
        $diskConfig = config("filesystems.disks.{$diskName}");

        if ($diskConfig && isset($diskConfig['root'])) {
            $root = $diskConfig['root'];
            $storagePath = storage_path();

            if (str_starts_with($root, $storagePath)) {
                return ltrim(substr($root, strlen($storagePath)), '/');
            }
        }

        return 'snapshots';
    }

    public function executeRemoteCommand(RemoteConfig $remote, string $command, ?int $timeout = null): ProcessResult
    {
        $timeout ??= 120;

        return Process::timeout($timeout)->run([
            'ssh',
            $remote->host,
            $command,
        ]);
    }

    public function isAtomicDeployment(RemoteConfig $remote): bool
    {
        $escapedPath = escapeshellarg("{$remote->path}/current");

        $result = $this->executeRemoteCommand(
            $remote,
            "test -d {$escapedPath} && echo 'yes' || echo 'no'",
            10
        );

        return trim($result->output()) === 'yes';
    }

    public function rsync(
        RemoteConfig $remote,
        string $sourcePath,
        string $destinationPath,
        array $options = [],
        ?int $timeout = null
    ): ProcessResult {
        $timeout ??= config('remote-sync.timeouts.file_sync', 1800);

        $defaultOptions = ['-avz', '--progress', '--exclude=.*'];

        $excludePaths = config('remote-sync.exclude_paths', []);
        $excludeOptions = collect($excludePaths)
            ->map(fn (string $pattern) => '--exclude='.escapeshellarg($pattern))
            ->all();

        $options = array_merge($defaultOptions, $excludeOptions, $options);

        $source = "{$remote->host}:{$sourcePath}";

        return Process::timeout($timeout)
            ->tty()
            ->run(array_merge(['rsync'], $options, [$source, $destinationPath]));
    }

    public function getRemoteDatabaseDriver(RemoteConfig $remote): ?string
    {
        $escapedPath = escapeshellarg($remote->workingPath());
        $command = "cd {$escapedPath} && php artisan tinker --execute=\"echo config('database.connections.' . config('database.default') . '.driver');\"";

        $result = $this->executeRemoteCommand($remote, $command, 30);

        if (! $result->successful()) {
            return null;
        }

        return trim($result->output()) ?: null;
    }

    public function createRemoteSnapshot(RemoteConfig $remote, string $snapshotName, bool $full = false): ProcessResult
    {
        $excludeFlags = '';

        if (! $full) {
            $excludeTables = config('remote-sync.exclude_tables', []);
            $excludeFlags = collect($excludeTables)
                ->map(fn (string $table) => '--exclude='.escapeshellarg($table))
                ->implode(' ');
        }

        $escapedPath = escapeshellarg($remote->workingPath());
        $escapedSnapshotName = escapeshellarg($snapshotName);
        $command = "cd {$escapedPath} && php artisan snapshot:create {$escapedSnapshotName} {$excludeFlags} --compress";
        $timeout = config('remote-sync.timeouts.snapshot_create', 300);

        return $this->executeRemoteCommand($remote, $command, $timeout);
    }

    public function getRemoteSnapshotPath(RemoteConfig $remote, string $snapshotName): string
    {
        $subdir = $this->getSnapshotSubdirectory();

        return "{$remote->storagePath()}/{$subdir}/{$snapshotName}.sql.gz";
    }

    public function downloadSnapshot(RemoteConfig $remote, string $snapshotName, string $localPath): ProcessResult
    {
        $remotePath = $this->getRemoteSnapshotPath($remote, $snapshotName);
        $timeout = config('remote-sync.timeouts.snapshot_download', 600);

        return Process::timeout($timeout)
            ->tty()
            ->run([
                'rsync',
                '-avz',
                '--progress',
                "{$remote->host}:{$remotePath}",
                $localPath,
            ]);
    }

    public function deleteRemoteSnapshot(RemoteConfig $remote, string $snapshotName): ProcessResult
    {
        $escapedPath = escapeshellarg($remote->workingPath());
        $escapedSnapshotName = escapeshellarg($snapshotName);
        $command = "cd {$escapedPath} && php artisan snapshot:delete {$escapedSnapshotName} --no-interaction";
        $timeout = config('remote-sync.timeouts.snapshot_cleanup', 60);

        return $this->executeRemoteCommand($remote, $command, $timeout);
    }

    public function listRemoteSnapshots(RemoteConfig $remote): ProcessResult
    {
        $subdir = $this->getSnapshotSubdirectory();
        $snapshotPath = "{$remote->storagePath()}/{$subdir}";
        $escapedSnapshotPath = escapeshellarg($snapshotPath);
        $command = "stat -c '%Y %n' {$escapedSnapshotPath}/*.sql.gz 2>/dev/null | sort -rn || true";
        $timeout = config('remote-sync.timeouts.snapshot_cleanup', 60);

        return $this->executeRemoteCommand($remote, $command, $timeout);
    }

    public function rsyncUpload(
        RemoteConfig $remote,
        string $sourcePath,
        string $destinationPath,
        array $options = [],
        ?int $timeout = null
    ): ProcessResult {
        $timeout ??= config('remote-sync.timeouts.file_sync', 1800);

        $defaultOptions = ['-avz', '--progress', '--exclude=.*'];

        $excludePaths = config('remote-sync.exclude_paths', []);
        $excludeOptions = collect($excludePaths)
            ->map(fn (string $pattern) => '--exclude='.escapeshellarg($pattern))
            ->all();

        $options = array_merge($defaultOptions, $excludeOptions, $options);

        $destination = "{$remote->host}:{$destinationPath}";

        return Process::timeout($timeout)
            ->tty()
            ->run(array_merge(['rsync'], $options, [$sourcePath, $destination]));
    }

    public function uploadSnapshot(RemoteConfig $remote, string $snapshotName, string $localPath): ProcessResult
    {
        $subdir = $this->getSnapshotSubdirectory();
        $remotePath = "{$remote->storagePath()}/{$subdir}/";
        $localFile = "{$localPath}/{$snapshotName}.sql.gz";
        $timeout = config('remote-sync.timeouts.snapshot_upload', 600);

        return Process::timeout($timeout)
            ->tty()
            ->run([
                'rsync',
                '-avz',
                '--progress',
                '--partial',
                $localFile,
                "{$remote->host}:{$remotePath}",
            ]);
    }

    public function loadRemoteSnapshot(RemoteConfig $remote, string $snapshotName): ProcessResult
    {
        $escapedPath = escapeshellarg($remote->workingPath());
        $escapedSnapshotName = escapeshellarg($snapshotName);
        $command = "cd {$escapedPath} && php artisan snapshot:load {$escapedSnapshotName} --force";
        $timeout = config('remote-sync.timeouts.snapshot_create', 300);

        return $this->executeRemoteCommand($remote, $command, $timeout);
    }

    public function createRemoteBackup(RemoteConfig $remote, string $backupName): ProcessResult
    {
        $excludeTables = config('remote-sync.exclude_tables', []);
        $excludeFlags = collect($excludeTables)
            ->map(fn (string $table) => '--exclude='.escapeshellarg($table))
            ->implode(' ');

        $escapedPath = escapeshellarg($remote->workingPath());
        $escapedBackupName = escapeshellarg($backupName);
        $command = "cd {$escapedPath} && php artisan snapshot:create {$escapedBackupName} {$excludeFlags} --compress";
        $timeout = config('remote-sync.timeouts.snapshot_create', 300);

        return $this->executeRemoteCommand($remote, $command, $timeout);
    }

    /**
     * Run rsync in dry-run mode for download and return output for analysis.
     */
    public function rsyncDryRun(
        RemoteConfig $remote,
        string $sourcePath,
        string $destinationPath,
        array $options = []
    ): ProcessResult {
        $defaultOptions = ['-avz', '--dry-run', '--itemize-changes'];

        $excludePaths = config('remote-sync.exclude_paths', []);
        $excludeOptions = collect($excludePaths)
            ->map(fn (string $pattern) => '--exclude='.escapeshellarg($pattern))
            ->all();

        $options = array_merge($defaultOptions, $excludeOptions, $options);

        $source = "{$remote->host}:{$sourcePath}";

        return Process::timeout(120)
            ->run(array_merge(['rsync'], $options, [$source, $destinationPath]));
    }

    /**
     * Run rsync in dry-run mode for upload and return output for analysis.
     */
    public function rsyncUploadDryRun(
        RemoteConfig $remote,
        string $sourcePath,
        string $destinationPath,
        array $options = []
    ): ProcessResult {
        $defaultOptions = ['-avz', '--dry-run', '--itemize-changes'];

        $excludePaths = config('remote-sync.exclude_paths', []);
        $excludeOptions = collect($excludePaths)
            ->map(fn (string $pattern) => '--exclude='.escapeshellarg($pattern))
            ->all();

        $options = array_merge($defaultOptions, $excludeOptions, $options);

        $destination = "{$remote->host}:{$destinationPath}";

        return Process::timeout(120)
            ->run(array_merge(['rsync'], $options, [$sourcePath, $destination]));
    }

    /**
     * Get table names and row counts from a remote database.
     *
     * @return array<string, int>
     */
    public function getRemoteTableInfo(RemoteConfig $remote): array
    {
        $escapedPath = escapeshellarg($remote->workingPath());
        $code = <<<'PHP'
$tables = DB::connection()->getSchemaBuilder()->getTableListing();
$info = [];
foreach ($tables as $table) {
    try {
        $info[$table] = DB::table($table)->count();
    } catch (\Throwable $e) {
        $info[$table] = 0;
    }
}
echo json_encode($info);
PHP;

        $escapedCode = escapeshellarg($code);
        $command = "cd {$escapedPath} && php artisan tinker --execute={$escapedCode}";

        $result = $this->executeRemoteCommand($remote, $command, 60);

        if (! $result->successful()) {
            return [];
        }

        $output = trim($result->output());

        $decoded = json_decode($output, true);

        if (! is_array($decoded)) {
            return [];
        }

        return $decoded;
    }

    /**
     * Get table names and row counts from the local database.
     *
     * @return array<string, int>
     */
    public function getLocalTableInfo(): array
    {
        $tables = DB::connection()->getSchemaBuilder()->getTableListing();
        $info = [];

        foreach ($tables as $table) {
            try {
                $info[$table] = DB::table($table)->count();
            } catch (\Throwable $e) {
                $info[$table] = 0;
            }
        }

        return $info;
    }
}
