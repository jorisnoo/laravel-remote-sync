<?php

use Illuminate\Support\Facades\Process;

beforeEach(function () {
    Process::fake([
        '*' => Process::result(output: 'no'),
    ]);
});

describe('SyncRemoteCommand', function () {
    it('refuses to run in production environment', function () {
        app()->detectEnvironment(fn () => 'production');
        $this->setUpProductionRemote();

        $this->artisan('remote-sync:pull', ['remote' => 'production'])
            ->assertFailed()
            ->expectsOutputToContain('This command cannot be run in production');
    });

    it('fails when no remotes are configured', function () {
        config()->set('remote-sync.remotes', []);

        $this->artisan('remote-sync:pull')
            ->assertFailed()
            ->expectsOutputToContain('No remote environment selected');
    });
});
