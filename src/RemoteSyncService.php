<?php

namespace Noo\LaravelRemoteSync;

use Illuminate\Contracts\Process\ProcessResult;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Process;
use InvalidArgumentException;
use Noo\LaravelRemoteSync\Data\RemoteConfig;
use RuntimeException;

class RemoteSyncService
{
    /** @var array<int, string> */
    public const ALWAYS_PRESERVED_TABLES = ['migrations'];

    /** @var array<string, string> */
    protected array $remoteSnapshotSubdirs = [];

    protected bool $useTty = true;

    public function withoutTty(): static
    {
        $this->useTty = false;

        return $this;
    }

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
            ->map(fn (string $pattern) => '--exclude='.$pattern)
            ->all();

        $options = array_merge($defaultOptions, $excludeOptions, $options);

        $source = "{$remote->host}:{$sourcePath}";

        $process = Process::timeout($timeout);

        if ($this->useTty) {
            $process = $process->tty();
        }

        return $process->run(array_merge(['rsync'], $options, [$source, $destinationPath]));
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
            $excludeTables = array_unique(array_merge(
                config('remote-sync.exclude_tables', []),
                self::ALWAYS_PRESERVED_TABLES
            ));
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
        $subdir = $this->getRemoteSnapshotSubdirectory($remote);

