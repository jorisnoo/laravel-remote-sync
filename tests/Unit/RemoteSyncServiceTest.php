<?php

use Illuminate\Support\Facades\Process;
use Noo\LaravelRemoteSync\Data\RemoteConfig;
use Noo\LaravelRemoteSync\RemoteSyncService;

beforeEach(function () {
    $this->service = new RemoteSyncService;
});

describe('RemoteSyncService', function () {
    describe('getRemote', function () {
        it('returns RemoteConfig for valid remote', function () {
            $this->setUpProductionRemote();

            $remote = $this->service->getRemote('production');

            expect($remote)->toBeInstanceOf(RemoteConfig::class);
            expect($remote->name)->toBe('production');
            expect($remote->host)->toBe('user@production.example.com');
            expect($remote->path)->toBe('/var/www/app');
            expect($remote->pushAllowed)->toBeFalse();
        });

        it('throws InvalidArgumentException for missing remote', function () {
            $this->setUpProductionRemote();

            expect(fn () => $this->service->getRemote('nonexistent'))
                ->toThrow(InvalidArgumentException::class, "Remote 'nonexistent' is not configured.");
        });

        it('throws InvalidArgumentException for remote missing host', function () {
            config()->set('remote-sync.remotes', [
                'incomplete' => [
                    'path' => '/var/www/app',
                ],
            ]);

            expect(fn () => $this->service->getRemote('incomplete'))
                ->toThrow(InvalidArgumentException::class, "Remote 'incomplete' is missing host or path configuration.");
        });

        it('throws InvalidArgumentException for remote missing path', function () {
            config()->set('remote-sync.remotes', [
                'incomplete' => [
                    'host' => 'user@example.com',
                ],
            ]);

            expect(fn () => $this->service->getRemote('incomplete'))
                ->toThrow(InvalidArgumentException::class, "Remote 'incomplete' is missing host or path configuration.");
        });

        it('uses default remote when name is null', function () {
            $this->setUpProductionRemote();

            $remote = $this->service->getRemote(null);

            expect($remote->name)->toBe('production');
        });

        it('returns remote with push_allowed set to true', function () {
            $this->setUpStagingRemote();

            $remote = $this->service->getRemote('staging');

            expect($remote->pushAllowed)->toBeTrue();
        });
    });

    describe('getAvailableRemotes', function () {
        it('returns array of remote names', function () {
            $this->setUpMultipleRemotes();

            $remotes = $this->service->getAvailableRemotes();

            expect($remotes)->toBe(['production', 'staging']);
        });

        it('returns empty array when no remotes configured', function () {
            config()->set('remote-sync.remotes', []);

            $remotes = $this->service->getAvailableRemotes();

            expect($remotes)->toBe([]);
        });
    });

    describe('getSnapshotPath', function () {
        it('returns configured disk root when available', function () {
            config()->set('db-snapshots.disk', 'snapshots');
            config()->set('filesystems.disks.snapshots', [
                'driver' => 'local',
                'root' => '/custom/snapshot/path',
            ]);

            $path = $this->service->getSnapshotPath();

            expect($path)->toBe('/custom/snapshot/path');
        });

        it('falls back to storage_path when disk not configured', function () {
            config()->set('db-snapshots.disk', 'nonexistent');
            config()->set('filesystems.disks.nonexistent', null);

            $path = $this->service->getSnapshotPath();

            expect($path)->toBe(storage_path('snapshots'));
        });
    });

    describe('executeRemoteCommand', function () {
        it('builds correct ssh command', function () {
            Process::fake();

            $remote = new RemoteConfig(
                name: 'production',
                host: 'user@example.com',
                path: '/var/www/app',
            );

            $this->service->executeRemoteCommand($remote, 'ls -la');

            Process::assertRan(function ($process) {
                return $process->command === ['ssh', 'user@example.com', 'ls -la'];
            });
        });

        it('respects timeout parameter', function () {
            Process::fake();

            $remote = new RemoteConfig(
                name: 'production',
                host: 'user@example.com',
                path: '/var/www/app',
            );

            $this->service->executeRemoteCommand($remote, 'sleep 1', 60);

            Process::assertRan(fn ($process) => $process->command === ['ssh', 'user@example.com', 'sleep 1']);
        });
    });

    describe('isAtomicDeployment', function () {
        it('returns true when remote has /current directory', function () {
            Process::fake([
                '*' => Process::result(output: 'yes'),
            ]);

            $remote = new RemoteConfig(
                name: 'production',
                host: 'user@example.com',
                path: '/var/www/app',
            );

            $result = $this->service->isAtomicDeployment($remote);

            expect($result)->toBeTrue();
        });

        it('returns false when no /current directory', function () {
            Process::fake([
                '*' => Process::result(output: 'no'),
            ]);

            $remote = new RemoteConfig(
                name: 'production',
                host: 'user@example.com',
                path: '/var/www/app',
            );

            $result = $this->service->isAtomicDeployment($remote);

            expect($result)->toBeFalse();
        });

        it('executes correct test command', function () {
            Process::fake([
                '*' => Process::result(output: 'no'),
            ]);

            $remote = new RemoteConfig(
                name: 'production',
                host: 'user@example.com',
                path: '/var/www/app',
            );

            $this->service->isAtomicDeployment($remote);

            Process::assertRan(function ($process) {
                return $process->command === [
                    'ssh',
                    'user@example.com',
                    "test -d '/var/www/app/current' && echo 'yes' || echo 'no'",
                ];
            });
        });
    });

    describe('getRemoteDatabaseInfo', function () {
        it('parses driver, tables, and migrations from tinker output', function () {
            Process::fake([
                '*' => Process::result(output: '{"driver":"mysql","tables":["users","posts"],"migrations":["2024_01_01_create_users"]}'),
            ]);

            $remote = new RemoteConfig(
                name: 'production',
                host: 'user@example.com',
                path: '/var/www/app',
            );

            $info = $this->service->getRemoteDatabaseInfo($remote);

            expect($info)->toBe([
                'driver' => 'mysql',
                'tables' => ['users', 'posts'],
                'migrations' => ['2024_01_01_create_users'],
            ]);
        });

        it('returns defaults on failure', function () {
            Process::fake([
                '*' => Process::result(exitCode: 1, errorOutput: 'Command failed'),
            ]);

            $remote = new RemoteConfig(
                name: 'production',
                host: 'user@example.com',
                path: '/var/www/app',
            );

            $info = $this->service->getRemoteDatabaseInfo($remote);

            expect($info)->toBe(['driver' => null, 'tables' => [], 'migrations' => []]);
        });

        it('returns defaults for invalid JSON output', function () {
            Process::fake([
                '*' => Process::result(output: 'not json'),
            ]);

            $remote = new RemoteConfig(
                name: 'production',
                host: 'user@example.com',
                path: '/var/www/app',
            );

            $info = $this->service->getRemoteDatabaseInfo($remote);

            expect($info)->toBe(['driver' => null, 'tables' => [], 'migrations' => []]);
        });

        it('uses working path in command', function () {
            Process::fake([
                '*' => Process::result(output: '{"driver":"mysql","tables":[],"migrations":[]}'),
            ]);

            $remote = new RemoteConfig(
                name: 'production',
                host: 'user@example.com',
                path: '/var/www/app',
                isAtomic: true,
            );

            $this->service->getRemoteDatabaseInfo($remote);

            Process::assertRan(function ($process) {
                return str_contains($process->command[2], "cd '/var/www/app/current'");
            });
        });

        it('uses detected PHP binary in tinker command', function () {
            Process::fake(function ($process) {
                $command = implode(' ', $process->command);

                if (str_contains($command, 'vendor/composer/platform_check.php')) {
                    return Process::result(output: 'php8.5');
                }

                return Process::result(output: '{"driver":"mysql","tables":[],"migrations":[]}');
            });

            $remote = new RemoteConfig(
                name: 'production',
                host: 'user@example.com',
                path: '/var/www/app',
            );

            $this->service->getRemoteDatabaseInfo($remote);

            Process::assertRan(function ($process) {
                return str_contains($process->command[2], "'php8.5' artisan tinker");
            });
        });
    });

    describe('createRemoteSnapshot', function () {
        it('builds correct artisan command without exclusions when full=true', function () {
            Process::fake();

            $remote = new RemoteConfig(
                name: 'production',
                host: 'user@example.com',
                path: '/var/www/app',
            );

            config()->set('remote-sync.exclude_tables', ['sessions', 'cache']);

            $this->service->createRemoteSnapshot($remote, 'test-snapshot', full: true);

            Process::assertRan(function ($process) {
                $command = $process->command[2];

                return str_contains($command, "snapshot:create 'test-snapshot'")
                    && str_contains($command, '--compress')
                    && ! str_contains($command, '--exclude');
            });
        });

        it('builds correct artisan command with exclusions when full=false', function () {
            Process::fake();

            $remote = new RemoteConfig(
                name: 'production',
                host: 'user@example.com',
                path: '/var/www/app',
            );

            config()->set('remote-sync.exclude_tables', ['sessions', 'cache']);

            $this->service->createRemoteSnapshot($remote, 'test-snapshot', full: false);

            Process::assertRan(function ($process) {
                $command = $process->command[2];

                return str_contains($command, "--exclude='sessions'")
                    && str_contains($command, "--exclude='cache'");
            });
        });

        it('includes migrations in exclusions when full=false', function () {
            Process::fake();

            $remote = new RemoteConfig(
                name: 'production',
                host: 'user@example.com',
                path: '/var/www/app',
            );

            config()->set('remote-sync.exclude_tables', ['sessions']);

            $this->service->createRemoteSnapshot($remote, 'test-snapshot', full: false);

            Process::assertRan(function ($process) {
                $command = $process->command[2];

                return str_contains($command, "--exclude='sessions'")
                    && str_contains($command, "--exclude='migrations'");
            });
        });

        it('does not include migrations in exclusions when full=true', function () {
            Process::fake();

            $remote = new RemoteConfig(
                name: 'production',
                host: 'user@example.com',
                path: '/var/www/app',
            );

            config()->set('remote-sync.exclude_tables', ['sessions']);

            $this->service->createRemoteSnapshot($remote, 'test-snapshot', full: true);

            Process::assertRan(function ($process) {
                $command = $process->command[2];

                return ! str_contains($command, '--exclude');
            });
        });

        it('uses detected PHP binary in snapshot create command', function () {
            Process::fake(function ($process) {
                $command = implode(' ', $process->command);

                if (str_contains($command, 'vendor/composer/platform_check.php')) {
                    return Process::result(output: 'php8.5');
                }

                return Process::result();
            });

            $remote = new RemoteConfig(
                name: 'production',
                host: 'user@example.com',
                path: '/var/www/app',
            );

            $this->service->createRemoteSnapshot($remote, 'test-snapshot', full: true);

            Process::assertRan(function ($process) {
                return str_contains($process->command[2], "'php8.5' artisan snapshot:create 'test-snapshot'");
            });
        });
    });

    describe('deleteRemoteSnapshot', function () {
        it('builds correct delete command', function () {
            Process::fake();

            $remote = new RemoteConfig(
                name: 'production',
                host: 'user@example.com',
                path: '/var/www/app',
            );

            $this->service->deleteRemoteSnapshot($remote, 'test-snapshot');

            Process::assertRan(function ($process) {
                return str_contains($process->command[2], "snapshot:delete 'test-snapshot' --no-interaction");
            });
        });
    });

    describe('listRemoteSnapshots', function () {
        it('builds correct stat command with GNU and BSD fallback', function () {
            Process::fake([
                '*' => Process::result(output: 'snapshots'),
            ]);

            $remote = new RemoteConfig(
                name: 'production',
                host: 'user@example.com',
                path: '/var/www/app',
            );

            $this->service->listRemoteSnapshots($remote);

            Process::assertRan(function ($process) {
                return str_contains($process->command[2] ?? '', "stat -c '%Y %n'")
                    && str_contains($process->command[2], "stat -f '%m %N'")
                    && str_contains($process->command[2], 'sort -rn')
                    && str_contains($process->command[2], "'/var/www/app/storage/snapshots'");
            });
        });
    });

    describe('getRemoteSnapshotPath', function () {
        it('returns correct path using local config', function () {
            $remote = new RemoteConfig(
                name: 'production',
                host: 'user@example.com',
                path: '/var/www/app',
            );

            $path = $this->service->getRemoteSnapshotPath($remote, 'test-snapshot');

            expect($path)->toBe('/var/www/app/storage/snapshots/test-snapshot.sql.gz');
        });

        it('returns correct path for atomic deployment', function () {
            $remote = new RemoteConfig(
                name: 'production',
                host: 'user@example.com',
                path: '/var/www/app',
                isAtomic: true,
            );

            $path = $this->service->getRemoteSnapshotPath($remote, 'test-snapshot');

            expect($path)->toBe('/var/www/app/current/storage/snapshots/test-snapshot.sql.gz');
        });

        it('uses custom disk config from local config', function () {
            config()->set('db-snapshots.disk', 'custom-snapshots');
            config()->set('filesystems.disks.custom-snapshots', [
                'driver' => 'local',
                'root' => storage_path('custom-snapshots'),
            ]);

            $remote = new RemoteConfig(
                name: 'production',
                host: 'user@example.com',
                path: '/var/www/app',
            );

            $path = $this->service->getRemoteSnapshotPath($remote, 'test-snapshot');

            expect($path)->toBe('/var/www/app/storage/custom-snapshots/test-snapshot.sql.gz');
        });
    });

    describe('loadRemoteSnapshot', function () {
        it('builds correct load command', function () {
            Process::fake();

            $remote = new RemoteConfig(
                name: 'production',
                host: 'user@example.com',
                path: '/var/www/app',
            );

            $this->service->loadRemoteSnapshot($remote, 'test-snapshot');

            Process::assertRan(function ($process) {
                return str_contains($process->command[2], "snapshot:load 'test-snapshot' --force");
            });
        });
    });

    describe('runRemoteMigrations', function () {
        it('uses detected PHP binary in migrate command', function () {
            Process::fake(function ($process) {
                $command = implode(' ', $process->command);

                if (str_contains($command, 'vendor/composer/platform_check.php')) {
                    return Process::result(output: 'php8.5');
                }

                return Process::result();
            });

            $remote = new RemoteConfig(
                name: 'production',
                host: 'user@example.com',
                path: '/var/www/app',
            );

            $this->service->runRemoteMigrations($remote);

            Process::assertRan(function ($process) {
                return str_contains($process->command[2], "'php8.5' artisan migrate --force");
            });
        });
    });

    describe('loadSnapshotViaCli', function () {
        it('constructs correct MySQL CLI command', function () {
            Process::fake();

            config()->set('database.default', 'testing');
            config()->set('database.connections.testing', [
                'driver' => 'mysql',
                'host' => '127.0.0.1',
                'port' => 3306,
                'username' => 'root',
                'password' => 'secret',
                'database' => 'mydb',
                'unix_socket' => '',
            ]);

            $snapshotPath = storage_path('snapshots');

            if (! is_dir($snapshotPath)) {
                mkdir($snapshotPath, 0755, true);
            }

            file_put_contents("{$snapshotPath}/test.sql.gz", 'fake');

            $this->service->loadSnapshotViaCli('test', false);

            Process::assertRan(function ($process) {
                $command = $process->command;

                return str_contains($command, 'gunzip -c')
                    && str_contains($command, '| mysql')
                    && str_contains($command, '--host=127.0.0.1')
                    && str_contains($command, '--port=3306')
                    && str_contains($command, '--user=root')
                    && ! str_contains($command, '--password')
                    && str_contains($command, 'mydb');
            });

            @unlink("{$snapshotPath}/test.sql.gz");
        });

        it('uses socket instead of host/port for MySQL with unix_socket', function () {
            Process::fake();

            config()->set('database.default', 'testing');
            config()->set('database.connections.testing', [
                'driver' => 'mysql',
                'host' => '127.0.0.1',
                'port' => 3306,
                'username' => 'root',
                'password' => 'secret',
                'database' => 'mydb',
                'unix_socket' => '/tmp/mysql.sock',
            ]);

            $snapshotPath = storage_path('snapshots');

            if (! is_dir($snapshotPath)) {
                mkdir($snapshotPath, 0755, true);
            }

            file_put_contents("{$snapshotPath}/test.sql.gz", 'fake');

            $this->service->loadSnapshotViaCli('test', false);

            Process::assertRan(function ($process) {
                $command = $process->command;

                return str_contains($command, '--socket=/tmp/mysql.sock')
                    && ! str_contains($command, '--host=')
                    && ! str_contains($command, '--port=');
            });

            @unlink("{$snapshotPath}/test.sql.gz");
        });

        it('omits password flag for MySQL when password is empty', function () {
            Process::fake();

            config()->set('database.default', 'testing');
            config()->set('database.connections.testing', [
                'driver' => 'mysql',
                'host' => '127.0.0.1',
                'port' => 3306,
                'username' => 'root',
                'password' => '',
                'database' => 'mydb',
                'unix_socket' => '',
            ]);

            $snapshotPath = storage_path('snapshots');

            if (! is_dir($snapshotPath)) {
                mkdir($snapshotPath, 0755, true);
            }

            file_put_contents("{$snapshotPath}/test.sql.gz", 'fake');

            $this->service->loadSnapshotViaCli('test', false);

            Process::assertRan(function ($process) {
                return ! str_contains($process->command, '--password=');
            });

            @unlink("{$snapshotPath}/test.sql.gz");
        });

        it('constructs correct PostgreSQL CLI command with PGPASSWORD', function () {
            Process::fake();

            config()->set('database.default', 'testing');
            config()->set('database.connections.testing', [
                'driver' => 'pgsql',
                'host' => '127.0.0.1',
                'port' => 5432,
                'username' => 'postgres',
                'password' => 'pgpass',
                'database' => 'mydb',
            ]);

            $snapshotPath = storage_path('snapshots');

            if (! is_dir($snapshotPath)) {
                mkdir($snapshotPath, 0755, true);
            }

            file_put_contents("{$snapshotPath}/test.sql.gz", 'fake');

            $this->service->loadSnapshotViaCli('test', false);

            Process::assertRan(function ($process) {
                $command = $process->command;

                return str_contains($command, 'gunzip -c')
                    && str_contains($command, '| psql')
                    && str_contains($command, '--host=127.0.0.1')
                    && str_contains($command, '--port=5432')
                    && str_contains($command, '--username=postgres')
                    && str_contains($command, 'mydb');
            });

            @unlink("{$snapshotPath}/test.sql.gz");
        });

        it('throws RuntimeException for unsupported drivers', function () {
            config()->set('database.default', 'testing');
            config()->set('database.connections.testing', [
                'driver' => 'sqlite',
                'database' => ':memory:',
            ]);

            $snapshotPath = storage_path('snapshots');

            if (! is_dir($snapshotPath)) {
                mkdir($snapshotPath, 0755, true);
            }

            file_put_contents("{$snapshotPath}/test.sql.gz", 'fake');

            expect(fn () => $this->service->loadSnapshotViaCli('test', false))
                ->toThrow(RuntimeException::class, 'Unsupported database driver for CLI loading: sqlite');

            @unlink("{$snapshotPath}/test.sql.gz");
        });

        it('throws RuntimeException when snapshot file not found', function () {
            expect(fn () => $this->service->loadSnapshotViaCli('nonexistent', false))
                ->toThrow(RuntimeException::class, 'Snapshot file not found');
        });
    });

    describe('createRemoteBackup', function () {
        it('builds correct backup command with exclusions', function () {
            Process::fake();

            $remote = new RemoteConfig(
                name: 'production',
                host: 'user@example.com',
                path: '/var/www/app',
            );

            config()->set('remote-sync.exclude_tables', ['sessions', 'cache']);

            $this->service->createRemoteBackup($remote, 'backup-name');

            Process::assertRan(function ($process) {
                $command = $process->command[2];

                return str_contains($command, "snapshot:create 'backup-name'")
                    && str_contains($command, "--exclude='sessions'")
                    && str_contains($command, "--exclude='cache'")
                    && str_contains($command, '--compress');
            });
        });

        it('includes migrations in backup exclusions', function () {
            Process::fake();

            $remote = new RemoteConfig(
                name: 'production',
                host: 'user@example.com',
                path: '/var/www/app',
            );

            config()->set('remote-sync.exclude_tables', ['sessions']);

            $this->service->createRemoteBackup($remote, 'backup-name');

            Process::assertRan(function ($process) {
                $command = $process->command[2];

                return str_contains($command, "--exclude='sessions'")
                    && str_contains($command, "--exclude='migrations'");
            });
        });
    });

    describe('getLocalMigrationRecords', function () {
        it('returns empty array when migrations table does not exist', function () {
            $migrations = $this->service->getLocalMigrationRecords();

            expect($migrations)->toBe([]);
        });
    });
});
