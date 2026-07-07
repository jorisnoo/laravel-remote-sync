<?php

use Illuminate\Support\Facades\Process;
use Noo\LaravelRemoteSync\Remotes\Connection;
use Noo\LaravelRemoteSync\Remotes\Remote;
use Noo\LaravelRemoteSync\Sync\Direction;
use Noo\LaravelRemoteSync\Sync\Rsync;

function rsyncFixture(bool $interactive = false): Rsync
{
    $connection = new Connection(new Remote(
        name: 'production',
        host: 'forge@prod.acme.test',
        path: '/home/forge/acme',
    ));

    return new Rsync($connection, interactive: $interactive);
}

function itemizedOutput(): string
{
    return implode("\n", [
        'receiving incremental file list',
        '>f+++++++++ uploads/a.jpg',
        '>f.st...... uploads/b.jpg',
        'cd+++++++++ uploads/nested/',
        '*deleting   old/gone.jpg',
        '',
        'sent 123 bytes  received 456 bytes',
    ]);
}

describe('Rsync', function () {
    describe('transfer', function () {
        it('pulls from the remote to the local path', function () {
            Process::fake();

            rsyncFixture()->transfer(Direction::Pull, '/remote/storage/app/', '/local/storage/app/');

            Process::assertRan(fn ($process) => $process->command === [
                'rsync', '-avz', '--partial', '--exclude=.*',
                'forge@prod.acme.test:/remote/storage/app/',
                '/local/storage/app/',
            ]);
        });

        it('pushes from the local path to the remote', function () {
            Process::fake();

            rsyncFixture()->transfer(Direction::Push, '/remote/storage/app/', '/local/storage/app/');

            Process::assertRan(fn ($process) => $process->command === [
                'rsync', '-avz', '--partial', '--exclude=.*',
                '/local/storage/app/',
                'forge@prod.acme.test:/remote/storage/app/',
            ]);
        });

        it('only mirrors deletions when asked to', function () {
            Process::fake();

            rsyncFixture()->transfer(Direction::Pull, '/r/', '/l/', delete: true);

            Process::assertRan(fn ($process) => in_array('--delete', $process->command, true));
        });

        it('merges configured and per-transfer excludes without duplicates', function () {
            Process::fake();
            config()->set('remote-sync.exclude_paths', ['*.log', 'tmp']);

            rsyncFixture()->transfer(Direction::Pull, '/r/', '/l/', excludes: ['snapshots', 'tmp']);

            Process::assertRan(fn ($process) => array_values(array_filter(
                $process->command,
                fn ($arg) => str_starts_with($arg, '--exclude=')
            )) === ['--exclude=.*', '--exclude=*.log', '--exclude=tmp', '--exclude=snapshots']);
        });

        it('shows live progress only in interactive mode', function () {
            Process::fake();

            rsyncFixture(interactive: true)->transfer(Direction::Pull, '/r/', '/l/');

            Process::assertRan(fn ($process) => in_array('--info=progress2', $process->command, true));
        });
    });

    describe('dryRun', function () {
        it('runs rsync with dry-run and itemize flags and parses the result', function () {
            Process::fake(['*' => Process::result(output: itemizedOutput())]);

            $changes = rsyncFixture()->dryRun(Direction::Pull, '/r/', '/l/', delete: true);

            Process::assertRan(fn ($process) => in_array('--dry-run', $process->command, true)
                && in_array('--itemize-changes', $process->command, true)
                && ! in_array('--partial', $process->command, true));

            expect($changes->transfers)->toBe(['uploads/a.jpg', 'uploads/b.jpg'])
                ->and($changes->deletions)->toBe(['old/gone.jpg'])
                ->and($changes->transferCount())->toBe(2)
                ->and($changes->deletionCount())->toBe(1);
        });

        it('throws with the rsync error when the dry run fails', function () {
            Process::fake(['*' => Process::result(exitCode: 23, errorOutput: 'rsync: link_stat failed')]);

            expect(fn () => rsyncFixture()->dryRun(Direction::Pull, '/r/', '/l/'))
                ->toThrow(RuntimeException::class, 'rsync: link_stat failed');
        });
    });

    describe('parse', function () {
        it('ignores directories and summary lines', function () {
            $changes = rsyncFixture()->parse(itemizedOutput());

            expect($changes->transfers)->not->toContain('uploads/nested/')
                ->and($changes->transferCount())->toBe(2);
        });

        it('returns empty changes for empty output', function () {
            $changes = rsyncFixture()->parse('');

            expect($changes->transferCount())->toBe(0)
                ->and($changes->deletionCount())->toBe(0);
        });
    });
});
