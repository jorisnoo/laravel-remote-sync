<?php

use Illuminate\Support\Facades\Process;

beforeEach(function () {
    Process::fake([
        '*' => Process::result(output: 'no'),
    ]);
});

describe('PushRemoteCommand', function () {
    it('refuses to run in production environment', function () {
        app()->detectEnvironment(fn () => 'production');
        $this->setUpStagingRemote();

        $this->artisan('remote-sync:push', ['remote' => 'staging'])
            ->assertFailed()
            ->expectsOutputToContain('This command cannot be run in production');
    });

    it('fails when no remotes are configured', function () {
        config()->set('remote-sync.remotes', []);

        $this->artisan('remote-sync:push')
            ->assertFailed()
            ->expectsOutputToContain('No remote environment selected');
    });

    it('fails when push is not allowed', function () {
        $this->setUpProductionRemote();

        $this->artisan('remote-sync:push', ['remote' => 'production'])
            ->assertFailed()
            ->expectsOutputToContain("Push is not allowed for remote [production]");
    });
});
