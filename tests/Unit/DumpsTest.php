<?php

use Noo\LaravelRemoteSync\Database\Dumps;

afterEach(function () {
    foreach (glob(storage_path('remote-sync/*.sql.gz')) ?: [] as $file) {
        unlink($file);
    }
});

describe('Dumps', function () {
    it('names transfer dumps and backups', function () {
        expect(Dumps::transferName())->toMatch('/^remote-sync-\d{4}-\d{2}-\d{2}-\d{6}-[0-9a-f]{6}$/')
            ->and(Dumps::backupName('pull'))->toMatch('/^pre-pull-\d{4}-\d{2}-\d{2}-\d{6}$/');
    });

    it('derives the kind from the name', function () {
        expect(Dumps::kind(Dumps::transferName()))->toBe('remote-sync')
            ->and(Dumps::kind(Dumps::backupName('push')))->toBe('pre-push');
    });

    it('only accepts plain names, so a name cannot leave the directory', function () {
        expect(Dumps::relativePath('pre-pull-2026-01-01-120000'))->toBe('remote-sync/pre-pull-2026-01-01-120000.sql.gz')
            ->and(fn () => Dumps::relativePath('../../.env'))->toThrow(InvalidArgumentException::class);
    });

    it('creates the directory with a .gitignore', function () {
        $directory = Dumps::ensureDirectory();

        expect(file_get_contents("{$directory}/.gitignore"))->toBe("*\n!.gitignore\n");
    });

    it('lists dumps newest first', function () {
        Dumps::ensureDirectory();
        touch(Dumps::path('pre-pull-old'), 1000);
        touch(Dumps::path('pre-pull-new'), 2000);

        expect(Dumps::list())->toBe([
            ['name' => 'pre-pull-new', 'mtime' => 2000],
            ['name' => 'pre-pull-old', 'mtime' => 1000],
        ]);
    });
});
