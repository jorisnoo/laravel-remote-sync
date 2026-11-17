<?php

namespace Noo\LaravelRemoteSync\Database;

use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use RuntimeException;
use Spatie\DbDumper\Compressors\GzipCompressor;
use Spatie\DbDumper\Databases\MySql;
use Spatie\DbDumper\Databases\PostgreSql;
use Spatie\DbDumper\DbDumper;

class Dumper
{
    /**
     * Write a gzipped dump of the default database to $path.
     *
     * @param  list<string>  $exclude  Tables left out completely.
     * @param  list<string>  $excludeData  Tables dumped as structure only.
     */
    public function dump(string $path, array $exclude = [], array $excludeData = []): void
    {
        $part = "{$path}.part";

        try {
            foreach ($this->passes(Credentials::current(), $exclude, $excludeData) as $index => $dumper) {
                $dumper->dumpToFile($index === 0 ? $path : $part);

                if ($index > 0) {
                    $this->append($part, $path);
                }
            }
        } finally {
            if (is_file($part)) {
                unlink($part);
            }
        }
    }

    /**
     * The dump commands to run, in order. Each writes a gzip member; the
     * members are concatenated into one file, which gunzip reads as one.
     *
     * @param  list<string>  $exclude
     * @param  list<string>  $excludeData
     * @return list<DbDumper>
     */
    public function passes(Credentials $credentials, array $exclude, array $excludeData): array
    {
        foreach ([...$exclude, ...$excludeData] as $table) {
            if (! preg_match('/^[A-Za-z0-9_$-]+$/', $table)) {
                throw new InvalidArgumentException("Invalid table name '{$table}'.");
            }
        }

        if ($credentials->driver === 'pgsql') {
            // --clean makes the dump replace the tables it contains, so it can
            // be loaded without dropping anything else first.
            $dumper = $this->dumper($credentials)
                ->addExtraOption('--clean')
                ->addExtraOption('--if-exists')
                ->addExtraOption('--no-owner')
                ->addExtraOption('--no-privileges')
                ->excludeTables([...$exclude, ...$this->ownedSequences($exclude)]);

            foreach ($excludeData as $table) {
                $dumper->addExtraOption(escapeshellarg("--exclude-table-data={$table}"));
            }

            return [$dumper];
        }

        // Oracle's mysqldump has no --ignore-table-data, so structure-only
        // tables get a second pass. The load runs with foreign key checks
        // off, so the order of the two passes does not matter.
        $passes = [$this->dumper($credentials)->excludeTables([...$exclude, ...$excludeData])];

        if ($excludeData !== []) {
            $passes[] = $this->dumper($credentials)->includeTables($excludeData)->doNotDumpData();
        }

        return $passes;
    }

    protected function dumper(Credentials $credentials): DbDumper
    {
        $dumper = match ($credentials->driver) {
            'mysql' => MySql::create()->useSingleTransaction(),
            'pgsql' => PostgreSql::create(),
            default => throw new RuntimeException("Cannot dump a {$credentials->driver} database; only mysql and pgsql are supported."),
        };

        $dumper->setDbName($credentials->database)
            ->setUserName($credentials->username)
            ->setPassword($credentials->password)
            ->setHost($credentials->host)
            ->useCompressor(new GzipCompressor);

        if ($credentials->port !== null) {
            $dumper->setPort($credentials->port);
        }

        if ($credentials->socket !== '') {
            $dumper->setSocket($credentials->socket);
        }

        return $dumper;
    }

    /**
     * pg_dump -T leaves out a table but not the sequence it owns. With
     * --clean, the dump would then drop that sequence on the target,
     * where the excluded table still depends on it.
     *
     * @param  list<string>  $tables
     * @return list<string>
     */
    protected function ownedSequences(array $tables): array
    {
        if ($tables === []) {
            return [];
        }

        $sequences = DB::select(
            "select s.relname from pg_class s
                join pg_depend d on d.objid = s.oid and d.classid = 'pg_class'::regclass and d.deptype in ('a', 'i')
                join pg_class t on t.oid = d.refobjid
                where s.relkind = 'S' and t.relnamespace = current_schema()::regnamespace
                and t.relname in (".implode(', ', array_fill(0, count($tables), '?')).')',
            $tables,
        );

        return array_map(fn (object $row) => $row->relname, $sequences);
    }

    protected function append(string $from, string $to): void
    {
        $source = fopen($from, 'rb');
        $target = fopen($to, 'ab');

        if ($source === false || $target === false || stream_copy_to_stream($source, $target) === false) {
            throw new RuntimeException("Could not append {$from} to {$to}.");
        }

        fclose($source);
        fclose($target);
    }
}
