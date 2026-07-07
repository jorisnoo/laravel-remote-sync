<?php

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Schema;
use Noo\LaravelRemoteSync\Snapshots\Importer;
use Noo\LaravelRemoteSync\Snapshots\Snapshots;

function createSnapshotFile(string $name): string
{
    $dir = Snapshots::localDir();

    if (! is_dir($dir)) {
        mkdir($dir, 0755, true);
    }

    $path = "{$dir}/{$name}.sql.gz";
    file_put_contents($path, 'fake');

    return $path;
}

afterEach(function () {
    foreach (glob(Snapshots::localDir().'/*.sql.gz') ?: [] as $file) {
        unlink($file);
    }
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

        it('pipes through mysql with host and port', function () {
            Process::fake();
            createSnapshotFile('snap');
            config()->set('database.default', 'mysql');
            config()->set('database.connections.mysql', [
                'driver' => 'mysql',
                'host' => 'db.internal',
                'port' => 3307,
                'database' => 'acme',
                'username' => 'acme_user',
                'password' => 'secret',
            ]);

            (new Importer)->import('snap');

            $path = Snapshots::localPath('snap');

            Process::assertRan(fn ($process) => $process->command === "gunzip -c '{$path}' | mysql '--host=db.internal' '--port=3307' '--user=acme_user' 'acme'"
                && ($process->environment['MYSQL_PWD'] ?? null) === 'secret');
        });

        it('prefers the unix socket when configured', function () {
            Process::fake();
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

            Process::assertRan(fn ($process) => str_contains($process->command, "'--socket=/tmp/mysql.sock'")
                && ! str_contains($process->command, '--host=')
                && ! array_key_exists('MYSQL_PWD', $process->environment));
        });

        it('pipes through psql with PGPASSWORD', function () {
            Process::fake();
            createSnapshotFile('snap');
            config()->set('database.default', 'pgsql');
            config()->set('database.connections.pgsql', [
                'driver' => 'pgsql',
                'host' => 'db.internal',
                'port' => 5433,
                'database' => 'acme',
                'username' => 'acme_user',
                'password' => 'pg-secret',
            ]);

            (new Importer)->import('snap');

            $path = Snapshots::localPath('snap');

            Process::assertRan(fn ($process) => $process->command === "gunzip -c '{$path}' | psql '--host=db.internal' '--port=5433' '--username=acme_user' 'acme'"
                && ($process->environment['PGPASSWORD'] ?? null) === 'pg-secret');
        });
    });

    describe('truncateExcluded', function () {
        it('truncates only excluded tables that exist, never protected ones', function () {
            Schema::create('cache', function ($table) {
                $table->increments('id');
                $table->string('key');
            });
            Schema::create('migrations_shadow', function ($table) {
                $table->string('name');
            });
            DB::table('cache')->insert(['key' => 'a']);
            DB::table('migrations_shadow')->insert(['name' => 'keep-me']);

            config()->set('remote-sync.exclude_tables', ['cache', 'migrations', 'not_a_table']);

            $truncated = (new Importer)->truncateExcluded();

            expect($truncated)->toBe(['cache'])
                ->and(DB::table('cache')->count())->toBe(0)
                ->and(DB::table('migrations_shadow')->count())->toBe(1);
        });

        it('does nothing when no tables are excluded', function () {
            config()->set('remote-sync.exclude_tables', []);

            expect((new Importer)->truncateExcluded())->toBe([]);
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
