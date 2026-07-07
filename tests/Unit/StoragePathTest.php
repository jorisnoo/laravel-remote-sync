<?php

use Noo\LaravelRemoteSync\Support\StoragePath;

describe('StoragePath', function () {
    it('accepts ordinary storage-relative paths', function (string $path) {
        expect(StoragePath::validate($path))->toBeNull();
    })->with([
        'app',
        'app/public',
        'app/public/uploads',
        'framework/cache-x_1.log',
        'app/2024-uploads',
    ]);

    it('rejects traversal, absolute paths, and shell metacharacters', function (string $path) {
        expect(StoragePath::validate($path))->not->toBeNull();
    })->with([
        '../etc',
        'app/../secrets',
        '/etc/passwd',
        'app;rm -rf /',
        'app$(whoami)',
        'app`id`',
        'app with spaces',
        "app'quote",
        'app//double',
        '.hidden',
        '',
        'app&&x',
    ]);

    it('normalizes surrounding slashes', function () {
        expect(StoragePath::normalize('/app/public/'))->toBe('app/public');
    });
});
