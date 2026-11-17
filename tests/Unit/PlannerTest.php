<?php

use Illuminate\Support\Facades\Process;
use Noo\LaravelRemoteSync\Database\Inspector;
use Noo\LaravelRemoteSync\Remotes\Connection;
use Noo\LaravelRemoteSync\Remotes\Remote;
use Noo\LaravelRemoteSync\Remotes\RemoteInfo;
use Noo\LaravelRemoteSync\Sync\Direction;
use Noo\LaravelRemoteSync\Sync\LocalEndpoint;
use Noo\LaravelRemoteSync\Sync\Planner;
use Noo\LaravelRemoteSync\Sync\RemoteEndpoint;
use Noo\LaravelRemoteSync\Sync\Rsync;

/**
 * @param  array<string, mixed>  $local
 * @param  array<string, mixed>  $remote
 */
function planner(Direction $direction, array $local = [], array $remote = []): Planner
{
    useLocalInfo($local);

    $localEndpoint = new LocalEndpoint(app(Inspector::class));
    $remoteEndpoint = new RemoteEndpoint(
        new Connection(new Remote(name: 'staging', host: 'forge@staging.acme.test', path: '/srv/acme')),
        new RemoteInfo('/srv/acme/current', 'php8.4', appInfo($remote)),
    );

    return $direction === Direction::Pull
        ? new Planner($remoteEndpoint, $localEndpoint, new Rsync)
        : new Planner($localEndpoint, $remoteEndpoint, new Rsync);
}

beforeEach(fn () => syncConfig());

describe('pull', function () {
    it('copies excluded tables as structure only and drops local-only tables', function () {
        $plan = planner(Direction::Pull, local: ['tables' => ['users', 'cache', 'scratch']])->database(Direction::Pull);

        expect($plan->replace)->toBe(['users', 'posts', 'migrations'])
            ->and($plan->empty)->toBe(['cache', 'jobs'])
            ->and($plan->drop)->toBe(['scratch'])
            ->and($plan->dumpOptions)->toBe(['--exclude-data=cache', '--exclude-data=jobs'])
            ->and($plan->fresh)->toBeTrue()
            ->and($plan->transfer)->toStartWith('remote-sync-')
            ->and($plan->backup)->toStartWith('pre-pull-');
    });

    it('never excludes the migrations table, even when configured', function () {
        $plan = planner(Direction::Pull)->database(Direction::Pull);

        expect($plan->replace)->toContain('migrations')->and($plan->empty)->not->toContain('migrations');
    });

    it('copies every row with --full', function () {
        $plan = planner(Direction::Pull)->database(Direction::Pull, full: true);

        expect($plan->replace)->toBe(['users', 'posts', 'cache', 'jobs', 'migrations'])
            ->and($plan->empty)->toBe([])
            ->and($plan->dumpOptions)->toBe([]);
    });

    it('warns about a missing backup and about filter_users', function () {
        config()->set('remote-sync.filter_users', ['*@acme.test']);

        $plan = planner(Direction::Pull)->database(Direction::Pull, backup: false);

        expect($plan->backup)->toBeNull()
            ->and($plan->filterUsers)->toBeTrue()
            ->and($plan->warnings)->toBe([
                'Without a backup, the current local database cannot be restored.',
                'Only users matching *@acme.test are kept.',
            ]);
    });
});

describe('push', function () {
    it('leaves excluded tables out of the dump and keeps the remote copies', function () {
        $plan = planner(Direction::Push, remote: ['tables' => ['users', 'cache', 'sessions_old']])->database(Direction::Push);

        expect($plan->replace)->toBe(['users', 'posts', 'migrations'])
            ->and($plan->preserve)->toBe(['cache'])
            ->and($plan->drop)->toBe([])
            ->and($plan->dumpOptions)->toBe(['--exclude=cache', '--exclude=jobs'])
            ->and($plan->fresh)->toBeFalse()
            ->and($plan->filterUsers)->toBeFalse()
            ->and($plan->backup)->toStartWith('pre-push-');
    });

    it('refuses when the remote has migrations this app does not', function () {
        $planner = planner(Direction::Push, remote: ['migrations' => ['0001_create_users_table', '0002_create_posts_table', '0003_add_flags']]);

        expect(fn () => $planner->database(Direction::Push))
            ->toThrow(RuntimeException::class, '[staging] has migrations this app does not: 0003_add_flags.');
    });

    it('warns about local migrations the remote has not run, and about production', function () {
        $plan = planner(
            Direction::Push,
            local: ['migrations' => ['0001_create_users_table', '0002_create_posts_table', '0003_add_flags']],
            remote: ['environment' => 'production'],
        )->database(Direction::Push);

        expect($plan->warnings)->toBe([
            'Migrations not yet run on [staging] will be recorded as run: 0003_add_flags.',
            '[staging] is a production environment.',
        ]);
    });
});

describe('files', function () {
    it('previews each path from source to target', function () {
        Process::fake(['*' => Process::result(output: ">f+++++++++ new.jpg\n*deleting   stale.jpg\n")]);

        $plan = planner(Direction::Pull)->files(['app', 'app/public'], delete: true);

        expect($plan->paths())->toBe(['app', 'app/public'])
            ->and($plan->transfers())->toBe(['new.jpg', 'new.jpg'])
            ->and($plan->deletions())->toBe(['stale.jpg', 'stale.jpg'])
            ->and($plan->delete)->toBeTrue();

        Process::assertRan(fn ($process) => array_slice($process->command, -2) === [
            'forge@staging.acme.test:/srv/acme/releases/7/storage/app/public/',
            storage_path('app/public').'/',
        ]);
    });

    it('names the path when a preview fails', function () {
        Process::fake(['*' => Process::result(exitCode: 23, errorOutput: 'No such file or directory')]);

        expect(fn () => planner(Direction::Push)->files(['app']))
            ->toThrow(RuntimeException::class, 'Could not compare storage/app: No such file or directory');
    });
});
