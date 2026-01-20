<?php

use Noo\LaravelRemoteSync\Data\RemoteConfig;

describe('RemoteConfig', function () {
    describe('workingPath', function () {
        it('returns path as-is when isAtomic is null', function () {
            $config = new RemoteConfig(
                name: 'production',
                host: 'user@example.com',
                path: '/var/www/app',
            );

            expect($config->workingPath())->toBe('/var/www/app');
        });

        it('returns path as-is when isAtomic is false', function () {
            $config = new RemoteConfig(
                name: 'production',
                host: 'user@example.com',
                path: '/var/www/app',
                isAtomic: false,
            );

            expect($config->workingPath())->toBe('/var/www/app');
        });

        it('appends /current when isAtomic is true', function () {
            $config = new RemoteConfig(
                name: 'production',
                host: 'user@example.com',
                path: '/var/www/app',
                isAtomic: true,
            );

            expect($config->workingPath())->toBe('/var/www/app/current');
        });
    });

    describe('storagePath', function () {
        it('returns storage path based on workingPath when not atomic', function () {
            $config = new RemoteConfig(
                name: 'production',
                host: 'user@example.com',
                path: '/var/www/app',
                isAtomic: false,
            );

            expect($config->storagePath())->toBe('/var/www/app/storage');
        });

        it('returns storage path based on workingPath when atomic', function () {
            $config = new RemoteConfig(
                name: 'production',
                host: 'user@example.com',
                path: '/var/www/app',
                isAtomic: true,
            );

            expect($config->storagePath())->toBe('/var/www/app/current/storage');
        });
    });

    describe('withAtomicDetection', function () {
        it('returns new instance with updated isAtomic value', function () {
            $original = new RemoteConfig(
                name: 'production',
                host: 'user@example.com',
                path: '/var/www/app',
                pushAllowed: true,
            );

            $updated = $original->withAtomicDetection(true);

            expect($updated)->not->toBe($original);
            expect($updated->isAtomic)->toBeTrue();
            expect($updated->name)->toBe('production');
            expect($updated->host)->toBe('user@example.com');
            expect($updated->path)->toBe('/var/www/app');
            expect($updated->pushAllowed)->toBeTrue();
        });

        it('preserves all other properties when updating isAtomic', function () {
            $original = new RemoteConfig(
                name: 'staging',
                host: 'deploy@staging.example.com',
                path: '/home/deploy/app',
                pushAllowed: false,
                isAtomic: null,
            );

            $updated = $original->withAtomicDetection(false);

            expect($updated->name)->toBe('staging');
            expect($updated->host)->toBe('deploy@staging.example.com');
            expect($updated->path)->toBe('/home/deploy/app');
            expect($updated->pushAllowed)->toBeFalse();
            expect($updated->isAtomic)->toBeFalse();
        });
    });

    describe('constructor defaults', function () {
        it('has pushAllowed default to false', function () {
            $config = new RemoteConfig(
                name: 'production',
                host: 'user@example.com',
                path: '/var/www/app',
            );

            expect($config->pushAllowed)->toBeFalse();
        });

        it('has isAtomic default to null', function () {
            $config = new RemoteConfig(
                name: 'production',
                host: 'user@example.com',
                path: '/var/www/app',
            );

            expect($config->isAtomic)->toBeNull();
        });
    });
});
