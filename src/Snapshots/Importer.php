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
     * Tables excluded from dumps and truncated after import. Protected
     * tables are filtered out even when a user lists them in config.
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
     * Import a downloaded snapshot by piping it straight into the database
     * CLI. This deliberately bypasses spatie's snapshot:load, which loads
     * the dump through PHP and exhausts memory on large databases.
     */
    public function import(string $snapshotName, bool $dropTables = false): ProcessResult
    {
        $snapshotPath = Snapshots::localPath($snapshotName);

        if (! file_exists($snapshotPath)) {
            throw new RuntimeException("Snapshot file not found: {$snapshotPath}");
        }

        $driver = self::localDriver();

        if (! in_array($driver, ['mysql', 'pgsql'], true)) {
            throw new RuntimeException("Unsupported database driver for CLI loading: {$driver}");
        }

        if ($dropTables) {
            DB::connection()->getSchemaBuilder()->dropAllTables();
        }

        $timeout = (int) config('remote-sync.timeouts.transfer', 1800);

        return $driver === 'mysql'
            ? $this->importViaMysql($snapshotPath, $timeout)
            : $this->importViaPsql($snapshotPath, $timeout);
    }

    protected function importViaMysql(string $snapshotPath, int $timeout): ProcessResult
    {
        $connection = config('database.connections.'.config('database.default'));

        $args = [];

        if (! empty($connection['unix_socket'])) {
            $args[] = '--socket='.$connection['unix_socket'];
        } else {
            $args[] = '--host='.($connection['host'] ?? '127.0.0.1');
            $args[] = '--port='.($connection['port'] ?? 3306);
        }

        $args[] = '--user='.($connection['username'] ?? 'root');
        $args[] = (string) ($connection['database'] ?? '');

        $command = 'gunzip -c '.escapeshellarg($snapshotPath).' | mysql '.implode(' ', array_map('escapeshellarg', $args));

        $env = [];
        $password = $connection['password'] ?? '';

        if ($password !== '') {
            $env['MYSQL_PWD'] = $password;
        }

        return Process::timeout($timeout)->env($env)->run($command);
    }

    protected function importViaPsql(string $snapshotPath, int $timeout): ProcessResult
    {
        $connection = config('database.connections.'.config('database.default'));

        $args = [
            '--host='.($connection['host'] ?? '127.0.0.1'),
            '--port='.($connection['port'] ?? 5432),
            '--username='.($connection['username'] ?? 'postgres'),
            (string) ($connection['database'] ?? ''),
        ];

        $command = 'gunzip -c '.escapeshellarg($snapshotPath).' | psql '.implode(' ', array_map('escapeshellarg', $args));

        $env = [];
        $password = $connection['password'] ?? '';

        if ($password !== '') {
            $env['PGPASSWORD'] = $password;
        }

        return Process::timeout($timeout)->env($env)->run($command);
    }

    /**
     * @return list<string>
     */
    public function localTables(): array
    {
        $schemaBuilder = DB::connection()->getSchemaBuilder();

        // getCurrentSchemaName() does not exist on Laravel 11.
        // @phpstan-ignore function.alreadyNarrowedType
        if (method_exists($schemaBuilder, 'getCurrentSchemaName')) {
            return $schemaBuilder->getTableListing(
                $schemaBuilder->getCurrentSchemaName(),
                schemaQualified: false
            );
        }

        return $schemaBuilder->getTableListing();
    }

    /**
     * Replace excluded tables with empty ones after a standard import.
     *
     * @return list<string> the tables that were truncated
     */
    public function truncateExcluded(): array
    {
        $excluded = self::excludedTables();

        if ($excluded === []) {
            return [];
        }

        $existing = $this->localTables();
        $tablesToTruncate = array_values(array_filter(
            $excluded,
            fn (string $table) => in_array($table, $existing, true)
        ));

        if ($tablesToTruncate === []) {
            return [];
        }

        $schema = DB::connection()->getSchemaBuilder();
        $schema->disableForeignKeyConstraints();

        try {
            foreach ($tablesToTruncate as $table) {
                DB::table($table)->truncate();
            }
        } finally {
            $schema->enableForeignKeyConstraints();
        }

        return $tablesToTruncate;
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

        $exactEmails = [];
        $wildcardPatterns = [];

        foreach ($filterUsers as $entry) {
            if (str_contains($entry, '*')) {
                $wildcardPatterns[] = str_replace('*', '%', $entry);
            } else {
                $exactEmails[] = $entry;
            }
        }

        return DB::transaction(function () use ($usersTable, $exactEmails, $wildcardPatterns) {
            $query = DB::table($usersTable)->where(function ($outer) use ($exactEmails, $wildcardPatterns) {
                if ($exactEmails !== []) {
                    $outer->whereNotIn('email', $exactEmails);
                }

                foreach ($wildcardPatterns as $pattern) {
                    $outer->where('email', 'not like', $pattern);
                }
            });

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
