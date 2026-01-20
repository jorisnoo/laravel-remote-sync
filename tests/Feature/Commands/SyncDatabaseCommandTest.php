<?php

use Illuminate\Support\Facades\Process;

beforeEach(function () {
    Process::fake([
        '*' => Process::result(output: 'no'),
    ]);
});

describe('SyncDatabaseCommand', function () {
    it('refuses to run in production environment', function () {
        app()->detectEnvironment(fn () => 'production');
        $this->setUpProductionRemote();

        $this->artisan('remote-sync:pull-database', ['remote' => 'production'])
            ->assertFailed()
            ->expectsOutputToContain('This command cannot be run in production');
    });

    it('fails when remote is not configured', function () {
        config()->set('remote-sync.remotes', []);

        $this->artisan('remote-sync:pull-database', ['remote' => 'nonexistent'])
            ->assertFailed()
            ->expectsOutputToContain("Remote 'nonexistent' is not configured");
    });

    it('fails when remote is missing host', function () {
        config()->set('remote-sync.remotes', [
            'incomplete' => ['path' => '/var/www/app'],
        ]);

        $this->artisan('remote-sync:pull-database', ['remote' => 'incomplete'])
            ->assertFailed()
            ->expectsOutputToContain('missing host or path configuration');
    });

    it('warns when database driver cannot be detected but proceeds', function () {
        $this->setUpProductionRemote();

        Process::fake([
            '*' => Process::result(exitCode: 1, errorOutput: 'Failed'),
        ]);

        $this->artisan('remote-sync:pull-database', ['remote' => 'production'])
            ->expectsOutputToContain('Could not detect remote database driver')
            ->expectsConfirmation(
                'This will replace your local database with data from [production]. Continue?',
                'no'
            )
            ->assertSuccessful();
    });

    it('fails on database driver mismatch', function () {
        $this->setUpProductionRemote();
        config()->set('database.default', 'testing');
        config()->set('database.connections.testing.driver', 'sqlite');

        Process::fake([
            '*' => Process::result(output: 'mysql'),
        ]);

        $this->artisan('remote-sync:pull-database', ['remote' => 'production'])
            ->assertFailed()
            ->expectsOutputToContain('Database driver mismatch');
    });

    it('prompts for confirmation before syncing', function () {
        $this->setUpProductionRemote();
        config()->set('database.connections.testing.driver', 'mysql');

        Process::fake([
            '*' => Process::result(output: 'mysql'),
        ]);

        $this->artisan('remote-sync:pull-database', ['remote' => 'production'])
            ->expectsConfirmation(
                'This will replace your local database with data from [production]. Continue?',
                'no'
            )
            ->assertSuccessful();
    });

    it('cancels operation when user declines confirmation', function () {
        $this->setUpProductionRemote();
        config()->set('database.connections.testing.driver', 'mysql');

        Process::fake([
            '*' => Process::result(output: 'mysql'),
        ]);

        $this->artisan('remote-sync:pull-database', ['remote' => 'production'])
            ->expectsConfirmation(
                'This will replace your local database with data from [production]. Continue?',
                'no'
            )
            ->expectsOutputToContain('Operation cancelled')
            ->assertSuccessful();
    });

    it('treats mariadb and mysql as compatible drivers', function () {
        $this->setUpProductionRemote();
        config()->set('database.connections.testing.driver', 'mariadb');

        Process::fake([
            '*' => Process::result(output: 'mysql'),
        ]);

        $this->artisan('remote-sync:pull-database', ['remote' => 'production'])
            ->expectsConfirmation(
                'This will replace your local database with data from [production]. Continue?',
                'no'
            )
            ->assertSuccessful();
    });

    it('uses default remote when not specified', function () {
        $this->setUpProductionRemote();
        config()->set('database.connections.testing.driver', 'mysql');

        Process::fake([
            '*' => Process::result(output: 'mysql'),
        ]);

        $this->artisan('remote-sync:pull-database')
            ->expectsConfirmation(
                'This will replace your local database with data from [production]. Continue?',
                'no'
            )
            ->assertSuccessful();
    });

    it('detects atomic deployment path ending with /current', function () {
        config()->set('remote-sync.remotes', [
            'production' => [
                'host' => 'user@example.com',
                'path' => '/var/www/app/current',
            ],
        ]);
        config()->set('remote-sync.default', 'production');
        config()->set('database.connections.testing.driver', 'mysql');

        Process::fake([
            '*' => Process::result(output: 'mysql'),
        ]);

        $this->artisan('remote-sync:pull-database', ['remote' => 'production'])
            ->expectsConfirmation(
                'This will replace your local database with data from [production]. Continue?',
                'no'
            )
            ->assertSuccessful();

        Process::assertRan(function ($process) {
            return str_contains($process->command[2] ?? '', '/var/www/app/current');
        });
    });

});
