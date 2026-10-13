<?php

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Schema;
use Noo\LaravelRemoteSync\Database\Credentials;
use Noo\LaravelRemoteSync\Database\Dumps;
use Noo\LaravelRemoteSync\Database\Loader;

function dumpFile(): string
{
    Dumps::ensureDirectory();
    file_put_contents($path = Dumps::path('remote-sync-test'), 'fake');

    return $path;
}

function useConnection(array $config): void
{
    config()->set('database.default', $config['driver']);
    config()->set("database.connections.{$config['driver']}", $config);
    DB::purge($config['driver']);
}

afterEach(fn () => @unlink(Dumps::path('remote-sync-test')));

describe('Loader', function () {
    it('pipes the dump into mysql, passing the password in the environment', function () {
        Process::fake();
        $path = dumpFile();
        useConnection(['driver' => 'mysql', 'host' => 'db.internal', 'port' => 3307, 'database' => 'acme', 'username' => 'acme_user', 'password' => 'secret']);

        (new Loader)->load($path);

        Process::assertRan(fn ($process) => $process->command === ['gzip', '-t', $path]);
        Process::assertRan(fn ($process) => $process->command === "gunzip -c '{$path}' | 'mysql' '--host=db.internal' '--port=3307' '--user=acme_user' 'acme'"
            && $process->environment === ['MYSQL_PWD' => 'secret']);
    });

    it('prefers the unix socket for mysql', function () {
        Process::fake();
        $path = dumpFile();
        useConnection(['driver' => 'mysql', 'unix_socket' => '/tmp/mysql.sock', 'database' => 'acme', 'username' => 'acme_user', 'password' => '']);

        (new Loader)->load($path);

        Process::assertRan(fn ($process) => is_string($process->command)
            && str_contains($process->command, "'--socket=/tmp/mysql.sock'")
            && ! str_contains($process->command, '--host')
            && $process->environment === []);
    });

    it('pipes the dump into psql, stopping at the first error', function () {
        Process::fake();
        $path = dumpFile();
        useConnection(['driver' => 'pgsql', 'host' => 'db.internal', 'port' => 5433, 'database' => 'acme', 'username' => 'acme_user', 'password' => 'pg-secret']);

        (new Loader)->load($path);

        Process::assertRan(fn ($process) => $process->command === "gunzip -c '{$path}' | 'psql' '--quiet' '-v' 'ON_ERROR_STOP=1' '--host=db.internal' '--port=5433' '--username=acme_user' 'acme'"
            && $process->environment === ['PGPASSWORD' => 'pg-secret']);
    });

    it('reads connection details parsed from DB_URL', function () {
        Process::fake();
        $path = dumpFile();
        useConnection(['driver' => 'mysql', 'url' => 'mysql://acme_user:secret@db.internal:3307/acme']);

        (new Loader)->load($path);

        Process::assertRan(fn ($process) => is_string($process->command)
            && str_contains($process->command, "'--host=db.internal' '--port=3307' '--user=acme_user' 'acme'"));
    });

    it('refuses a dump that fails the integrity check, before touching the database', function () {
        Process::fake(['gzip *' => Process::result(exitCode: 1)]);
        Schema::create('keep_me', fn ($table) => $table->id());

        expect(fn () => (new Loader)->load(dumpFile(), fresh: true))
            ->toThrow(RuntimeException::class, 'failed its integrity check');

        expect(Schema::hasTable('keep_me'))->toBeTrue();
        Process::assertRanTimes(fn ($process) => is_string($process->command), 0);
    });

    it('reports the client error when loading fails', function () {
        Process::fake(fn ($process) => is_string($process->command)
            ? Process::result(exitCode: 1, errorOutput: 'ERROR 1064 at line 12')
            : Process::result());
        useConnection(['driver' => 'mysql', 'database' => 'acme', 'username' => 'root']);

        expect(fn () => (new Loader)->load(dumpFile()))->toThrow(RuntimeException::class, 'ERROR 1064 at line 12');
    });

    it('refuses a missing dump and unsupported drivers', function () {
        expect(fn () => (new Loader)->load('/nope.sql.gz'))->toThrow(RuntimeException::class, 'Dump not found');

        Process::fake();
        expect(fn () => (new Loader)->load(dumpFile()))->toThrow(RuntimeException::class, 'Cannot load into a sqlite database');
    });
});

describe('Credentials', function () {
    it('folds mariadb into mysql', function () {
        expect(Credentials::normalizeDriver('MariaDB'))->toBe('mysql')
            ->and(Credentials::normalizeDriver('pgsql'))->toBe('pgsql');
    });
});
