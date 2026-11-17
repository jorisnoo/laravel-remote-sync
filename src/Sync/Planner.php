<?php

namespace Noo\LaravelRemoteSync\Sync;

use Noo\LaravelRemoteSync\Database\Dumps;
use Noo\LaravelRemoteSync\Database\Inspector;
use Noo\LaravelRemoteSync\Database\UserFilter;
use RuntimeException;

class Planner
{
    public function __construct(
        protected Endpoint $source,
        protected Endpoint $target,
        protected Rsync $rsync,
    ) {}

    /**
     * Pull makes the local database equal to the remote's: excluded tables
     * arrive as structure only, local-only tables are dropped, and migrate
     * brings it up to the local code afterwards.
     *
     * Push replaces the remote's copy of every non-excluded table and leaves
     * its excluded tables (sessions, tokens, jobs) untouched.
     *
     * @throws RuntimeException when a push would erase remote migrations
     */
    public function database(Direction $direction, bool $full = false, bool $backup = true, bool $keepTransfer = false): DatabasePlan
    {
        $backupName = $backup ? Dumps::backupName($direction->value) : null;

        return $direction === Direction::Pull
            ? $this->pullDatabase($full, $backupName, $keepTransfer)
            : $this->pushDatabase($backupName, $keepTransfer);
    }

    protected function pullDatabase(bool $full, ?string $backup, bool $keepTransfer): DatabasePlan
    {
        $source = $this->source->info();
        $empty = $full ? [] : $this->excludedTables($source->tables);
        $warnings = [];

        if ($backup === null) {
            $warnings[] = 'Without a backup, the current local database cannot be restored.';
        }

        $patterns = UserFilter::patterns();

        if ($patterns !== null) {
            $warnings[] = 'Only users matching '.implode(', ', $patterns).' are kept.';
        }

        return new DatabasePlan(
            transfer: Dumps::transferName(),
            backup: $backup,
            fresh: true,
            keepTransfer: $keepTransfer,
            dumpOptions: array_map(fn (string $table) => "--exclude-data={$table}", $empty),
            replace: array_values(array_diff($source->tables, $empty)),
            empty: $empty,
            drop: array_values(array_diff($this->target->info()->tables, $source->tables)),
            filterUsers: $patterns !== null,
            warnings: $warnings,
        );
    }

    protected function pushDatabase(?string $backup, bool $keepTransfer): DatabasePlan
    {
        $source = $this->source->info();
        $target = $this->target->info();
        $excluded = $this->excludedTables($source->tables);
        $warnings = [];

        // The pushed migrations table replaces the remote's: migrations only
        // the remote has would be forgotten and run again.
        if (($ahead = array_values(array_diff($target->migrations, $source->migrations))) !== []) {
            throw new RuntimeException(
                "{$this->target->label()} has migrations this app does not: ".self::summarize($ahead).'. Update your code and pull first.'
            );
        }

        if (($unrun = array_values(array_diff($source->migrations, $target->migrations))) !== []) {
            $warnings[] = "Migrations not yet run on {$this->target->label()} will be recorded as run: ".self::summarize($unrun).'.';
        }

        if ($target->environment === 'production') {
            $warnings[] = "{$this->target->label()} is a production environment.";
        }

        return new DatabasePlan(
            transfer: Dumps::transferName(),
            backup: $backup,
            fresh: false,
            keepTransfer: $keepTransfer,
            dumpOptions: array_map(fn (string $table) => "--exclude={$table}", $excluded),
            replace: array_values(array_diff($source->tables, $excluded)),
            preserve: $this->excludedTables($target->tables),
            warnings: $warnings,
        );
    }

    /**
     * @param  list<string>  $paths  Storage-relative paths.
     *
     * @throws RuntimeException when a dry run fails, e.g. the source path is missing
     */
    public function files(array $paths, bool $delete = false): FilesPlan
    {
        $changes = [];

        foreach ($paths as $path) {
            try {
                $changes[$path] = $this->rsync->preview(
                    $this->source->rsyncPath($this->source->storagePath($path).'/'),
                    $this->target->rsyncPath($this->target->storagePath($path).'/'),
                );
            } catch (RuntimeException $e) {
                throw new RuntimeException("Could not compare storage/{$path}: {$e->getMessage()}");
            }
        }

        return new FilesPlan($changes, $delete);
    }

    /**
     * The tables in $tables that are configured as excluded. The migrations
     * table is never excluded.
     *
     * @param  list<string>  $tables
     * @return list<string>
     */
    protected function excludedTables(array $tables): array
    {
        $excluded = array_diff(config('remote-sync.exclude_tables', []), [Inspector::migrationsTable()]);

        return array_values(array_intersect($tables, $excluded));
    }

    /**
     * @param  list<string>  $items
     */
    public static function summarize(array $items, int $limit = 3): string
    {
        $shown = implode(', ', array_slice($items, 0, $limit));

        return count($items) > $limit ? $shown.' (+'.(count($items) - $limit).' more)' : $shown;
    }
}
