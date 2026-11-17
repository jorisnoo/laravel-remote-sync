<?php

use Illuminate\Support\Facades\Process;
use Noo\LaravelRemoteSync\Remotes\Connection;
use Noo\LaravelRemoteSync\Remotes\HostKey;
use Noo\LaravelRemoteSync\Remotes\Remote;

function makeConnection(): Connection
{
    return new Connection(new Remote(name: 'production', host: 'forge@prod.acme.test', path: '/srv/acme'));
}

describe('Connection', function () {
    it('runs commands over ssh without ever prompting', function () {
        Process::fake();

        makeConnection()->run('ls -la');

        Process::assertRan(fn ($process) => $process->command === [
            'ssh', '-o', 'BatchMode=yes', '-o', 'ConnectTimeout=10', 'forge@prod.acme.test', 'ls -la',
        ]);
    });

    it('gives rsync the same ssh options', function () {
        expect(Connection::shell())->toBe('ssh -o BatchMode=yes -o ConnectTimeout=10');
    });

    it('reads both timeouts from config, with defaults', function () {
        config()->set('remote-sync.timeouts', ['remote' => 120, 'transfer' => 3600]);
        expect([Connection::remoteTimeout(), Connection::transferTimeout()])->toBe([120, 3600]);

        config()->set('remote-sync.timeouts', []);
        expect([Connection::remoteTimeout(), Connection::transferTimeout()])->toBe([300, 1800]);
    });

    it('classifies the host key from ssh output', function (string $error, HostKey $expected) {
        Process::fake(['*' => Process::result(exitCode: $error === '' ? 0 : 255, errorOutput: $error)]);

        expect(makeConnection()->checkHostKey())->toBe($expected);
    })->with([
        'trusted' => ['', HostKey::Trusted],
        'unknown' => ['Host key verification failed.', HostKey::Unknown],
        'changed' => ["@ WARNING: REMOTE HOST IDENTIFICATION HAS CHANGED! @\nHost key verification failed.", HostKey::Changed],
        'auth failed' => ['forge@prod.acme.test: Permission denied (publickey).', HostKey::AuthFailed],
        'other errors are left to the probe' => ['ssh: connect to host prod.acme.test port 22: Connection refused', HostKey::Trusted],
    ]);

    it('scans fingerprints for the hostname without the user', function () {
        Process::fake(['*' => Process::result(output: '256 SHA256:abc prod.acme.test (ED25519)')]);

        expect(makeConnection()->hostFingerprints())->toBe('256 SHA256:abc prod.acme.test (ED25519)');

        Process::assertRan(fn ($process) => $process->command === "ssh-keyscan -t ed25519,rsa,ecdsa 'prod.acme.test' 2>/dev/null | ssh-keygen -lf -");
    });

    it('accepts a new host key with accept-new', function () {
        Process::fake();

        expect(makeConnection()->acceptHostKey())->toBeTrue();

        Process::assertRan(fn ($process) => in_array('StrictHostKeyChecking=accept-new', $process->command, true));
    });
});
