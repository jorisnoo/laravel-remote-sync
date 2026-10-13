<?php

namespace Noo\LaravelRemoteSync\Database;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Process;
use RuntimeException;

class Loader
{
    /**
     * Load a gzipped dump into the default database by piping it into the
     * mysql/psql CLI, which keeps memory flat regardless of dump size.
     *
     * @param  bool  $fresh  Drop every table first, so the result matches the dump exactly.
     *
     * @throws RuntimeException
     */
    public function load(string $path, bool $fresh = false): void
    {
        if (! is_file($path)) {
            throw new RuntimeException("Dump not found: {$path}");
        }

        if (! Process::forever()->run(['gzip', '-t', $path])->successful()) {
            throw new RuntimeException("The dump failed its integrity check, nothing was loaded: {$path}");
        }

        $credentials = Credentials::current();
        [$client, $environment] = $this->client($credentials);

        if ($fresh) {
            DB::connection()->getSchemaBuilder()->dropAllTables();
        }

        $result = Process::forever()
            ->env($environment)
            ->run('gunzip -c '.escapeshellarg($path).' | '.implode(' ', array_map('escapeshellarg', $client)));

        if (! $result->successful()) {
            throw new RuntimeException(trim($result->errorOutput()) ?: "{$client[0]} exited with code {$result->exitCode()}.");
        }
    }

    /**
     * @return array{0: list<string>, 1: array<string, string>} the command and its environment
     */
    protected function client(Credentials $credentials): array
    {
        $password = $credentials->password !== '' ? $credentials->password : null;

        return match ($credentials->driver) {
            'mysql' => [
                ['mysql',
                    ...($credentials->socket !== ''
                        ? ["--socket={$credentials->socket}"]
                        : ["--host={$credentials->host}", '--port='.($credentials->port ?? 3306)]),
                    "--user={$credentials->username}",
                    $credentials->database,
                ],
                array_filter(['MYSQL_PWD' => $password]),
            ],
            'pgsql' => [
                ['psql', '--quiet', '-v', 'ON_ERROR_STOP=1',
                    '--host='.($credentials->socket !== '' ? $credentials->socket : $credentials->host),
                    '--port='.($credentials->port ?? 5432),
                    "--username={$credentials->username}",
                    $credentials->database,
                ],
                array_filter(['PGPASSWORD' => $password]),
            ],
            default => throw new RuntimeException("Cannot load into a {$credentials->driver} database; only mysql and pgsql are supported."),
        };
    }
}
