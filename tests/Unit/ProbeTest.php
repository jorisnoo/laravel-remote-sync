<?php

use Illuminate\Support\Facades\Process;
use Noo\LaravelRemoteSync\Database\Inspector;
use Noo\LaravelRemoteSync\Remotes\Connection;
use Noo\LaravelRemoteSync\Remotes\Probe;
use Noo\LaravelRemoteSync\Remotes\Remote;
use Noo\LaravelRemoteSync\Remotes\RemoteInfo;

function probe(?string $phpBinary = null): RemoteInfo
{
    return (new Probe)->run(new Connection(new Remote(
        name: 'production', host: 'forge@prod.acme.test', path: '/srv/acme', phpBinary: $phpBinary,
    )));
}

describe('Probe', function () {
    it('builds a script that tries every PHP candidate and runs remote-sync:info', function () {
        $script = (new Probe)->script(new Remote(name: 'production', host: 'h', path: '/srv/acme'));

        expect($script)
            ->toContain("BASE='/srv/acme';")
            ->toContain('"$BASE/current"')
            ->toContain("'php' 'php8.5' 'php85' 'php8.4'")
            ->toContain('platform_check.php')
            ->toContain('artisan remote-sync:info');
    });

    it('only tries the configured php_binary', function () {
        $script = (new Probe)->script(new Remote(name: 'production', host: 'h', path: '/srv/acme', phpBinary: '/usr/bin/php8.4'));

        expect($script)->toContain("for candidate in '/usr/bin/php8.4'; do")->not->toContain("'php8.5'");
    });

    it('returns the working path, PHP binary and app info', function () {
        Process::fake(['*' => Process::result(output: probeOutput(['tables' => ['users']]))]);

        $info = probe();

        expect($info->workingPath)->toBe('/srv/acme/current')
            ->and($info->phpBinary)->toBe('php8.4')
            ->and($info->app->driver)->toBe('mysql')
            ->and($info->app->tables)->toBe(['users']);
    });

    it('ignores noise around the expected lines', function () {
        Process::fake(['*' => Process::result(output: "Deprecated: something\n".probeOutput()."\n")]);

        expect(probe()->app->protocol)->toBe(Inspector::PROTOCOL);
    });

    it('explains each failure', function (string $output, string $error, string $message) {
        Process::fake(['*' => Process::result(output: $output, errorOutput: $error, exitCode: 1)]);

        expect(fn () => probe())->toThrow(RuntimeException::class, $message);
    })->with([
        'missing path' => ['REMOTE_SYNC_NO_PATH', '', "Cannot access '/srv/acme' on [production]: directory not found"],
        'unreachable' => ['', 'ssh: Could not resolve hostname', 'Cannot reach [production] (forge@prod.acme.test): ssh: Could not resolve hostname'],
        'no php' => ["WORK=/srv/acme\nPHP=\nRSYNC=1", '', "Set 'php_binary'"],
        'no rsync' => ["WORK=/srv/acme\nPHP=php\nRSYNC=0", '', 'rsync is not installed on [production]'],
        'not installed' => ["WORK=/srv/acme\nPHP=php\nRSYNC=1", 'ERROR  Command "remote-sync:info" is not defined.', 'remote-sync:info failed on [production]: ERROR  Command "remote-sync:info" is not defined. Install jorisnoo/laravel-remote-sync there'],
        'artisan error on stdout' => ["WORK=/srv/acme\nPHP=php\nRSYNC=1\n\n   ERROR  There are no commands defined in the \"remote-sync\" namespace.", '', 'remote-sync:info failed on [production]: ERROR  There are no commands defined in the "remote-sync" namespace. Install jorisnoo/laravel-remote-sync'],
        'database down' => ["WORK=/srv/acme\nPHP=php\nRSYNC=1", "\n   Illuminate\\Database\\QueryException \n\n  SQLSTATE[HY000] [2002] Connection refused\n  at vendor/x.php:1", 'remote-sync:info failed on [production]: Illuminate\\Database\\QueryException SQLSTATE[HY000] [2002] Connection refused'],
    ]);

    it('refuses a remote on another protocol version, naming the side to update', function (int $protocol, string $message) {
        Process::fake(['*' => Process::result(output: probeOutput(['protocol' => $protocol]))]);

        expect(fn () => probe())->toThrow(RuntimeException::class, $message);
    })->with([
        'remote older' => [Inspector::PROTOCOL - 1, 'deploy the newer jorisnoo/laravel-remote-sync to [production]'],
        'remote newer' => [Inspector::PROTOCOL + 1, 'update jorisnoo/laravel-remote-sync locally'],
    ]);
});
