<?php

use Illuminate\Support\Facades\Process;

function doctorTestConfig(): void
{
    config()->set('remote-sync.remotes', [
        'staging' => [
            'host' => 'forge@staging.acme.test',
            'path' => '/home/forge/acme',
        ],
    ]);

    // Doctor only reads the connection config, so a mysql default makes
    // the local-driver check pass without a real server.
    config()->set('database.default', 'mysql');
    config()->set('database.connections.mysql', ['driver' => 'mysql']);
}

function fakeDoctorProcesses(array $probeOverrides = [], array $custom = []): void
{
    $probeOverrides['json'] = array_merge(['driver' => 'mysql'], $probeOverrides['json'] ?? []);

    Process::fake(function ($process) use ($probeOverrides, $custom) {
        $command = is_array($process->command) ? implode(' ', $process->command) : $process->command;

        foreach ($custom as $needle => $result) {
            if (str_contains($command, $needle)) {
                return $result;
            }
        }

        if (str_contains($command, 'BatchMode=yes')) {
            return Process::result();
        }

        if (str_contains($command, 'BASE=')) {
            return Process::result(output: syncProbeOutput($probeOverrides));
        }

        if (str_contains($command, 'command -v')) {
            return Process::result(output: '/usr/bin/tool');
        }

        return Process::result();
    });
}

beforeEach(function () {
    doctorTestConfig();
});

describe('remote-sync:doctor', function () {
    it('reports a healthy setup', function () {
        fakeDoctorProcesses(probeOverrides: ['atomic' => '1']);

        $this->artisan('remote-sync:doctor')
            ->expectsOutputToContain('Local environment')
            ->expectsOutputToContain('Remote [staging]')
            ->expectsOutputToContain('atomic deployment')
            ->expectsOutputToContain('Everything looks good.')
            ->assertSuccessful();
    });

    it('checks every configured remote when none is given', function () {
        config()->set('remote-sync.remotes', [
            'production' => ['host' => 'forge@prod.acme.test', 'path' => '/home/forge/acme'],
            'staging' => ['host' => 'forge@staging.acme.test', 'path' => '/home/forge/acme'],
        ]);
        fakeDoctorProcesses();

        $this->artisan('remote-sync:doctor')
            ->expectsOutputToContain('Remote [production]')
            ->expectsOutputToContain('Remote [staging]')
            ->assertSuccessful();
    });

    it('fails on placeholder configuration without throwing', function () {
        config()->set('remote-sync.remotes', [
            'staging' => ['host' => 'forge@your-server', 'path' => '/home/forge/acme'],
        ]);
        fakeDoctorProcesses();

        $this->artisan('remote-sync:doctor')
            ->expectsOutputToContain('REMOTE_SYNC_STAGING_HOST')
            ->expectsOutputToContain('Some checks failed')
            ->assertFailed();
    });

    it('flags an unknown host key with remediation', function () {
        fakeDoctorProcesses(custom: [
            'BatchMode=yes' => Process::result(exitCode: 255, errorOutput: 'Host key verification failed.'),
        ]);

        $this->artisan('remote-sync:doctor')
            ->expectsOutputToContain('connect once interactively')
            ->assertFailed();
    });

    it('flags a missing db-snapshots package on the remote', function () {
        fakeDoctorProcesses(probeOverrides: ['snapshots' => '0']);

        $this->artisan('remote-sync:doctor')
            ->expectsOutputToContain('not installed on the remote')
            ->assertFailed();
    });

    it('flags a database driver mismatch', function () {
        fakeDoctorProcesses(probeOverrides: ['json' => ['driver' => 'pgsql']]);

        $this->artisan('remote-sync:doctor')
            ->expectsOutputToContain('does not match local mysql')
            ->assertFailed();
    });

    it('suggests php_binary when detection fails', function () {
        fakeDoctorProcesses(probeOverrides: ['php' => '']);

        $this->artisan('remote-sync:doctor')
            ->expectsOutputToContain('php_binary')
            ->assertFailed();
    });

    it('flags an unsupported local driver', function () {
        config()->set('database.default', 'testing'); // sqlite

        fakeDoctorProcesses();

        $this->artisan('remote-sync:doctor')
            ->expectsOutputToContain('imports need mysql or pgsql')
            ->assertFailed();
    });
});
