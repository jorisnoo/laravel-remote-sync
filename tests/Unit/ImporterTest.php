<?php

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Schema;
use Noo\LaravelRemoteSync\Snapshots\Importer;
use Noo\LaravelRemoteSync\Snapshots\Snapshots;

function createSnapshotFile(string $name, string $sql = "SELECT 1;\n"): string
{
    $dir = Snapshots::localDir();

    if (! is_dir($dir)) {
        mkdir($dir, 0755, true);
    }

    $path = "{$dir}/{$name}.sql.gz";
    file_put_contents($path, gzencode($sql));

    return $path;
}

beforeEach(function () {
    $this->snapshotRoot = sys_get_temp_dir().'/import-test-'.bin2hex(random_bytes(8));
    mkdir($this->snapshotRoot, 0700);
    config()->set('db-snapshots.disk', 'snapshots');
    config()->set('filesystems.disks.snapshots', ['driver' => 'local', 'root' => $this->snapshotRoot]);
    config()->set('database.connections.mysql.unix_socket', $this->snapshotRoot.'/absent.sock');
});

afterEach(function () {
    File::deleteDirectory($this->snapshotRoot);
});

describe('Importer', function () {
    describe('driver handling', function () {
        it('normalizes mariadb to mysql', function () {
            expect(Importer::normalizeDriver('mariadb'))->toBe('mysql')
                ->and(Importer::normalizeDriver('MariaDB'))->toBe('mysql')
                ->and(Importer::normalizeDriver('MySQL'))->toBe('mysql')
                ->and(Importer::normalizeDriver('pgsql'))->toBe('pgsql');
        });

        it('reads the local driver from the default connection', function () {
            expect(Importer::localDriver())->toBe('sqlite');
        });
    });

    describe('excludedTables', function () {
        it('never excludes protected tables', function () {
            config()->set('remote-sync.exclude_tables', ['cache', 'migrations', 'jobs']);

            expect(Importer::excludedTables())->toBe(['cache', 'jobs']);
        });
    });

    describe('import', function () {
        it('throws when the snapshot file is missing', function () {
            expect(fn () => (new Importer)->import('nope'))
                ->toThrow(RuntimeException::class, 'Snapshot file not found');
        });

        it('throws for unsupported local drivers', function () {
            createSnapshotFile('snap');

            expect(fn () => (new Importer)->import('snap'))
                ->toThrow(RuntimeException::class, 'Unsupported database driver for CLI loading: sqlite');
        });

        it('loads through mysql with host and port', function () {
            Process::fake(['*mysql*' => Process::result()]);
            createSnapshotFile('snap');
            config()->set('database.default', 'mysql');
            config()->set('database.connections.mysql', [
                'driver' => 'mysql',
                'host' => 'db.invalid',
                'port' => 3307,
                'database' => 'acme',
                'username' => 'acme_user',
                'password' => 'secret',
            ]);

            (new Importer)->import('snap');

            Process::assertRan(fn ($process) => $process->command === [
                'mysql', '--batch', '--skip-force', '--host=db.invalid', '--port=3307', '--user=acme_user', '--database=acme',
            ] && ($process->environment['MYSQL_PWD'] ?? null) === 'secret');
        });

        it('prefers the unix socket when configured', function () {
            Process::fake(['*mysql*' => Process::result()]);
            createSnapshotFile('snap');
            config()->set('database.default', 'mysql');
            config()->set('database.connections.mysql', [
                'driver' => 'mysql',
                'unix_socket' => '/tmp/mysql.sock',
                'database' => 'acme',
                'username' => 'acme_user',
                'password' => '',
            ]);

            (new Importer)->import('snap');

            Process::assertRan(fn ($process) => in_array('--socket=/tmp/mysql.sock', $process->command, true)
                && ! in_array('--host=127.0.0.1', $process->command, true)
                && ! array_key_exists('MYSQL_PWD', $process->environment));
        });

        it('restores with psql in one transaction', function () {
            Process::fake(['*psql*' => Process::result()]);
            createSnapshotFile('snap');
            config()->set('database.default', 'pgsql');
            config()->set('database.connections.pgsql', [
                'driver' => 'pgsql',
                'host' => 'db.invalid',
                'port' => 5433,
                'database' => 'acme',
                'username' => 'acme_user',
                'password' => 'pg-secret',
            ]);

            (new Importer)->import('snap');

            Process::assertRan(fn ($process) => array_slice($process->command, 0, -1) === [
                'psql', '--no-psqlrc', '--no-password', '--quiet', '--set=ON_ERROR_STOP=1', '--single-transaction',
                '--host=db.invalid', '--port=5433', '--username=acme_user', '--dbname=acme',
            ] && str_starts_with(end($process->command), '--file=')
                && ($process->environment['PGPASSWORD'] ?? null) === 'pg-secret');
        });

        it('reports a PostgreSQL statement error as an import failure', function () {
            Process::fake(['*psql*' => Process::result(exitCode: 3, errorOutput: 'ERROR: relation "users" already exists')]);
            createSnapshotFile('snap');
            config()->set('database.default', 'pgsql');
            config()->set('database.connections.pgsql', [
                'driver' => 'pgsql',
                'database' => 'acme',
            ]);

            $result = (new Importer)->import('snap');

            expect($result->successful())->toBeFalse()
                ->and($result->exitCode())->toBe(3)
                ->and($result->errorOutput())->toContain('relation "users" already exists');
        });

        it('uses a sandbox-capable client and passes the original MariaDB header through', function (string $header) {
            $sql = $header."\nSELECT 123;\n";
            createSnapshotFile('snap', $sql);
            config()->set('database.default', 'mysql');
            $inputs = [];
            Process::fake(['*mariadb*' => function ($process) use (&$inputs) {
                $inputs[] = is_resource($process->input) ? stream_get_contents($process->input) : $process->input;

                return Process::result();
            }]);

            expect((new Importer)->import('snap')->successful())->toBeTrue()
                ->and($inputs)->toHaveCount(2)
                ->and($inputs[1])->toBe($sql);
        })->with(['/*M!999999\\- enable the sandbox mode */', '/*!999999\\- enable the sandbox mode */']);

        it('fails before destructive full MySQL restore when the MariaDB client cannot handle the header', function () {
            createSnapshotFile('snap', "/*M!999999\\- enable the sandbox mode */\nSELECT 1;");
            config()->set('database.default', 'mysql');
            Process::fake(['*mariadb*' => Process::result(exitCode: 1, errorOutput: 'unknown command')]);

            expect(fn () => (new Importer)->import('snap', true))
                ->toThrow(RuntimeException::class, 'A sandbox-capable mariadb client is required');
        });
    });

    describe('filterUsers', function () {
        beforeEach(function () {
            Schema::create('users', function ($table) {
                $table->increments('id');
                $table->string('email');
            });
            DB::table('users')->insert([
                ['email' => 'keep@acme.test'],
                ['email' => 'jane@acme.test'],
                ['email' => 'other@elsewhere.test'],
            ]);
        });

        it('returns null when filtering is not configured', function () {
            config()->set('remote-sync.filter_users', false);

            expect((new Importer)->filterUsers())->toBeNull()
                ->and(DB::table('users')->count())->toBe(3);
        });

        it('keeps exact matches and wildcard matches, deletes the rest', function () {
            config()->set('remote-sync.filter_users', ['*@acme.test']);

            $result = (new Importer)->filterUsers();

            expect($result)->toBe(['kept' => 2, 'deleted' => 1, 'skipped' => false])
                ->and(DB::table('users')->pluck('email')->all())->toBe(['keep@acme.test', 'jane@acme.test']);
        });

        it('combines exact emails and wildcards', function () {
            config()->set('remote-sync.filter_users', ['other@elsewhere.test', 'keep@acme.test']);

            $result = (new Importer)->filterUsers();

            expect($result['kept'])->toBe(2)
                ->and(DB::table('users')->pluck('email')->all())->toBe(['keep@acme.test', 'other@elsewhere.test']);
        });

        it('folds email case and only treats asterisks as wildcards', function () {
            DB::table('users')->insert([
                ['email' => 'A_%!\\name@acme.test'],
                ['email' => 'ax%!\\name@acme.test'],
                ['email' => 'a_xyz!\\name@acme.test'],
            ]);
            config()->set('remote-sync.filter_users', ['KEEP@ACME.TEST', 'a_%!\\n*@acme.test']);

            expect((new Importer)->filterUsers()['kept'])->toBe(2)
                ->and(DB::table('users')->orderBy('id')->pluck('email')->all())->toBe(['keep@acme.test', 'A_%!\\name@acme.test']);
        });

        it('refuses to delete every user when nothing matches', function () {
            config()->set('remote-sync.filter_users', ['nobody@nowhere.test']);

            $result = (new Importer)->filterUsers();

            expect($result)->toBe(['kept' => 0, 'deleted' => 0, 'skipped' => true])
                ->and(DB::table('users')->count())->toBe(3);
        });

        it('returns null when the users table does not exist', function () {
            Schema::drop('users');
            config()->set('remote-sync.filter_users', ['keep@acme.test']);

            expect((new Importer)->filterUsers())->toBeNull();
        });
    });
});
