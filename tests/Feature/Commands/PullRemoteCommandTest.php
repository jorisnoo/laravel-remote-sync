<?php

use Illuminate\Support\Facades\Process;

beforeEach(function () {
    Process::fake([
        '*' => Process::result(output: 'no'),
    ]);
});

describe('PullRemoteCommand', function () {
    it('warns and asks for confirmation in production environment', function () {
        app()->detectEnvironment(fn () => 'production');
        $this->setUpProductionRemote();

        $this->artisan('remote-sync:pull', ['remote' => 'production'])
            ->expectsOutputToContain('PRODUCTION environment')
            ->expectsConfirmation('Are you sure you want to continue in production?', 'no')
            ->assertSuccessful();
    });

    it('fails when no remotes are configured', function () {
        config()->set('remote-sync.remotes', []);

        $this->artisan('remote-sync:pull')
            ->assertFailed()
            ->expectsOutputToContain('No remote environment selected');
    });
});
