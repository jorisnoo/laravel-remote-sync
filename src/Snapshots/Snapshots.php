<?php

namespace Noo\LaravelRemoteSync\Snapshots;

use Illuminate\Contracts\Process\ProcessResult;
use Illuminate\Support\Facades\Process;
use Noo\LaravelRemoteSync\Remotes\Connection;
use Noo\LaravelRemoteSync\Remotes\RemoteInfo;
use RuntimeException;

class Snapshots
{
    /** @var list<string> Name prefixes of snapshots created by this package. */
    public const NAME_PREFIXES = ['remote-sync-', 'pre-pull-', 'pre-push-'];

    public function __construct(
        protected Connection $connection,
        protected RemoteInfo $info,
    ) {}

    // Naming

    public static function transferName(): string
    {
        return 'remote-sync-'.date('Y-m-d-H-i-s').'-'.bin2hex(random_bytes(4));
    }

    public static function pullBackupName(): string
    {
        return 'pre-pull-'.date('Y-m-d-H-i-s');
    }

    public static function pushBackupName(): string
    {
        return 'pre-push-'.date('Y-m-d-H-i-s');
    }

    public static function isOwnSnapshot(string $name): bool
    {
        foreach (self::NAME_PREFIXES as $prefix) {
            if (str_starts_with($name, $prefix)) {
                return true;
            }
        }

        return false;
    }

    // Local storage

    public static function localDir(): string
    {
        return static::configuredLocalDir() ?? throw new RuntimeException('Configure the snapshots disk with the local driver and an absolute directory root.');
    }

    public static function configuredLocalDir(): ?string
    {
        $disk = config('db-snapshots.disk', 'snapshots');
        $root = config("filesystems.disks.{$disk}.root");

        return config("filesystems.disks.{$disk}.driver") === 'local' && is_string($root) && str_starts_with($root, '/') && trim($root, '/') !== ''
            ? rtrim($root, '/')
            : null;
    }

    public static function localPath(string $name): string
    {
        if (! preg_match('/\A[A-Za-z0-9][A-Za-z0-9_.-]*\z/', $name)) {
            throw new RuntimeException('Invalid snapshot name.');
        }

        return static::localDir()."/{$name}.sql.gz";
    }

    /**
     * List local snapshot files, newest first.
     *
     * @return array<int, array{name: string, path: string, mtime: int}>
     */
    public static function listLocal(): array
    {
        $files = glob(static::localDir().'/*.sql.gz') ?: [];
        $snapshots = [];

        foreach ($files as $file) {
            $snapshots[] = [
                'name' => basename($file, '.sql.gz'),
                'path' => $file,
                'mtime' => (int) filemtime($file),
            ];
        }

        usort($snapshots, fn (array $a, array $b) => $b['mtime'] <=> $a['mtime']);

        return $snapshots;
    }

    public static function deleteLocal(string $name): bool
    {
        $path = static::localPath($name);

        if (file_exists($path)) {
            return unlink($path);
        }

        return false;
    }

    /**
     * Create a compressed snapshot, using the same dump protocol at either endpoint.
     *
     * @param  list<string>  $excludeTables
     */
    public function createLocal(string $name, array $excludeTables = []): void
    {
        app(SnapshotDump::class)->create($name, $excludeTables);
    }

    public static function verifyGzip(string $name): bool
    {
        return Process::timeout((int) config('remote-sync.timeouts.transfer', 1800))
            ->run(['gzip', '-t', static::localPath($name)])
            ->successful();
    }

    // Remote storage

    public function remotePath(string $name): string
    {
        return "{$this->info->snapshotDir}/{$name}.sql.gz";
    }

    /**
     * @param  list<string>  $empty  Tables whose structure is needed but whose data must not be copied.
     */
    public function createRemote(string $name, array $empty = []): ProcessResult
    {
        $arguments = implode(' ', ['remote-sync:dump', escapeshellarg($name),
            ...array_map(static fn (string $table): string => '--empty='.escapeshellarg($table), $empty)]);

        return $this->connection->artisan($this->info, $arguments, $this->connection->transferTimeout());
    }

    public function loadRemote(string $name): ProcessResult
    {
        return $this->connection->artisan(
            $this->info,
            'remote-sync:load '.escapeshellarg($name).' --force',
            $this->connection->transferTimeout()
        );
    }

    public function deleteRemote(string $name): ProcessResult
    {
        return $this->connection->artisan(
            $this->info,
            'snapshot:delete '.escapeshellarg($name).' --no-interaction'
        );
    }

    public function download(string $name): ProcessResult
    {
        $dir = static::localDir();

        if (! is_dir($dir)) {
            mkdir($dir, 0700, true);
        }

        return Process::timeout($this->connection->transferTimeout())->run([
            'rsync',
            '-e', Connection::shell(),
            '-az',
            '--partial',
            "{$this->connection->remote->host}:{$this->remotePath($name)}",
            "{$dir}/",
        ]);
    }

    public function upload(string $name): ProcessResult
    {
        $directory = $this->connection->run('mkdir -p '.escapeshellarg($this->info->snapshotDir));
        if (! $directory->successful()) {
            return $directory;
        }

        return Process::timeout($this->connection->transferTimeout())->run([
            'rsync',
            '-e', Connection::shell(),
            '-az',
            '--partial',
            static::localPath($name),
            "{$this->connection->remote->host}:{$this->info->snapshotDir}/",
        ]);
    }

    /**
     * List snapshot files in the remote snapshot directory, newest first.
     *
     * @return array<int, array{name: string, path: string, mtime: int}>
     */
    public function listRemote(): array
    {
        $dir = escapeshellarg($this->info->snapshotDir);
        $command = "if [ ! -d {$dir} ]; then exit 0; fi; "
            ."find {$dir} -maxdepth 1 -type f -name '*.sql.gz' -exec stat -c '%Y %n' {} + 2>/dev/null"
            ." || find {$dir} -maxdepth 1 -type f -name '*.sql.gz' -exec stat -f '%m %N' {} +";

        $result = $this->connection->run($command);

        if (! $result->successful()) {
            throw new RuntimeException('Could not list remote snapshots: '.trim($result->errorOutput()));
        }

        $snapshots = [];

        foreach (array_filter(explode("\n", trim($result->output()))) as $line) {
            $parts = preg_split('/\s+/', trim($line), 2);

            if (count($parts) !== 2) {
                continue;
            }

            [$mtime, $path] = $parts;
            $snapshots[] = [
                'name' => basename($path, '.sql.gz'),
                'path' => $path,
                'mtime' => (int) $mtime,
            ];
        }
        usort($snapshots, static fn (array $a, array $b): int => $b['mtime'] <=> $a['mtime']);

        return $snapshots;
    }
}
