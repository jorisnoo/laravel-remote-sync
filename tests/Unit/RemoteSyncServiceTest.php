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
                    "test -d /var/www/app/current && echo 'yes' || echo 'no'",
                ];
            });
        });
    });

    describe('getRemoteDatabaseDriver', function () {
        it('parses driver from tinker output', function () {
            Process::fake([
                '*' => Process::result(output: 'mysql'),
            ]);

            $remote = new RemoteConfig(
                name: 'production',
                host: 'user@example.com',
                path: '/var/www/app',
            );

            $driver = $this->service->getRemoteDatabaseDriver($remote);

            expect($driver)->toBe('mysql');
        });

        it('returns null on failure', function () {
            Process::fake([
                '*' => Process::result(exitCode: 1, errorOutput: 'Command failed'),
            ]);

            $remote = new RemoteConfig(
                name: 'production',
                host: 'user@example.com',
                path: '/var/www/app',
            );

            $driver = $this->service->getRemoteDatabaseDriver($remote);

            expect($driver)->toBeNull();
        });

        it('returns null for empty output', function () {
            Process::fake([
                '*' => Process::result(output: ''),
            ]);

            $remote = new RemoteConfig(
                name: 'production',
                host: 'user@example.com',
                path: '/var/www/app',
            );

            $driver = $this->service->getRemoteDatabaseDriver($remote);

            expect($driver)->toBeNull();
        });

        it('uses working path in command', function () {
            Process::fake([
                '*' => Process::result(output: 'mysql'),
            ]);

            $remote = new RemoteConfig(
                name: 'production',
                host: 'user@example.com',
                path: '/var/www/app',
                isAtomic: true,
            );

            $this->service->getRemoteDatabaseDriver($remote);

            Process::assertRan(function ($process) {
                return str_contains($process->command[2], 'cd /var/www/app/current');
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

                return str_contains($command, 'snapshot:create test-snapshot')
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

                return str_contains($command, '--exclude=sessions')
                    && str_contains($command, '--exclude=cache');
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
                return str_contains($process->command[2], 'snapshot:delete test-snapshot --no-interaction');
            });
        });
    });

    describe('listRemoteSnapshots', function () {
        it('builds correct stat command', function () {
            Process::fake();

            $remote = new RemoteConfig(
                name: 'production',
                host: 'user@example.com',
                path: '/var/www/app',
            );

            $this->service->listRemoteSnapshots($remote);

            Process::assertRan(function ($process) {
                return str_contains($process->command[2], "stat -c '%Y %n'")
                    && str_contains($process->command[2], '/var/www/app/storage/snapshots/*.sql.gz');
            });
        });
    });

    describe('getRemoteSnapshotPath', function () {
        it('returns correct path', function () {
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
                return str_contains($process->command[2], 'snapshot:load test-snapshot --force');
            });
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

                return str_contains($command, 'snapshot:create backup-name')
                    && str_contains($command, '--exclude=sessions')
                    && str_contains($command, '--exclude=cache')
                    && str_contains($command, '--compress');
            });
        });
    });
});
