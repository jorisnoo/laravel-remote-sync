<?php

namespace Noo\LaravelRemoteSync\Remotes;

use RuntimeException;

class Probe
{
    protected const CD_FAILED_MARKER = 'REMOTE_SYNC_CD_FAILED';

    protected const JSON_MARKER = 'REMOTE_SYNC_JSON=';

    /** @var list<string> */
    protected const PHP_CANDIDATES = [
        'php',
        'php8.5',
        'php85',
        'php8.4',
        'php84',
        'php8.3',
        'php83',
        'php8.2',
        'php82',
        'php8.1',
        'php81',
    ];

    public function run(Connection $connection): RemoteInfo
    {
        $remote = $connection->remote;
        $result = $connection->run($this->command($remote));

        if (str_contains($result->output(), self::CD_FAILED_MARKER)) {
            throw new RuntimeException(
                "Cannot access '{$remote->path}' on [{$remote->name}]: directory not found. Check the remote's path configuration."
            );
        }

        if (! $result->successful()) {
            $error = trim($result->errorOutput()) ?: trim($result->output());

            throw new RuntimeException("Cannot reach [{$remote->name}] ({$remote->host}): {$error}");
        }

        return $this->parse($result->output(), $remote);
    }

    public function command(Remote $remote): string
    {
        $candidates = $remote->phpBinary !== null
            ? [$remote->phpBinary]
            : self::PHP_CANDIDATES;

        $escapedCandidates = implode(' ', array_map('escapeshellarg', $candidates));
        $base = escapeshellarg($remote->path);
        $tinker = escapeshellarg($this->tinkerCode());
        $marker = self::CD_FAILED_MARKER;

        return "BASE={$base}; "
            .'if [ -d "$BASE/current" ]; then WORK="$BASE/current"; ATOMIC=1; else WORK="$BASE"; ATOMIC=0; fi; '
            ."cd \"\$WORK\" || { echo {$marker}; exit 1; }; "
            .'echo "ATOMIC=$ATOMIC"; '
            ."PHP=''; for candidate in {$escapedCandidates}; do "
            .'if command -v "$candidate" >/dev/null 2>&1 && "$candidate" -r \'if (file_exists("vendor/composer/platform_check.php")) { require "vendor/composer/platform_check.php"; }\' >/dev/null 2>&1; then PHP="$candidate"; break; fi; '
            .'done; '
            .'echo "PHP=$PHP"; '
            .'if command -v rsync >/dev/null 2>&1; then echo RSYNC=1; else echo RSYNC=0; fi; '
            .'if [ -d vendor/spatie/laravel-db-snapshots ]; then echo SNAPSHOTS=1; else echo SNAPSHOTS=0; fi; '
            .'if [ -n "$PHP" ]; then "$PHP" artisan tinker --execute='.$tinker.' 2>/dev/null; fi; '
            .'exit 0';
    }

    protected function tinkerCode(): string
    {
        return <<<'PHP'
$driver = config('database.connections.'.config('database.default').'.driver');
$schemaBuilder = DB::connection()->getSchemaBuilder();
$tables = method_exists($schemaBuilder, 'getCurrentSchemaName')
    ? $schemaBuilder->getTableListing($schemaBuilder->getCurrentSchemaName(), schemaQualified: false)
    : $schemaBuilder->getTableListing();
$disk = config('db-snapshots.disk', 'snapshots');
$root = config('filesystems.disks.'.$disk.'.root');
echo PHP_EOL.'REMOTE_SYNC_JSON='.json_encode(['driver' => $driver, 'tables' => array_values($tables), 'snapshot_dir' => $root]);
PHP;
    }

    protected function parse(string $output, Remote $remote): RemoteInfo
    {
        $atomic = false;
        $phpBinary = null;
        $hasRsync = false;
        $hasDbSnapshots = false;
        $driver = null;
        $tables = [];
        $snapshotDir = null;

        foreach (preg_split('/\r?\n/', $output) ?: [] as $line) {
            $line = trim($line);

            if (str_starts_with($line, 'ATOMIC=')) {
                $atomic = substr($line, strlen('ATOMIC=')) === '1';
            } elseif (str_starts_with($line, 'PHP=')) {
                $value = substr($line, strlen('PHP='));
                $phpBinary = $value !== '' ? $value : null;
            } elseif (str_starts_with($line, 'RSYNC=')) {
                $hasRsync = substr($line, strlen('RSYNC=')) === '1';
            } elseif (str_starts_with($line, 'SNAPSHOTS=')) {
                $hasDbSnapshots = substr($line, strlen('SNAPSHOTS=')) === '1';
            } elseif (str_starts_with($line, self::JSON_MARKER)) {
                $decoded = json_decode(substr($line, strlen(self::JSON_MARKER)), true);

                if (is_array($decoded)) {
                    $driver = is_string($decoded['driver'] ?? null) ? $decoded['driver'] : null;
                    $tables = array_values(array_filter($decoded['tables'] ?? [], 'is_string'));
                    $snapshotDir = is_string($decoded['snapshot_dir'] ?? null) ? $decoded['snapshot_dir'] : null;
                }
            }
        }

        $endsWithCurrent = str_ends_with($remote->path, '/current');
        $isAtomic = $atomic || $endsWithCurrent;
        $workingPath = ($atomic && ! $endsWithCurrent) ? "{$remote->path}/current" : $remote->path;

        return new RemoteInfo(
            phpBinary: $phpBinary,
            isAtomic: $isAtomic,
            workingPath: $workingPath,
            snapshotDir: $snapshotDir ?? "{$workingPath}/storage/snapshots",
            driver: $driver,
            tables: $tables,
            hasDbSnapshots: $hasDbSnapshots,
            hasRsync: $hasRsync,
        );
    }
}
