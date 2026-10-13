<?php

use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Schema;
use Noo\LaravelRemoteSync\Database\Dumps;

/*
| Pull and push are one command running the same steps in opposite
| directions. Local artisan calls run as "<php> artisan ..."; remote ones
| as "ssh ... cd '/srv/acme/current' && 'php8.4' 'artisan' ...".
*/

const LOCAL = PHP_BINARY.' artisan';
const REMOTE = "cd '/srv/acme/current' && 'php8.4' 'artisan'";

beforeEach(function () {
    syncConfig();
    useLocalInfo();
});

afterEach(function () {
    foreach (glob(storage_path('remote-sync/*.sql.gz')) ?: [] as $file) {
        unlink($file);
    }
});

describe('pull', function () {
    it('backs up, dumps, transfers, loads, migrates, clears the cache and syncs files, in that order', function () {
        fakeProcesses();

        $this->artisan('remote-sync:pull', ['--force' => true])
            ->expectsOutputToContain('Pulled the database and files from [staging].')
            ->assertSuccessful();

        $order = [
            ranAt(LOCAL, 'remote-sync:dump pre-pull-'),
            ranAt(REMOTE, "'remote-sync:dump' 'remote-sync-", "'--exclude-data=cache' '--exclude-data=jobs'"),
            ranAt('rsync', '-az --partial forge@staging.acme.test:/srv/acme/releases/7/storage/remote-sync/remote-sync-', storage_path('remote-sync/remote-sync-')),
            ranAt(LOCAL, 'remote-sync:load remote-sync-', '--force --fresh'),
            ranAt(LOCAL, 'migrate --force'),
            ranAt(LOCAL, 'cache:clear'),
            ranAt('rsync', '--partial --exclude=.*', 'forge@staging.acme.test:/srv/acme/releases/7/storage/app/ '.storage_path('app').'/'),
            ranAt('ssh', "rm -f '/srv/acme/releases/7/storage/remote-sync/remote-sync-"),
        ];

        expect($order)->not->toContain(-1)->and($order)->toBe(array_values(collect($order)->sort()->all()));
    });

    it('never deletes files without --delete, even with --force', function () {
        fakeProcesses();

        $this->artisan('remote-sync:pull', ['--files' => true, '--force' => true])
            ->expectsOutputToContain('--delete would remove 1 files only on local')
            ->assertSuccessful();

        expect(ranAt('rsync', '--partial', '--exclude=.*'))->toBeGreaterThan(-1)
            ->and(ranAt('rsync', '--partial', '--exclude=.*', '--delete'))->toBe(-1);
    });

    it('deletes local-only files with --delete', function () {
        fakeProcesses();

        $this->artisan('remote-sync:pull', ['--files' => true, '--delete' => true, '--force' => true])->assertSuccessful();

        expect(ranAt('rsync', '--partial', '--exclude=.*', '--delete'))->toBeGreaterThan(-1);
    });

    it('copies every row with --full and skips the backup with --no-backup', function () {
        fakeProcesses();

        $this->artisan('remote-sync:pull', ['--database' => true, '--full' => true, '--no-backup' => true, '--force' => true])
            ->expectsOutputToContain('the current local database cannot be restored')
            ->assertSuccessful();

        expect(ranAt(LOCAL, 'remote-sync:dump pre-pull-'))->toBe(-1)
            ->and(ranAt(REMOTE, "'remote-sync:dump'", '--exclude'))->toBe(-1)
            ->and(ranAt(REMOTE, "'remote-sync:dump' 'remote-sync-"))->toBeGreaterThan(-1);
    });

    it('keeps the dump and explains how to restore when the load fails', function () {
        fakeProcesses(custom: ['remote-sync:load' => Process::result(exitCode: 1, output: 'ERROR 1064 at line 12')]);

        $this->artisan('remote-sync:pull', ['--database' => true, '--force' => true])
            ->expectsOutputToContain('ERROR 1064 at line 12')
            ->expectsOutputToContain('Restore the previous local database with `php artisan remote-sync:load pre-pull-')
            ->expectsOutputToContain('The dump was kept for inspection: '.storage_path('remote-sync/remote-sync-'))
            ->assertFailed();

        // The remote's temporary dump is still removed; nothing runs after the failed step.
        expect(ranAt('ssh', 'rm -f'))->toBeGreaterThan(-1)
            ->and(ranAt(LOCAL, 'migrate'))->toBe(-1);
    });

    it('keeps the transferred dump with --keep-dump', function () {
        fakeProcesses(custom: ['-az --partial forge@' => function (string $command) {
            Dumps::ensureDirectory();
            touch(explode(' ', $command)[count(explode(' ', $command)) - 1]);

            return Process::result();
        }]);

        $this->artisan('remote-sync:pull', ['--database' => true, '--keep-dump' => true, '--force' => true])->assertSuccessful();

        expect(Dumps::list())->toHaveCount(1)->and(Dumps::list()[0]['name'])->toStartWith('remote-sync-');
    });

    it('fails when migrations fail', function () {
        fakeProcesses(custom: ['migrate --force' => Process::result(exitCode: 1, errorOutput: 'SQLSTATE[42S21]: Duplicate column')]);

        $this->artisan('remote-sync:pull', ['--database' => true, '--force' => true])
            ->expectsOutputToContain('Duplicate column')
            ->assertFailed();
    });

    it('applies filter_users after loading', function () {
        config()->set('remote-sync.filter_users', ['*@acme.test']);
        Schema::create('users', function ($table) {
            $table->id();
            $table->string('email');
        });
        DB::table('users')->insert([['email' => 'jane@acme.test'], ['email' => 'joe@elsewhere.test']]);
        fakeProcesses();

        $this->artisan('remote-sync:pull', ['--database' => true, '--force' => true])->assertSuccessful();

        expect(DB::table('users')->pluck('email')->all())->toBe(['jane@acme.test']);
    });

    it('drops local-only tables, and says so in the plan', function () {
        useLocalInfo(['tables' => ['users', 'scratch']]);
        fakeProcesses();

        $this->artisan('remote-sync:pull', ['--database' => true, '--dry-run' => true])
            ->expectsOutputToContain('Drop')
            ->expectsOutputToContain('scratch')
            ->assertSuccessful();
    });

    it('asks for the scope and one confirmation when interactive', function () {
        fakeProcesses();

        $this->artisan('remote-sync:pull')
            ->expectsChoice('What do you want to pull?', ['database'], ['database' => 'Database', 'files' => 'Storage files'])
            ->expectsConfirmation('Pull the database from [staging]?', 'yes')
            ->expectsOutputToContain('Pulled the database from [staging].')
            ->assertSuccessful();
    });

    it('refuses to run in production unless allowed', function () {
        $this->app['env'] = 'production';

        $this->artisan('remote-sync:pull', ['--force' => true])
            ->expectsOutputToContain('Refusing to run in production')
            ->assertFailed();

        $this->app['env'] = 'testing';
    });
});

