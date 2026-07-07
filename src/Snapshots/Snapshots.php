<?php

namespace Noo\LaravelRemoteSync\Snapshots;

use Illuminate\Contracts\Process\ProcessResult;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Process;
use Noo\LaravelRemoteSync\Remotes\Connection;
use Noo\LaravelRemoteSync\Remotes\RemoteInfo;

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
        $disk = config('db-snapshots.disk', 'snapshots');
        $root = config("filesystems.disks.{$disk}.root");

        return is_string($root) && $root !== '' ? $root : storage_path('snapshots');
    }

    public static function localPath(string $name): string
    {
        return static::localDir()."/{$name}.sql.gz";
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
     * Create a compressed snapshot of the local database via spatie/laravel-db-snapshots.
     *
     * @param  list<string>  $excludeTables
     */
    public static function createLocal(string $name, array $excludeTables = []): int
    {
        $arguments = ['name' => $name, '--compress' => true];

        if ($excludeTables !== []) {
            $arguments['--exclude'] = $excludeTables;
        }

        return Artisan::call('snapshot:create', $arguments);
    }

    public static function verifyGzip(string $name): bool
    {
        return Process::timeout(300)
            ->run(['gzip', '-t', static::localPath($name)])
            ->successful();
    }

    // Remote storage

    public function remotePath(string $name): string
    {
        return "{$this->info->snapshotDir}/{$name}.sql.gz";
    }

    /**
     * @param  list<string>  $excludeTables
     */
    public function createRemote(string $name, array $excludeTables = []): ProcessResult
    {
        $arguments = collect(['snapshot:create', escapeshellarg($name)])
            ->merge(collect($excludeTables)->map(fn (string $table) => '--exclude='.escapeshellarg($table)))
            ->push('--compress')
            ->implode(' ');

        return $this->connection->artisan($this->info, $arguments, $this->connection->transferTimeout());
    }

    public function loadRemote(string $name): ProcessResult
    {
        return $this->connection->artisan(
            $this->info,
            'snapshot:load '.escapeshellarg($name).' --force --drop-tables=0',
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
            mkdir($dir, 0755, true);
        }

        return Process::timeout($this->connection->transferTimeout())->run([
            'rsync',
            '-az',
            '--partial',
            "{$this->connection->remote->host}:{$this->remotePath($name)}",
            "{$dir}/",
        ]);
    }

    public function upload(string $name): ProcessResult
    {
        return Process::timeout($this->connection->transferTimeout())->run([
            'rsync',
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
        $command = "{ find {$dir} -maxdepth 1 -name '*.sql.gz' -exec stat -c '%Y %n' {} + 2>/dev/null"
            ." || find {$dir} -maxdepth 1 -name '*.sql.gz' -exec stat -f '%m %N' {} + 2>/dev/null; } | sort -rn || true";

        $result = $this->connection->run($command);

        if (! $result->successful()) {
            return [];
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

        return $snapshots;
    }
}
