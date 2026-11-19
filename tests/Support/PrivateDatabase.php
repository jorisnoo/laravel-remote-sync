<?php

namespace Noo\LaravelRemoteSync\Tests\Support;

use Closure;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use RuntimeException;
use Symfony\Component\Process\Process;

/** Native round-trip tests never take a DSN or attach to an existing server. */
final class PrivateDatabase
{
    public static function withServer(string $driver, Closure $test): void
    {
        // PostgreSQL and MySQL both impose a short limit on Unix socket paths.
        $dir = '/tmp/remote-sync-'.bin2hex(random_bytes(8));
        if (! mkdir($dir, 0700)) {
            throw new RuntimeException('Cannot create private test directory.');
        }
        $server = null;

        try {
            $data = $dir.'/data';
            if ($driver === 'pgsql') {
                self::run(['initdb', '-D', $data, '--username=fixture', '--auth=trust', '--no-locale', '--encoding=UTF8']);
                $server = new Process(['postgres', '-D', $data, '-k', $dir, '-p', '5432', '-c', 'listen_addresses=']);
                $admin = ['psql', '-X', '--no-password', '-v', 'ON_ERROR_STOP=1', '-h', $dir, '-p', '5432', '-U', 'fixture', '-d', 'postgres'];
                $probe = [...$admin, '-c', 'SELECT 1'];
                $connection = ['driver' => 'pgsql', 'host' => $dir, 'port' => 5432, 'search_path' => 'public'];
            } else {
                $base = ['mysqld', '--no-defaults', '--datadir='.$data, '--secure-file-priv=NULL'];
                self::run([...$base, '--initialize-insecure']);
                $socket = $dir.'/mysql.sock';
                $server = new Process([...$base, '--skip-networking', '--mysqlx=OFF', '--socket='.$socket, '--pid-file='.$dir.'/mysql.pid']);
                $admin = ['mysql', '--no-defaults', '--protocol=SOCKET', '--socket='.$socket, '--user=root', '--skip-password'];
                $probe = [...$admin, '-e', 'SELECT 1'];
                $connection = ['driver' => 'mysql', 'unix_socket' => $socket, 'host' => 'localhost', 'port' => 3306];
            }

            $server->setTimeout(null)->start();
            self::awaitReady($server, $probe);
            foreach (['source', 'destination'] as $name) {
                $user = $name.'_owner';
                if ($driver === 'pgsql') {
                    self::run([...$admin, '-c', "CREATE ROLE {$user} LOGIN", '-c', "CREATE DATABASE {$name} OWNER {$user}"]);
                } else {
                    self::run([...$admin, '-e', "CREATE DATABASE {$name}; CREATE USER '{$user}'@'localhost'; GRANT ALL ON {$name}.* TO '{$user}'@'localhost'; GRANT PROCESS ON *.* TO '{$user}'@'localhost'"]);
                }
                config()->set('database.connections.'.$name, [...$connection, 'database' => $name, 'username' => $user, 'password' => '', 'prefix' => '']);
            }
            config()->set('db-snapshots.disk', 'fixture');
            config()->set('filesystems.disks.fixture', ['driver' => 'local', 'root' => $dir.'/snapshots']);
            $test();
        } finally {
            DB::purge('source');
            DB::purge('destination');
            $server?->stop(10);
            File::deleteDirectory($dir);
        }
    }

    private static function run(array $args): void
    {
        (new Process($args))->setTimeout(120)->mustRun();
    }

    private static function awaitReady(Process $server, array $probe): void
    {
        $deadline = microtime(true) + 60;
        do {
            if (! $server->isRunning()) {
                break;
            }
            if ((new Process($probe))->run() === 0) {
                return;
            }
            usleep(100_000);
        } while (microtime(true) < $deadline);

        throw new RuntimeException('Private database did not start: '.$server->getErrorOutput());
    }
}
