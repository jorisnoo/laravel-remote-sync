<?php

namespace Noo\LaravelRemoteSync\Snapshots;

use Illuminate\Contracts\Process\ProcessResult;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Schema;
use RuntimeException;

class Importer
{
    /** @var list<string> Tables that are always synced and never truncated. */
    public const PROTECTED_TABLES = ['migrations'];

    /**
     * The one place mariadb is folded into mysql.
     */
    public static function normalizeDriver(string $driver): string
    {
        return match (strtolower($driver)) {
            'mariadb' => 'mysql',
            default => strtolower($driver),
        };
    }

    public static function localDriver(): string
    {
        return self::normalizeDriver(
            (string) config('database.connections.'.config('database.default').'.driver')
        );
    }

    /**
     * Tables configured for exclusion. Protected tables are always synced.
     *
     * @return list<string>
     */
    public static function excludedTables(): array
    {
        return array_values(array_diff(
            config('remote-sync.exclude_tables', []),
            self::PROTECTED_TABLES
        ));
    }

    /**
     * Decompress to a private file before touching the database. A pipe alone
     * cannot prevent psql from committing a valid SQL prefix on premature EOF.
     */
    public function import(string $snapshotName, bool $dropTables = false): ProcessResult
    {
        $snapshotPath = Snapshots::localPath($snapshotName);

        if (is_link($snapshotPath) || ! is_file($snapshotPath) || ! is_readable($snapshotPath)) {
            throw new RuntimeException("Snapshot file not found: {$snapshotPath}");
        }

        $driver = self::localDriver();

        if (! in_array($driver, ['mysql', 'pgsql'], true)) {
            throw new RuntimeException("Unsupported database driver for CLI loading: {$driver}");
        }

        $sql = tmpfile();

        if ($sql === false) {
            throw new RuntimeException('Could not create a private temporary file for the restore.');
        }

        try {
            $path = stream_get_meta_data($sql)['uri'];
            $timeout = (int) config('remote-sync.timeouts.transfer', 1800);
            $result = Process::timeout($timeout)->run(
                'exec gzip -dc < '.escapeshellarg($snapshotPath).' > '.escapeshellarg($path)
            );

            if ($result->failed()) {
                return $result;
            }

            if (filesize($path) === 0) {
                throw new RuntimeException('Snapshot contains no SQL.');
            }

            return $driver === 'mysql'
                ? $this->importViaMysql($sql, $timeout, $dropTables)
                : $this->importViaPsql($path, $timeout, $dropTables);
        } finally {
            fclose($sql);
        }
    }

    /** @param resource $sql */
    private function importViaMysql($sql, int $timeout, bool $dropTables): ProcessResult
    {
        $connection = DB::connection()->getConfig();
        $header = (string) fgets($sql, 128);
        rewind($sql);
        $maria = $connection['driver'] === 'mariadb' || preg_match('/^\/\*M?!999999\\\\-/', $header);
        $args = [$maria ? 'mariadb' : 'mysql', '--batch', '--skip-force'];

        if (! empty($connection['unix_socket'])) {
            $args[] = '--protocol=SOCKET';
            $args[] = '--socket='.$connection['unix_socket'];
        } else {
            $args[] = '--host='.($connection['host'] ?? '127.0.0.1');
            $args[] = '--port='.($connection['port'] ?? 3306);
        }

        $args[] = '--user='.($connection['username'] ?? 'root');
        $args[] = '--database='.$connection['database'];
        $password = $connection['password'] ?? '';
        $env = $password === '' ? [] : ['MYSQL_PWD' => $password];

        // Check the actual client's sandbox support before a full restore drops
        // any tables. Never remove the sandbox directive from the dump.
        if ($maria || $dropTables) {
            $probe = ($maria ? "/*M!999999\\- enable the sandbox mode */\n" : '')."SELECT 1;\n";
            $check = Process::timeout($timeout)->env($env)->input($probe)->run($args);

            if ($check->failed()) {
                throw new RuntimeException(($maria ? 'A sandbox-capable mariadb client is required. ' : '')
                    .'Database client preflight failed: '.trim($check->errorOutput()));
            }
        }

        if ($dropTables) {
            DB::connection()->getSchemaBuilder()->dropAllTables();
        }

        return Process::timeout($timeout)->env($env)->input($sql)->run($args);
    }

