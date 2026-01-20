<?php

use Illuminate\Support\Facades\Process;

beforeEach(function () {
    Process::fake([
        '*' => Process::result(output: 'no'),
    ]);
});

describe('PushDatabaseCommand', function () {
    it('refuses to run in production environment', function () {
        app()->detectEnvironment(fn () => 'production');
        $this->setUpStagingRemote();

        $this->artisan('remote-sync:push-database', ['remote' => 'staging'])
            ->assertFailed()
            ->expectsOutputToContain('This command cannot be run in production');
    });

    it('fails when remote is not configured', function () {
        config()->set('remote-sync.remotes', []);

        $this->artisan('remote-sync:push-database', ['remote' => 'nonexistent'])
            ->assertFailed()
            ->expectsOutputToContain("Remote 'nonexistent' is not configured");
    });

    it('fails when push is not allowed for remote', function () {
        $this->setUpProductionRemote();

        $this->artisan('remote-sync:push-database', ['remote' => 'production'])
            ->assertFailed()
            ->expectsOutputToContain('Push is not allowed for remote [production]');
    });

    it('requires push_allowed to be true', function () {
        config()->set('remote-sync.remotes', [
            'staging' => [
                'host' => 'user@staging.example.com',
                'path' => '/var/www/app',
                'push_allowed' => false,
            ],
        ]);

        $this->artisan('remote-sync:push-database', ['remote' => 'staging'])
            ->assertFailed()
            ->expectsOutputToContain('Push is not allowed for remote [staging]');
    });

    it('warns when database driver cannot be detected but proceeds', function () {
        $this->setUpStagingRemote();

        Process::fake([
            '*' => Process::result(exitCode: 1, errorOutput: 'Failed'),
        ]);

        $this->artisan('remote-sync:push-database', ['remote' => 'staging'])
            ->expectsOutputToContain('Could not detect remote database driver')
            ->expectsConfirmation(
                'You are about to push local database to [staging]. This will OVERWRITE remote data. Continue?',
                'no'
            )
            ->assertSuccessful();
    });

    it('fails on database driver mismatch', function () {
        $this->setUpStagingRemote();
        config()->set('database.default', 'testing');
        config()->set('database.connections.testing.driver', 'sqlite');

        Process::fake([
            '*' => Process::result(output: 'mysql'),
        ]);

        $this->artisan('remote-sync:push-database', ['remote' => 'staging'])
            ->assertFailed()
            ->expectsOutputToContain('Database driver mismatch');
    });

    it('requires double confirmation before push', function () {
        $this->setUpStagingRemote();
        config()->set('database.connections.testing.driver', 'mysql');

        Process::fake([
            '*' => Process::result(output: 'mysql'),
        ]);

        $this->artisan('remote-sync:push-database', ['remote' => 'staging'])
            ->expectsConfirmation(
                'You are about to push local database to [staging]. This will OVERWRITE remote data. Continue?',
                'yes'
            )
            ->expectsConfirmation(
                'Are you SURE you want to push to [staging]? This action cannot be undone.',
                'no'
            )
            ->expectsOutputToContain('Operation cancelled')
            ->assertSuccessful();
    });

    it('cancels on first confirmation decline', function () {
        $this->setUpStagingRemote();
        config()->set('database.connections.testing.driver', 'mysql');

        Process::fake([
            '*' => Process::result(output: 'mysql'),
        ]);

        $this->artisan('remote-sync:push-database', ['remote' => 'staging'])
            ->expectsConfirmation(
                'You are about to push local database to [staging]. This will OVERWRITE remote data. Continue?',
                'no'
            )
            ->expectsOutputToContain('Operation cancelled')
            ->assertSuccessful();
    });

    it('treats mariadb and mysql as compatible drivers', function () {
        $this->setUpStagingRemote();
        config()->set('database.connections.testing.driver', 'mariadb');

        Process::fake([
            '*' => Process::result(output: 'mysql'),
        ]);

        $this->artisan('remote-sync:push-database', ['remote' => 'staging'])
            ->expectsConfirmation(
                'You are about to push local database to [staging]. This will OVERWRITE remote data. Continue?',
                'no'
            )
            ->assertSuccessful();
    });

    it('uses default remote when not specified', function () {
        config()->set('remote-sync.remotes', [
            'staging' => [
                'host' => 'user@staging.example.com',
                'path' => '/var/www/app',
                'push_allowed' => true,
            ],
        ]);
        config()->set('remote-sync.default', 'staging');
        config()->set('database.connections.testing.driver', 'mysql');

        Process::fake([
            '*' => Process::result(output: 'mysql'),
        ]);

        $this->artisan('remote-sync:push-database')
            ->expectsConfirmation(
                'You are about to push local database to [staging]. This will OVERWRITE remote data. Continue?',
                'no'
            )
            ->assertSuccessful();
    });

    it('detects atomic deployment and uses correct path', function () {
        config()->set('remote-sync.remotes', [
            'staging' => [
                'host' => 'user@staging.example.com',
                'path' => '/var/www/app/current',
                'push_allowed' => true,
            ],
        ]);
        config()->set('remote-sync.default', 'staging');
        config()->set('database.connections.testing.driver', 'mysql');

        Process::fake([
            '*' => Process::result(output: 'mysql'),
        ]);

        $this->artisan('remote-sync:push-database', ['remote' => 'staging'])
            ->expectsConfirmation(
                'You are about to push local database to [staging]. This will OVERWRITE remote data. Continue?',
                'no'
            )
            ->assertSuccessful();

        Process::assertRan(function ($process) {
            return str_contains($process->command[2] ?? '', '/var/www/app/current');
        });
    });
});
