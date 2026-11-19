<?php

namespace Noo\LaravelRemoteSync\Snapshots;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Process;
use RuntimeException;

class SnapshotDump
{
    /**
     * @param  list<string>  $exclude  Tables to leave untouched on push.
     * @param  list<string>  $empty  Tables to transfer without rows on pull.
     */
    public function create(string $name, array $exclude = [], array $empty = []): void
    {
        $path = Snapshots::localPath($name);

        $connection = DB::connection();
        $config = $connection->getConfig();
        $database = $connection->getDatabaseName();
        $schema = $connection->getSchemaBuilder();
        $namespace = $schema->getCurrentSchemaName();
        $user = (string) ($config['username'] ?? '');
        $host = (string) ($config['host'] ?? 'localhost');
        $password = (string) ($config['password'] ?? '');

        if ($connection->getDriverName() === 'pgsql') {
            $args = ['pg_dump', '--no-password', '--clean', '--if-exists', '--no-owner', '--no-privileges',
                '--host='.$host, '--port='.($config['port'] ?? 5432), '--username='.$user];
            $pattern = static fn (string $schema, string $table): string => '"'.str_replace('"', '""', $schema).'"."'.str_replace('"', '""', $table).'"';
            foreach ($empty as $table) {
                $args[] = '--exclude-table-data='.$pattern($namespace, $table);
            }
            foreach ($exclude as $table) {
                $args[] = '--exclude-table='.$pattern($namespace, $table);
            }
            if ($exclude !== []) {
                // pg_dump -T excludes a table but not its owned serial/identity sequences.
                $sequences = $connection->select('SELECT ns.nspname, s.relname FROM pg_class s
                    JOIN pg_namespace ns ON ns.oid = s.relnamespace
                    JOIN pg_depend d ON d.objid = s.oid AND d.classid = \'pg_class\'::regclass AND d.deptype IN (\'a\', \'i\')
                    JOIN pg_class t ON t.oid = d.refobjid
                    WHERE s.relkind = \'S\' AND t.relnamespace = current_schema()::regnamespace AND t.relname IN ('
                    .implode(',', array_fill(0, count($exclude), '?')).')', $exclude);
                foreach ($sequences as $sequence) {
                    $args[] = '--exclude-table='.$pattern($sequence->nspname, $sequence->relname);
                }
            }
            $passes = [[...$args, '--dbname='.$database]];
            $env = ['PGPASSWORD' => $password];
        } elseif (in_array($connection->getDriverName(), ['mysql', 'mariadb'], true)) {
            $args = [$connection->getDriverName() === 'mariadb' ? 'mariadb-dump' : 'mysqldump',
                '--single-transaction', '--skip-lock-tables', '--quick', '--no-tablespaces', '--user='.$user,
                ...(! empty($config['unix_socket']) ? ['--protocol=SOCKET', '--socket='.$config['unix_socket']] : ['--host='.$host, '--port='.($config['port'] ?? 3306)])];
            // A partial database sync must not copy the server's GTID state.
            // Oracle mysqldump otherwise requests FLUSH TABLES with a global
            // read lock even in single-transaction mode. MariaDB has no such option.
            if ($args[0] === 'mysqldump') {
                $version = Process::timeout(10)->run(['mysqldump', '--version']);
                if (! $version->successful()) {
                    throw new RuntimeException('Cannot identify mysqldump: '.trim($version->errorOutput()));
                }
                if (! str_contains($version->output(), 'MariaDB')) {
                    $args[] = '--set-gtid-purged=OFF';
                }
            }
            $ignore = array_map(static fn (string $t): string => "--ignore-table={$database}.{$t}", [...$exclude, ...$empty]);
            // Oracle mysqldump has no --ignore-table-data. Each pass is a gzip member.
            $passes = [[...$args, ...$ignore, '--', $database]];
            if ($empty !== []) {
                $empty = array_values(array_intersect($empty, $schema->getTableListing($namespace, schemaQualified: false)));
            }
            if ($empty !== []) {
                $passes[] = [...$args, '--no-data', '--', $database, ...$empty];
            }
            $env = ['MYSQL_PWD' => $password];
        } else {
            throw new RuntimeException('Database dumps require MySQL, MariaDB or PostgreSQL.');
        }

        $dir = Snapshots::localDir();
        if (! is_dir($dir) && ! mkdir($dir, 0700, true) && ! is_dir($dir)) {
            throw new RuntimeException("Cannot create snapshot directory: {$dir}");
        }
        $temp = tempnam($dir, '.remote-sync-');
        if ($temp === false) {
            throw new RuntimeException("Cannot create a temporary snapshot in {$dir}.");
        }

        try {
            foreach ($passes as $args) {
                // pipefail checks the dumper AND gzip, without shell interpolation of credentials or names.
                $result = Process::timeout((int) config('remote-sync.timeouts.transfer', 1800))->env($env)
                    ->run(['bash', '-o', 'pipefail', '-c', '"$@" | gzip -c >> "$0"', $temp, ...$args]);
                if (! $result->successful()) {
                    throw new RuntimeException('Dump failed: '.trim($result->errorOutput()));
                }
            }
            // Never overwrite an existing backup or expose an incomplete one.
            if (! link($temp, $path)) {
                throw new RuntimeException("Could not publish snapshot {$name}.");
            }
        } finally {
            unlink($temp);
        }
    }
}
