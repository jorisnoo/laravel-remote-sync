<?php

use Illuminate\Support\Facades\Process;
use Noo\LaravelRemoteSync\Sync\FileChanges;
use Noo\LaravelRemoteSync\Sync\Rsync;

describe('Rsync', function () {
    beforeEach(fn () => config()->set('remote-sync.exclude_paths', ['cache', '*.log']));

    it('copies a single file without excludes', function () {
        Process::fake();

        (new Rsync)->copy('host:/a/dump.sql.gz', '/b/dump.sql.gz');

        Process::assertRan(fn ($process) => $process->command === [
            'rsync', '-e', 'ssh -o BatchMode=yes -o ConnectTimeout=10', '-az', '--partial', 'host:/a/dump.sql.gz', '/b/dump.sql.gz',
        ]);
    });

    it('syncs directories without dotfiles or excluded paths, deleting only when asked', function () {
        Process::fake();

        (new Rsync)->sync('host:/a/', '/b/', delete: false);
        (new Rsync)->sync('/b/', 'host:/a/', delete: true);

        Process::assertRan(fn ($process) => $process->command === [
            'rsync', '-e', 'ssh -o BatchMode=yes -o ConnectTimeout=10', '-az', '--partial',
            '--exclude=.*', '--exclude=cache', '--exclude=*.log', 'host:/a/', '/b/',
        ]);
        Process::assertRan(fn ($process) => in_array('--delete', $process->command, true) && end($process->command) === 'host:/a/');
    });

    it('shows progress when interactive', function () {
        Process::fake();

        (new Rsync(interactive: true))->sync('host:/a/', '/b/', delete: false);

        Process::assertRan(fn ($process) => in_array('--info=progress2', $process->command, true));
    });

    it('previews with --delete so the plan can count deletable files', function () {
        Process::fake(['*' => Process::result(output: ">f+++++++++ a.jpg\n*deleting   b.jpg\n")]);

        $changes = (new Rsync)->preview('host:/a/', '/b/');

        expect($changes->transfers)->toBe(['a.jpg'])->and($changes->deletions)->toBe(['b.jpg']);
        Process::assertRan(fn ($process) => in_array('--dry-run', $process->command, true) && in_array('--delete', $process->command, true));
    });

    it('throws when a preview fails', function () {
        Process::fake(['*' => Process::result(exitCode: 23, errorOutput: 'change_dir "/a" failed: No such file or directory')]);

        expect(fn () => (new Rsync)->preview('host:/a/', '/b/'))->toThrow(RuntimeException::class, 'No such file or directory');
    });
});

describe('FileChanges', function () {
    it('parses itemized output', function () {
        $changes = FileChanges::fromItemized(implode("\n", [
            'receiving incremental file list',
            'created directory /b',
            '>f+++++++++ uploads/a.jpg',
            '>f.st...... uploads/with space.jpg',
            'cd+++++++++ uploads/nested/',
            'cL+++++++++ uploads/link.jpg -> a.jpg',
            '*deleting   old/gone.jpg',
            '',
            'sent 123 bytes  received 456 bytes',
            'total size is 0  speedup is 0.00 (DRY RUN)',
        ]));

        expect($changes->transfers)->toBe(['uploads/a.jpg', 'uploads/with space.jpg', 'uploads/link.jpg'])
            ->and($changes->deletions)->toBe(['old/gone.jpg']);
    });
});