        return "{$remote->storagePath()}/{$subdir}/{$snapshotName}.sql.gz";
    }

    public function getRemoteSnapshotSubdirectory(RemoteConfig $remote): string
    {
        if (isset($this->remoteSnapshotSubdirs[$remote->name])) {
            return $this->remoteSnapshotSubdirs[$remote->name];
        }

        $escapedPath = escapeshellarg($remote->workingPath());
        $code = <<<'PHP'
$diskName = config('db-snapshots.disk', 'snapshots');
$diskConfig = config("filesystems.disks.{$diskName}");
if ($diskConfig && isset($diskConfig['root'])) {
    $root = $diskConfig['root'];
    $storagePath = storage_path();
    if (str_starts_with($root, $storagePath)) {
        echo ltrim(substr($root, strlen($storagePath)), '/');
        return;
    }
}
echo 'snapshots';
PHP;

        $escapedCode = escapeshellarg($code);
        $command = "cd {$escapedPath} && php artisan tinker --execute={$escapedCode}";

        $result = $this->executeRemoteCommand($remote, $command, 30);

        if ($result->successful() && trim($result->output()) !== '') {
            $subdir = trim($result->output());
        } else {
            $subdir = $this->getSnapshotSubdirectory();
        }

        $this->remoteSnapshotSubdirs[$remote->name] = $subdir;

        return $subdir;
    }

    public function downloadSnapshot(RemoteConfig $remote, string $snapshotName, string $localPath): ProcessResult
    {
        $remotePath = $this->getRemoteSnapshotPath($remote, $snapshotName);
        $timeout = config('remote-sync.timeouts.snapshot_download', 600);

        $process = Process::timeout($timeout);

        if ($this->useTty) {
            $process = $process->tty();
        }

        return $process->run([
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
        $subdir = $this->getRemoteSnapshotSubdirectory($remote);
        $snapshotPath = "{$remote->storagePath()}/{$subdir}";
        $escapedSnapshotPath = escapeshellarg($snapshotPath);
        $command = "{ find {$escapedSnapshotPath} -maxdepth 1 -name '*.sql.gz' -exec stat -c '%Y %n' {} + 2>/dev/null || find {$escapedSnapshotPath} -maxdepth 1 -name '*.sql.gz' -exec stat -f '%m %N' {} + 2>/dev/null; } | sort -rn || true";
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
            ->map(fn (string $pattern) => '--exclude='.$pattern)
            ->all();

        $options = array_merge($defaultOptions, $excludeOptions, $options);

        $destination = "{$remote->host}:{$destinationPath}";

        $process = Process::timeout($timeout);

        if ($this->useTty) {
            $process = $process->tty();
        }

        return $process->run(array_merge(['rsync'], $options, [$sourcePath, $destination]));
    }

    public function uploadSnapshot(RemoteConfig $remote, string $snapshotName, string $localPath): ProcessResult
    {
        $subdir = $this->getRemoteSnapshotSubdirectory($remote);
        $remotePath = "{$remote->storagePath()}/{$subdir}/";
        $localFile = "{$localPath}/{$snapshotName}.sql.gz";
        $timeout = config('remote-sync.timeouts.snapshot_upload', 600);

        $process = Process::timeout($timeout);

        if ($this->useTty) {
            $process = $process->tty();
        }

        return $process->run([
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
        $command = "cd {$escapedPath} && php artisan snapshot:load {$escapedSnapshotName} --force --drop-tables=0";
        $timeout = config('remote-sync.timeouts.snapshot_load', config('remote-sync.timeouts.snapshot_create', 300));

        return $this->executeRemoteCommand($remote, $command, $timeout);
    }

    public function createRemoteBackup(RemoteConfig $remote, string $backupName): ProcessResult
    {
        $excludeTables = array_unique(array_merge(
            config('remote-sync.exclude_tables', []),
            self::ALWAYS_PRESERVED_TABLES
        ));
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
            ->map(fn (string $pattern) => '--exclude='.$pattern)
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
            ->map(fn (string $pattern) => '--exclude='.$pattern)
            ->all();

        $options = array_merge($defaultOptions, $excludeOptions, $options);

        $destination = "{$remote->host}:{$destinationPath}";

        return Process::timeout(120)
            ->run(array_merge(['rsync'], $options, [$sourcePath, $destination]));
    }

    /**
     * Get table names from a remote database.
     *
     * @return array<int, string>
     */
    public function getRemoteTableNames(RemoteConfig $remote): array
    {
        $escapedPath = escapeshellarg($remote->workingPath());
        $code = <<<'PHP'
$schemaBuilder = DB::connection()->getSchemaBuilder();
$tables = method_exists($schemaBuilder, 'getCurrentSchemaName')
    ? $schemaBuilder->getTableListing($schemaBuilder->getCurrentSchemaName(), schemaQualified: false)
    : $schemaBuilder->getTableListing();
echo json_encode(array_values($tables));
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
     * Get table names from the local database.
     *
     * @return array<int, string>
     */
    public function getLocalTableNames(): array
    {
        $schemaBuilder = DB::connection()->getSchemaBuilder();

        if (method_exists($schemaBuilder, 'getCurrentSchemaName')) {
            return $schemaBuilder->getTableListing(
                $schemaBuilder->getCurrentSchemaName(),
                schemaQualified: false
            );
        }

        return $schemaBuilder->getTableListing();
    }

    public function loadSnapshotViaCli(string $snapshotName, bool $dropTables): ProcessResult
    {
        $snapshotPath = $this->getSnapshotPath()."/{$snapshotName}.sql.gz";

        if (! file_exists($snapshotPath)) {
            throw new RuntimeException("Snapshot file not found: {$snapshotPath}");
        }

        $driver = $this->normalizeLocalDriver();

        if ($dropTables) {
            $schema = DB::connection()->getSchemaBuilder();
            $schema->dropAllTables();
        }

        $timeout = config('remote-sync.timeouts.snapshot_load', config('remote-sync.timeouts.snapshot_create', 300));

        return match ($driver) {
            'mysql' => $this->loadViaMysqlCli($snapshotPath, $timeout),
            'pgsql' => $this->loadViaPsqlCli($snapshotPath, $timeout),
            default => throw new RuntimeException("Unsupported database driver for CLI loading: {$driver}"),
        };
    }

    public function normalizeLocalDriver(): string
    {
        $driver = config('database.connections.'.config('database.default').'.driver');

        return match (strtolower($driver)) {
            'mariadb' => 'mysql',
            default => strtolower($driver),
        };
    }

    protected function loadViaMysqlCli(string $snapshotPath, int $timeout): ProcessResult
    {
        $connection = config('database.connections.'.config('database.default'));

        $host = $connection['host'] ?? '127.0.0.1';
        $port = $connection['port'] ?? 3306;
        $username = $connection['username'] ?? 'root';
        $password = $connection['password'] ?? '';
        $database = $connection['database'];
        $socket = $connection['unix_socket'] ?? '';

        $escapedPath = escapeshellarg($snapshotPath);

        $args = [];

        if ($socket) {
            $args[] = "--socket={$socket}";
        } else {
            $args[] = "--host={$host}";
            $args[] = "--port={$port}";
        }

        $args[] = "--user={$username}";
        $args[] = $database;

        $argString = implode(' ', array_map('escapeshellarg', $args));
        $command = "gunzip -c {$escapedPath} | mysql {$argString}";

        $env = [];

        if ($password !== '') {
            $env['MYSQL_PWD'] = $password;
        }

        return Process::timeout($timeout)->env($env)->run($command);
    }

    protected function loadViaPsqlCli(string $snapshotPath, int $timeout): ProcessResult
    {
        $connection = config('database.connections.'.config('database.default'));

        $host = $connection['host'] ?? '127.0.0.1';
        $port = $connection['port'] ?? 5432;
        $username = $connection['username'] ?? 'postgres';
        $password = $connection['password'] ?? '';
        $database = $connection['database'];

        $escapedPath = escapeshellarg($snapshotPath);

        $args = ["--host={$host}", "--port={$port}", "--username={$username}", $database];
        $argString = implode(' ', array_map('escapeshellarg', $args));
        $command = "gunzip -c {$escapedPath} | psql {$argString}";

        $env = [];

        if ($password !== '') {
            $env['PGPASSWORD'] = $password;
        }

        return Process::timeout($timeout)->env($env)->run($command);
    }

    /**
     * Get migration records from a remote database.
     *
     * @return array<int, string>
     */
    public function getRemoteMigrationRecords(RemoteConfig $remote): array
    {
        try {
            $escapedPath = escapeshellarg($remote->workingPath());
            $code = "echo json_encode(DB::table('migrations')->pluck('migration')->toArray());";

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
        } catch (\Exception) {
            return [];
        }
    }

    /**
     * Get migration records from the local database.
     *
     * @return array<int, string>
     */
    public function getLocalMigrationRecords(): array
    {
        try {
            return DB::table('migrations')->pluck('migration')->toArray();
        } catch (\Exception) {
            return [];
        }
    }
}