    private function importViaPsql(string $path, int $timeout, bool $dropTables): ProcessResult
    {
        $db = DB::connection();
        $connection = $db->getConfig();
        $args = [
            'psql', '--no-psqlrc', '--no-password', '--quiet', '--set=ON_ERROR_STOP=1', '--single-transaction',
            '--host='.($connection['host'] ?? '127.0.0.1'),
            '--port='.($connection['port'] ?? 5432),
            '--username='.($connection['username'] ?? 'postgres'),
            '--dbname='.$connection['database'],
        ];
        $password = $connection['password'] ?? '';
        $process = Process::timeout($timeout)->env($password === '' ? [] : ['PGPASSWORD' => $password]);

        if ($dropTables) {
            // Discover on Laravel's configured search path, quote on the server,
            // and run the drops through psql in the very same transaction.
            $tables = $db->selectFromWriteConnection(<<<'SQL'
                SELECT schemaname, tablename, format('%I.%I', schemaname, tablename) AS identifier
                FROM pg_catalog.pg_tables WHERE schemaname = ANY(current_schemas(false))
                SQL);
            $keep = $connection['dont_drop'] ?? ['spatial_ref_sys'];
            $drop = [];
            foreach ($tables as $table) {
                if (! in_array($table->tablename, $keep, true) && ! in_array($table->schemaname.'.'.$table->tablename, $keep, true)) {
                    $drop[] = $table->identifier;
                }
            }

            if ($drop !== []) {
                $process->input('DROP TABLE '.implode(', ', $drop).";\n");
                $args[] = '--file=-';
            }
        }

        $args[] = '--file='.$path;

        return $process->run($args);
    }

    /**
     * @return list<string>
     */
    public function localTables(): array
    {
        $schemaBuilder = DB::connection()->getSchemaBuilder();

        return $schemaBuilder->getTableListing($schemaBuilder->getCurrentSchemaName(), schemaQualified: false);
    }

    /**
     * Delete users not matching the configured allowlist. Runs in a
     * transaction and refuses to act when no users would survive.
     *
     * @return array{kept: int, deleted: int, skipped: bool}|null null when filtering is not configured
     */
    public function filterUsers(): ?array
    {
        $filterUsers = config('remote-sync.filter_users', false);

        if (! is_array($filterUsers) || $filterUsers === []) {
            return null;
        }

        $usersTable = config('auth.providers.users.table', 'users');

        if (! Schema::hasTable($usersTable)) {
            return null;
        }

        // Use an explicit escape character so a literal backslash has the
        // same meaning on MySQL and PostgreSQL, regardless of SQL string mode.
        $patterns = array_map(
            fn (string $entry) => strtr($entry, ['!' => '!!', '%' => '!%', '_' => '!_', '*' => '%']),
            $filterUsers
        );

        return DB::transaction(function () use ($usersTable, $patterns) {
            $query = DB::table($usersTable);
            foreach ($patterns as $pattern) {
                $query->whereRaw("lower(email) NOT LIKE lower(?) ESCAPE '!'", [$pattern]);
            }

            $toDelete = $query->count();
            $total = DB::table($usersTable)->count();
            $kept = $total - $toDelete;

            if ($kept === 0 && $toDelete > 0) {
                return ['kept' => 0, 'deleted' => 0, 'skipped' => true];
            }

            $query->delete();

            return ['kept' => $kept, 'deleted' => $toDelete, 'skipped' => false];
        });
    }
}
