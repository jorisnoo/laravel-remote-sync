<?php

namespace Noo\LaravelRemoteSync\Database;

use UnexpectedValueException;

/**
 * What one side of a sync knows about its own app. The local side builds it
 * in-process; the remote side prints it from `remote-sync:info`.
 */
readonly class AppInfo
{
    /**
     * @param  string  $driver  Normalized: mariadb is reported as mysql.
     * @param  list<string>  $tables
     * @param  list<string>  $migrations  Rows of the migrations table.
     * @param  list<array{name: string, mtime: int}>  $dumps  Newest first.
     * @param  list<string>  $missingBinaries  CLI tools this side needs but lacks.
     */
    public function __construct(
        public int $protocol,
        public string $environment,
        public string $driver,
        public array $tables,
        public array $migrations,
        public string $storagePath,
        public array $dumps = [],
        public array $missingBinaries = [],
    ) {}

    public function supportsDatabaseSync(): bool
    {
        return in_array($this->driver, ['mysql', 'pgsql'], true);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return get_object_vars($this);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        if (! is_int($data['protocol'] ?? null) || ! is_string($data['driver'] ?? null)) {
            throw new UnexpectedValueException('Malformed remote-sync:info output.');
        }

        return new self(
            protocol: $data['protocol'],
            environment: (string) ($data['environment'] ?? ''),
            driver: $data['driver'],
            tables: self::strings($data['tables'] ?? []),
            migrations: self::strings($data['migrations'] ?? []),
            storagePath: (string) ($data['storagePath'] ?? ''),
            dumps: array_values(array_filter(
                (array) ($data['dumps'] ?? []),
                fn ($dump) => is_string($dump['name'] ?? null) && is_int($dump['mtime'] ?? null),
            )),
            missingBinaries: self::strings($data['missingBinaries'] ?? []),
        );
    }

    /**
     * @return list<string>
     */
    protected static function strings(mixed $values): array
    {
        return array_values(array_filter((array) $values, 'is_string'));
    }
}
