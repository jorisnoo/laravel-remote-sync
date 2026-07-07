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

            Process::assertRan(fn ($process) => $process->command === ['ssh', 'forge@prod.acme.test', 'ls -la']);
        });
    });

    describe('artisan', function () {
        it('changes into the working path and uses the detected php binary', function () {
            Process::fake();

            makeConnection()->artisan(makeRemoteInfo(), 'migrate --force');

            Process::assertRan(fn ($process) => $process->command === [
                'ssh',
                'forge@prod.acme.test',
                "cd '/home/forge/acme/current' && 'php8.4' artisan migrate --force",
            ]);
        });

        it('falls back to bare php when no binary was detected', function () {
            Process::fake();

            makeConnection()->artisan(makeRemoteInfo(phpBinary: null), 'migrate --force');

            Process::assertRan(fn ($process) => str_contains($process->command[2], "'php' artisan migrate --force"));
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
                '-o', 'BatchMode=yes',
                '-o', 'ConnectTimeout=5',
                'forge@prod.acme.test',
                'exit',
            ]);
        });

        it('returns ok when there is no host key issue', function () {
            Process::fake(['*' => Process::result()]);

            expect(makeConnection()->checkHostKey())->toBe('ok');
        });

        it('returns unknown when the host is not in known_hosts', function () {
            Process::fake(['*' => Process::result(exitCode: 255, errorOutput: 'Host key verification failed.')]);

            expect(makeConnection()->checkHostKey())->toBe('unknown');
        });

        it('returns changed when the host key does not match', function () {
            Process::fake(['*' => Process::result(
                exitCode: 255,
                errorOutput: "@ WARNING: REMOTE HOST IDENTIFICATION HAS CHANGED! @\nHost key verification failed.",
            )]);

            expect(makeConnection()->checkHostKey())->toBe('changed');
        });
    });

    describe('hostFingerprints', function () {
        it('scans the hostname without the user part', function () {
            Process::fake(['*' => Process::result(output: '256 SHA256:abc prod.acme.test (ED25519)')]);

            $fingerprints = makeConnection()->hostFingerprints();

            expect($fingerprints)->toBe('256 SHA256:abc prod.acme.test (ED25519)');

            Process::assertRan(fn ($process) => $process->command === "ssh-keyscan -t ed25519,rsa,ecdsa 'prod.acme.test' 2>/dev/null | ssh-keygen -lf -");
        });

        it('returns null when the scan produces nothing', function () {
            Process::fake(['*' => Process::result(output: '')]);

            expect(makeConnection()->hostFingerprints())->toBeNull();
        });
    });

    describe('acceptHostKey', function () {
        it('connects with accept-new to save the key', function () {
            Process::fake(['*' => Process::result()]);

            expect(makeConnection()->acceptHostKey())->toBeTrue();

            Process::assertRan(fn ($process) => $process->command === [
                'ssh',
                '-o', 'StrictHostKeyChecking=accept-new',
                '-o', 'ConnectTimeout=10',
                'forge@prod.acme.test',
                'exit',
            ]);
        });

        it('reports failure when verification still fails', function () {
            Process::fake(['*' => Process::result(exitCode: 255, errorOutput: 'Host key verification failed.')]);

            expect(makeConnection()->acceptHostKey())->toBeFalse();
        });
    });
});