describe('push', function () {
    it('backs up the remote, dumps locally without excluded tables, uploads, loads without --fresh, migrates and clears the cache', function () {
        fakeProcesses();

        $this->artisan('remote-sync:push', ['--database' => true, '--files' => true, '--force' => true])
            ->expectsOutputToContain('Pushed the database and files to [staging].')
            ->assertSuccessful();

        $order = [
            ranAt(REMOTE, "'remote-sync:dump' 'pre-push-"),
            ranAt(LOCAL, 'remote-sync:dump remote-sync-', '--exclude=cache --exclude=jobs'),
            ranAt('ssh', "mkdir -p '/srv/acme/releases/7/storage/remote-sync'"),
            ranAt('rsync', storage_path('remote-sync/remote-sync-'), 'forge@staging.acme.test:/srv/acme/releases/7/storage/remote-sync/remote-sync-'),
            ranAt(REMOTE, "'remote-sync:load' 'remote-sync-", "'--force'"),
            ranAt(REMOTE, "'migrate' '--force'"),
            ranAt(REMOTE, "'cache:clear'"),
            ranAt('rsync', '--partial --exclude=.*', storage_path('app').'/ forge@staging.acme.test:/srv/acme/releases/7/storage/app/'),
        ];

        expect($order)->not->toContain(-1)
            ->and($order)->toBe(array_values(collect($order)->sort()->all()))
            ->and(ranAt(REMOTE, "'remote-sync:load'", '--fresh'))->toBe(-1);
    });

    it('requires push to be enabled for the remote', function () {
        syncConfig(push: false);
        fakeProcesses();

        $this->artisan('remote-sync:push', ['--database' => true, '--force' => true])
            ->expectsOutputToContain("Pushing to [staging] is not allowed. Set 'push' => true")
            ->assertFailed();

        expect(ranCommands())->toBe([]);
    });

    it('requires an explicit scope when not interactive', function () {
        fakeProcesses();

        $this->artisan('remote-sync:push', ['--force' => true, '--no-interaction' => true])
            ->expectsOutputToContain('Pass --database and/or --files')
            ->assertFailed();
    });

    it('refuses when the remote has migrations this app does not', function () {
        fakeProcesses(remote: ['migrations' => ['0001_create_users_table', '0002_create_posts_table', '0003_add_flags']]);

        $this->artisan('remote-sync:push', ['--database' => true, '--force' => true])
            ->expectsOutputToContain('[staging] has migrations this app does not: 0003_add_flags.')
            ->assertFailed();
    });

    it('explains how to restore on the server when the remote load fails', function () {
        fakeProcesses(custom: ["'remote-sync:load'" => Process::result(exitCode: 1, errorOutput: 'ERROR: relation "users" does not exist')]);

        $this->artisan('remote-sync:push', ['--database' => true, '--force' => true])
            ->expectsOutputToContain('relation "users" does not exist')
            ->expectsOutputToContain('Restore the previous [staging] database by running `php artisan remote-sync:load pre-push-')
            ->assertFailed();
    });

    it('asks for a typed yes when interactive', function () {
        fakeProcesses();

        $this->artisan('remote-sync:push')
            ->expectsChoice('What do you want to push?', ['database'], ['database' => 'Database', 'files' => 'Storage files'])
            ->expectsQuestion('Push the database to [staging]? Type "yes" to continue', 'no')
            ->expectsOutputToContain('Cancelled.')
            ->assertSuccessful();

        expect(ranAt(REMOTE, "'remote-sync:load'"))->toBe(-1);
    });
});

