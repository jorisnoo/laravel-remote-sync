<?php

namespace Noo\LaravelRemoteSync\Database;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Symfony\Component\Process\ExecutableFinder;

class Inspector
{
    /**
     * Raised whenever info, dump or load change incompatibly. Both sides of
     * a sync must report the same value.
     */
    public const PROTOCOL = 1;

    public function inspect(): AppInfo
    {
        $driver = Credentials::normalizeDriver(DB::connection()->getDriverName());

        return new AppInfo(
            protocol: self::PROTOCOL,
            environment: (string) app()->environment(),
            driver: $driver,
            tables: $this->tables(),
            migrations: $this->migrations(),
            storagePath: storage_path(),
            dumps: Dumps::list(),
            missingBinaries: $this->missingBinaries($driver),
        );
    }

    public static function migrationsTable(): string
    {
        $config = config('database.migrations');

        return is_array($config) ? ($config['table'] ?? 'migrations') : ($config ?: 'migrations');
    }

    /**
     * @return list<string>
     */
    protected function tables(): array
    {
        $schema = DB::connection()->getSchemaBuilder();

        // getCurrentSchemaName() does not exist on Laravel 11.
        // @phpstan-ignore function.alreadyNarrowedType
        $tables = method_exists($schema, 'getCurrentSchemaName')
            ? $schema->getTableListing($schema->getCurrentSchemaName(), schemaQualified: false)
            : $schema->getTableListing();

        return array_values($tables);
    }

    /**
     * @return list<string>
     */
    protected function migrations(): array
    {
        $table = self::migrationsTable();

        if (! Schema::hasTable($table)) {
            return [];
        }

        return DB::table($table)->orderBy('id')->pluck('migration')->all();
    }

    /**
     * @return list<string>
     */
    protected function missingBinaries(string $driver): array
    {
        $binaries = match ($driver) {
            'mysql' => ['mysqldump', 'mysql', 'gzip'],
            'pgsql' => ['pg_dump', 'psql', 'gzip'],
            default => [],
        };

        $finder = new ExecutableFinder;

        return array_values(array_filter($binaries, fn (string $binary) => $finder->find($binary) === null));
    }
}
