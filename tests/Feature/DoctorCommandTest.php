<?php

use Illuminate\Support\Facades\Process;

beforeEach(function () {
    syncConfig();
    useLocalInfo();
});

describe('remote-sync:doctor', function () {
    it('passes when everything is in place', function () {
        fakeProcesses();

        $this->artisan('remote-sync:doctor')
            ->expectsOutputToContain('Remote [staging]')
            ->expectsOutputToContain('/srv/acme/current (staging)')
            ->expectsOutputToContain('protocol 1')
            ->expectsOutputToContain('Everything looks good.')
            ->assertSuccessful();
    });

    it('reports a driver mismatch and missing tools', function () {
        fakeProcesses(remote: ['driver' => 'pgsql', 'missingBinaries' => ['pg_dump']]);

        $this->artisan('remote-sync:doctor', ['remote' => 'staging'])
            ->expectsOutputToContain('pgsql does not match the local mysql')
            ->expectsOutputToContain('missing: pg_dump')
            ->assertFailed();
    });

    it('reports probe failures', function () {
        fakeProcesses(custom: ['WORK=' => Process::result(output: "WORK=/srv/acme\nPHP=php\nRSYNC=0")]);

        $this->artisan('remote-sync:doctor')
            ->expectsOutputToContain('rsync is not installed on [staging]')
            ->assertFailed();
    });

    it('reports SSH problems', function (string $error, string $message) {
        fakeProcesses(custom: ['forge@staging.acme.test exit' => Process::result(exitCode: 255, errorOutput: $error)]);

        $this->artisan('remote-sync:doctor')->expectsOutputToContain($message)->assertFailed();
    })->with([
        ['Host key verification failed.', 'unknown host key'],
        ['Permission denied (publickey).', 'key authentication failed'],
    ]);

    it('reports configuration errors', function () {
        config()->set('remote-sync.remotes.staging.host', null);
        fakeProcesses();

        $this->artisan('remote-sync:doctor')
            ->expectsOutputToContain('REMOTE_SYNC_STAGING_HOST')
            ->assertFailed();
    });
});
