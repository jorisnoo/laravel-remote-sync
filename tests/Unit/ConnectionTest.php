<?php

use Illuminate\Support\Facades\Process;
use Noo\LaravelRemoteSync\Remotes\Connection;
use Noo\LaravelRemoteSync\Remotes\Remote;
use Noo\LaravelRemoteSync\Remotes\RemoteInfo;

function makeConnection(): Connection
{
    return new Connection(new Remote(
        name: 'production',
        host: 'forge@prod.acme.test',
        path: '/home/forge/acme',
    ));
}

function makeRemoteInfo(?string $phpBinary = 'php8.4', string $workingPath = '/home/forge/acme/current'): RemoteInfo
{
    return new RemoteInfo(
        phpBinary: $phpBinary,
        isAtomic: true,
        workingPath: $workingPath,
        snapshotDir: '/home/forge/acme/shared/storage/snapshots',
        driver: 'mysql',
        tables: ['users'],
        hasDbSnapshots: true,
        hasRsync: true,
    );
}

describe('Connection', function () {
    describe('run', function () {
        it('executes the command over ssh', function () {
            Process::fake();

            makeConnection()->run('ls -la');

            Process::assertRan(fn ($process) => $process->command === ['ssh', ...Connection::SSH_OPTIONS, '--', 'forge@prod.acme.test', 'ls -la']);
        });
    });

    describe('artisan', function () {
        it('changes into the working path and uses the detected php binary', function () {
            Process::fake();

            makeConnection()->artisan(makeRemoteInfo(), 'migrate --force');

            Process::assertRan(fn ($process) => $process->command === [
                'ssh',
                ...Connection::SSH_OPTIONS,
                '--',
                'forge@prod.acme.test',
                "cd '/home/forge/acme/current' && 'php8.4' artisan migrate --force",
            ]);
        });

        it('falls back to bare php when no binary was detected', function () {
            Process::fake();

            makeConnection()->artisan(makeRemoteInfo(phpBinary: null), 'migrate --force');

            Process::assertRan(fn ($process) => str_contains(last($process->command), "'php' artisan migrate --force"));
        });
    });

    describe('timeouts', function () {
        it('reads the two timeout knobs from config', function () {
            config()->set('remote-sync.timeouts', ['remote' => 120, 'transfer' => 3600]);

            $connection = makeConnection();

            expect($connection->remoteTimeout())->toBe(120)
                ->and($connection->transferTimeout())->toBe(3600);
        });

        it('falls back to sensible defaults when unset', function () {
            config()->set('remote-sync.timeouts', []);

            $connection = makeConnection();

            expect($connection->remoteTimeout())->toBe(300)
                ->and($connection->transferTimeout())->toBe(1800);
        });
    });

    describe('checkHostKey', function () {
        it('probes with BatchMode so an unknown host fails instead of prompting', function () {
            Process::fake();

            makeConnection()->checkHostKey();

            Process::assertRan(fn ($process) => $process->command === [
                'ssh',
                '-o', 'StrictHostKeyChecking=yes',
                '-o', 'BatchMode=yes',
                '-o', 'UpdateHostKeys=no',
                '-o', 'ConnectTimeout=5',
                '--',
                'forge@prod.acme.test',
                'exit',
            ]);
        });

        it('requires independently verified keys for unknown hosts', function () {
            Process::fake(['*' => Process::result(exitCode: 255, errorOutput: 'Host key verification failed.')]);

            expect(fn () => makeConnection()->checkHostKey())->toThrow(RuntimeException::class, 'Verify its fingerprint');
        });

        it('rejects a changed host key', function () {
            Process::fake(['*' => Process::result(
                exitCode: 255,
                errorOutput: "@ WARNING: REMOTE HOST IDENTIFICATION HAS CHANGED! @\nHost key verification failed.",
            )]);

            expect(fn () => makeConnection()->checkHostKey())->toThrow(RuntimeException::class, 'man-in-the-middle');
        });

        it('does not trust a failed ssh connection even without a host key warning', function () {
            Process::fake(['*' => Process::result(exitCode: 255, errorOutput: 'Permission denied (publickey).')]);

            expect(fn () => makeConnection()->checkHostKey())->toThrow(RuntimeException::class, 'Permission denied (publickey).');
        });
    });
});
