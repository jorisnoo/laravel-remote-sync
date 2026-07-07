<?php

use Noo\LaravelRemoteSync\Remotes\Remote;
use Noo\LaravelRemoteSync\Remotes\RemoteRegistry;

beforeEach(function () {
    $this->registry = new RemoteRegistry;
});

describe('RemoteRegistry', function () {
    describe('get', function () {
        it('returns a Remote for a valid configuration', function () {
            config()->set('remote-sync.remotes', [
                'production' => [
                    'host' => 'forge@prod.acme.test',
                    'path' => '/home/forge/acme',
                ],
            ]);

            $remote = $this->registry->get('production');

            expect($remote)->toBeInstanceOf(Remote::class)
                ->and($remote->name)->toBe('production')
                ->and($remote->host)->toBe('forge@prod.acme.test')
                ->and($remote->path)->toBe('/home/forge/acme')
                ->and($remote->push)->toBeFalse()
                ->and($remote->phpBinary)->toBeNull();
        });

        it('reads push and php_binary when configured', function () {
            config()->set('remote-sync.remotes', [
                'staging' => [
                    'host' => 'forge@staging.acme.test',
                    'path' => '/home/forge/staging',
                    'push' => true,
                    'php_binary' => '/usr/bin/php8.4',
                ],
            ]);

            $remote = $this->registry->get('staging');

            expect($remote->push)->toBeTrue()
                ->and($remote->phpBinary)->toBe('/usr/bin/php8.4');
        });

        it('treats a blank php_binary as not configured', function () {
            config()->set('remote-sync.remotes', [
                'staging' => [
                    'host' => 'forge@staging.acme.test',
                    'path' => '/home/forge/staging',
                    'php_binary' => '  ',
                ],
            ]);

            expect($this->registry->get('staging')->phpBinary)->toBeNull();
        });

        it('trims a trailing slash from the path', function () {
            config()->set('remote-sync.remotes', [
                'production' => [
                    'host' => 'forge@prod.acme.test',
                    'path' => '/home/forge/acme/',
                ],
            ]);

            expect($this->registry->get('production')->path)->toBe('/home/forge/acme');
        });

        it('rejects an unknown remote and lists the configured ones', function () {
            config()->set('remote-sync.remotes', [
                'production' => ['host' => 'forge@prod.acme.test', 'path' => '/home/forge/acme'],
                'staging' => ['host' => 'forge@staging.acme.test', 'path' => '/home/forge/staging'],
            ]);

            expect(fn () => $this->registry->get('prod'))
                ->toThrow(InvalidArgumentException::class, 'Configured remotes: production, staging');
        });

        it('explains itself when no remotes are configured at all', function () {
            config()->set('remote-sync.remotes', []);

            expect(fn () => $this->registry->get('production'))
                ->toThrow(InvalidArgumentException::class, 'no remotes are defined');
        });

        it('rejects a missing host and names the env var to set', function () {
            config()->set('remote-sync.remotes', [
                'production' => ['host' => null, 'path' => '/home/forge/acme'],
            ]);

            expect(fn () => $this->registry->get('production'))
                ->toThrow(InvalidArgumentException::class, 'REMOTE_SYNC_PRODUCTION_HOST');
        });

        it('rejects an empty path and names the env var to set', function () {
            config()->set('remote-sync.remotes', [
                'production' => ['host' => 'forge@prod.acme.test', 'path' => ''],
            ]);

            expect(fn () => $this->registry->get('production'))
                ->toThrow(InvalidArgumentException::class, 'REMOTE_SYNC_PRODUCTION_PATH');
        });

        it('rejects the shipped placeholder host', function () {
            config()->set('remote-sync.remotes', [
                'production' => ['host' => 'forge@your-server', 'path' => '/home/forge/acme'],
            ]);

            expect(fn () => $this->registry->get('production'))
                ->toThrow(InvalidArgumentException::class, 'placeholder');
        });

        it('rejects placeholder example.com values', function () {
            config()->set('remote-sync.remotes', [
                'production' => ['host' => 'forge@prod.acme.test', 'path' => '/home/forge/www.example.com'],
            ]);

            expect(fn () => $this->registry->get('production'))
                ->toThrow(InvalidArgumentException::class, 'placeholder');
        });

        it('derives env var hints from dashed remote names', function () {
            config()->set('remote-sync.remotes', [
                'client-a' => ['host' => null, 'path' => '/srv/app'],
            ]);

            expect(fn () => $this->registry->get('client-a'))
                ->toThrow(InvalidArgumentException::class, 'REMOTE_SYNC_CLIENT_A_HOST');
        });
    });

    describe('names and defaults', function () {
        it('lists configured remote names', function () {
            config()->set('remote-sync.remotes', [
                'production' => ['host' => 'a', 'path' => 'b'],
                'staging' => ['host' => 'c', 'path' => 'd'],
            ]);

            expect($this->registry->names())->toBe(['production', 'staging'])
                ->and($this->registry->has('staging'))->toBeTrue()
                ->and($this->registry->has('demo'))->toBeFalse();
        });

        it('returns the default name only when set', function () {
            config()->set('remote-sync.default', null);
            expect($this->registry->defaultName())->toBeNull();

            config()->set('remote-sync.default', '');
            expect($this->registry->defaultName())->toBeNull();

            config()->set('remote-sync.default', 'staging');
            expect($this->registry->defaultName())->toBe('staging');
        });
    });
});
