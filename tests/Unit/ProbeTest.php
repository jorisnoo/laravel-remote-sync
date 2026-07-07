<?php

use Illuminate\Support\Facades\Process;
use Noo\LaravelRemoteSync\Remotes\Connection;
use Noo\LaravelRemoteSync\Remotes\Probe;
use Noo\LaravelRemoteSync\Remotes\Remote;

function probeConnection(string $path = '/home/forge/acme', ?string $phpBinary = null): Connection
{
    return new Connection(new Remote(
        name: 'production',
        host: 'forge@prod.acme.test',
        path: $path,
        phpBinary: $phpBinary,
    ));
}

function happyProbeOutput(): string
{
    return implode("\n", [
        'ATOMIC=1',
        'PHP=php8.4',
        'RSYNC=1',
        'SNAPSHOTS=1',
        'REMOTE_SYNC_JSON='.json_encode([
            'driver' => 'mysql',
            'tables' => ['users', 'posts'],
            'snapshot_dir' => '/home/forge/acme/shared/snapshots',
        ]),
    ]);
}

describe('Probe', function () {
    describe('command', function () {
        it('tries every candidate binary when none is configured', function () {
            $command = (new Probe)->command(probeConnection()->remote);

            expect($command)
                ->toContain("'php' 'php8.5' 'php85' 'php8.4'")
                ->toContain('platform_check.php')
                ->toContain('artisan tinker --execute=')
                ->toContain('snapshot_dir');
        });

        it('only tries the configured php_binary when set', function () {
            $command = (new Probe)->command(probeConnection(phpBinary: '/usr/bin/php8.4')->remote);

            expect($command)
                ->toContain("for candidate in '/usr/bin/php8.4'; do")
                ->not->toContain("'php8.5'");
        });
    });

    describe('run', function () {
        it('parses a complete probe response', function () {
            Process::fake(['*' => Process::result(output: happyProbeOutput())]);

            $info = (new Probe)->run(probeConnection());

            expect($info->phpBinary)->toBe('php8.4')
                ->and($info->isAtomic)->toBeTrue()
                ->and($info->workingPath)->toBe('/home/forge/acme/current')
                ->and($info->storagePath())->toBe('/home/forge/acme/current/storage')
                ->and($info->snapshotDir)->toBe('/home/forge/acme/shared/snapshots')
                ->and($info->driver)->toBe('mysql')
                ->and($info->tables)->toBe(['users', 'posts'])
                ->and($info->hasDbSnapshots)->toBeTrue()
                ->and($info->hasRsync)->toBeTrue();
        });

        it('does not double-append current when the path already ends with it', function () {
            Process::fake(['*' => Process::result(output: str_replace('ATOMIC=1', 'ATOMIC=0', happyProbeOutput()))]);

            $info = (new Probe)->run(probeConnection(path: '/home/forge/acme/current'));

            expect($info->workingPath)->toBe('/home/forge/acme/current')
                ->and($info->isAtomic)->toBeTrue();
        });

        it('keeps the plain path for non-atomic layouts', function () {
            Process::fake(['*' => Process::result(output: str_replace('ATOMIC=1', 'ATOMIC=0', happyProbeOutput()))]);

            $info = (new Probe)->run(probeConnection());

            expect($info->workingPath)->toBe('/home/forge/acme')
                ->and($info->isAtomic)->toBeFalse();
        });

        it('survives a partial response with defensive defaults', function () {
            Process::fake(['*' => Process::result(output: "ATOMIC=0\nPHP=\nRSYNC=0\nSNAPSHOTS=0")]);

            $info = (new Probe)->run(probeConnection());

            expect($info->phpBinary)->toBeNull()
                ->and($info->driver)->toBeNull()
                ->and($info->tables)->toBe([])
                ->and($info->hasDbSnapshots)->toBeFalse()
                ->and($info->hasRsync)->toBeFalse()
                ->and($info->snapshotDir)->toBe('/home/forge/acme/storage/snapshots');
        });

        it('ignores garbage around the structured lines', function () {
            $output = "Warning: something deprecated\n".happyProbeOutput()."\nnoise at the end";
            Process::fake(['*' => Process::result(output: $output)]);

            $info = (new Probe)->run(probeConnection());

            expect($info->driver)->toBe('mysql')
                ->and($info->phpBinary)->toBe('php8.4');
        });

        it('fails with an actionable message when the path does not exist', function () {
            Process::fake(['*' => Process::result(output: 'REMOTE_SYNC_CD_FAILED', exitCode: 1)]);

            expect(fn () => (new Probe)->run(probeConnection()))
                ->toThrow(RuntimeException::class, "Cannot access '/home/forge/acme' on [production]");
        });

        it('fails with the ssh error when the host is unreachable', function () {
            Process::fake(['*' => Process::result(exitCode: 255, errorOutput: 'Connection refused')]);

            expect(fn () => (new Probe)->run(probeConnection()))
                ->toThrow(RuntimeException::class, 'Cannot reach [production] (forge@prod.acme.test): Connection refused');
        });
    });
});