describe('both directions', function () {
    it('prints the plan and changes nothing with --dry-run', function (string $command) {
        fakeProcesses();

        $this->artisan($command, ['--database' => true, '--files' => true, '--dry-run' => true])
            ->expectsOutputToContain('Replace')
            ->expectsOutputToContain('Dry run: nothing was changed.')
            ->assertSuccessful();

        expect(collect(ranCommands())->filter(fn ($c) => str_contains($c, 'artisan') && ! str_contains($c, 'WORK=')))->toBeEmpty();
    })->with(['remote-sync:pull', 'remote-sync:push']);

    it('prints the plan and stops without --force when not interactive', function (string $command) {
        fakeProcesses();

        $this->artisan($command, ['--database' => true, '--no-interaction' => true])
            ->expectsOutputToContain('Confirmation required: re-run with --force.')
            ->assertFailed();
    })->with(['remote-sync:pull', 'remote-sync:push']);

    it('refuses databases that cannot be synced', function (array $local, array $remote, string $message) {
        useLocalInfo($local);
        fakeProcesses(remote: $remote);

        $this->artisan('remote-sync:pull', ['--database' => true, '--force' => true])
            ->expectsOutputToContain($message)
            ->assertFailed();
    })->with([
        'unsupported local driver' => [['driver' => 'sqlite'], [], 'The local database uses sqlite; only mysql and pgsql can be synced.'],
        'driver mismatch' => [[], ['driver' => 'pgsql'], 'The [staging] database uses pgsql and the local one mysql'],
        'missing tools' => [[], ['missingBinaries' => ['mysqldump']], 'Not installed on [staging]: mysqldump.'],
    ]);

    it('syncs files without any database tools', function () {
        useLocalInfo(['driver' => 'sqlite']);
        fakeProcesses();

        $this->artisan('remote-sync:pull', ['--files' => true, '--force' => true])->assertSuccessful();
    });

    it('rejects unsafe paths', function () {
        fakeProcesses();

        $this->artisan('remote-sync:pull', ['--files' => true, '--path' => ['../etc'], '--force' => true])
            ->expectsOutputToContain("Invalid path '../etc'")
            ->assertFailed();
    });

    it('stops at an unknown host key when not interactive', function () {
        fakeProcesses(custom: ['forge@staging.acme.test exit' => Process::result(exitCode: 255, errorOutput: 'Host key verification failed.')]);

        $this->artisan('remote-sync:pull', ['--force' => true, '--no-interaction' => true])
            ->expectsOutputToContain('staging.acme.test is not a known host')
            ->assertFailed();
    });

    it('points at doctor when the remote cannot be probed', function () {
        fakeProcesses(custom: ['WORK=' => Process::result(output: 'REMOTE_SYNC_NO_PATH', exitCode: 1)]);

        $this->artisan('remote-sync:pull', ['--force' => true])
            ->expectsOutputToContain("Cannot access '/srv/acme' on [staging]")
            ->expectsOutputToContain('remote-sync:doctor staging')
            ->assertFailed();
    });
});
