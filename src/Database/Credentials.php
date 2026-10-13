<?php

namespace Noo\LaravelRemoteSync\Database;

use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;

/**
 * Connection details of the default database, as the CLI tools need them.
 */
readonly class Credentials
{
    public function __construct(
        public string $driver,
        public string $host,
        public ?int $port,
        public string $socket,
        public string $database,
        public string $username,
        public string $password,
    ) {}

    /**
     * Read from the resolved connection rather than the raw config, so values
     * parsed from DB_URL and merged read/write settings are included.
     */
    public static function current(): self
    {
        $connection = DB::connection();
        $config = $connection->getConfig();

        return new self(
            driver: self::normalizeDriver($connection->getDriverName()),
            host: (string) Arr::first(Arr::wrap($config['host'] ?? '127.0.0.1')),
            port: isset($config['port']) ? (int) $config['port'] : null,
            socket: (string) ($config['unix_socket'] ?? ''),
            database: (string) ($config['database'] ?? ''),
            username: (string) ($config['username'] ?? ''),
            password: (string) ($config['password'] ?? ''),
        );
    }

    public static function normalizeDriver(string $driver): string
    {
        $driver = strtolower($driver);

        return $driver === 'mariadb' ? 'mysql' : $driver;
    }
}
