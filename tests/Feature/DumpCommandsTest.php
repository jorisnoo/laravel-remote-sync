<?php

use Noo\LaravelRemoteSync\Database\AppInfo;
use Noo\LaravelRemoteSync\Database\Dumper;
use Noo\LaravelRemoteSync\Database\Dumps;
use Noo\LaravelRemoteSync\Database\Loader;

afterEach(function () {
    foreach (glob(storage_path('remote-sync/*.sql.gz')) ?: [] as $file) {
        unlink($file);
    }
});

describe('remote-sync:info', function () {
    it('prints the app info as one KEY=json line', function () {
        $this->artisan('remote-sync:info')
            ->expectsOutputToContain('REMOTE_SYNC_INFO={"protocol":')
            ->assertSuccessful();
    });

    it('is hidden from the command list', function () {
        expect(Artisan::all()['remote-sync:info']->isHidden())->toBeTrue()
            ->and(Artisan::all()['remote-sync:dump']->isHidden())->toBeTrue()
            ->and(Artisan::all()['remote-sync:load']->isHidden())->toBeFalse();
    });

    it('produces output the probe can read back', function () {
        Artisan::call('remote-sync:info');
        [, $json] = explode('=', trim(Artisan::output()), 2);

        expect(AppInfo::fromArray(json_decode($json, true))->driver)->toBe('sqlite');
    });
});

describe('remote-sync:dump', function () {
    it('dumps into storage/remote-sync with the given options', function () {
        $dumper = Mockery::mock(Dumper::class);
        $dumper->shouldReceive('dump')->once()->with(Dumps::path('remote-sync-x'), ['sessions'], ['cache', 'jobs']);
        app()->instance(Dumper::class, $dumper);

        $this->artisan('remote-sync:dump', ['name' => 'remote-sync-x', '--exclude' => ['sessions'], '--exclude-data' => ['cache', 'jobs']])
            ->assertSuccessful();

        expect(is_file(storage_path('remote-sync/.gitignore')))->toBeTrue();
    });

    it('removes a partial dump and reports the error', function () {
        $dumper = Mockery::mock(Dumper::class);
        $dumper->shouldReceive('dump')->andReturnUsing(function (string $path) {
            file_put_contents($path, 'partial');

            throw new RuntimeException('mysqldump: Got error: 1045: Access denied');
        });
        app()->instance(Dumper::class, $dumper);

        $this->artisan('remote-sync:dump', ['name' => 'remote-sync-x'])
            ->expectsOutputToContain('Access denied')
            ->assertFailed();

        expect(is_file(Dumps::path('remote-sync-x')))->toBeFalse();
    });

    it('rejects names that would leave the dump directory', function () {
        $this->artisan('remote-sync:dump', ['name' => '../../.env'])
            ->expectsOutputToContain("Invalid dump name '../../.env'")
            ->assertFailed();
    });
});

describe('remote-sync:load', function () {
    it('loads with --fresh when asked and --force skips the question', function () {
        $loader = Mockery::mock(Loader::class);
        $loader->shouldReceive('load')->once()->with(Dumps::path('pre-pull-x'), true);
        app()->instance(Loader::class, $loader);

        $this->artisan('remote-sync:load', ['name' => 'pre-pull-x', '--fresh' => true, '--force' => true])
            ->expectsOutputToContain('Loaded pre-pull-x.')
            ->assertSuccessful();
    });

    it('asks before loading interactively', function () {
        $loader = Mockery::mock(Loader::class);
        $loader->shouldNotReceive('load');
        app()->instance(Loader::class, $loader);

        $this->artisan('remote-sync:load', ['name' => 'pre-pull-x', '--fresh' => true])
            ->expectsConfirmation('Replace the whole database with pre-pull-x?', 'no')
            ->assertSuccessful();
    });

    it('requires --force when not interactive', function () {
        $this->artisan('remote-sync:load', ['name' => 'pre-pull-x', '--no-interaction' => true])
            ->expectsOutputToContain('Confirmation required')
            ->assertFailed();
    });

    it('reports load errors', function () {
        $loader = Mockery::mock(Loader::class);
        $loader->shouldReceive('load')->andThrow(new RuntimeException('The dump failed its integrity check'));
        app()->instance(Loader::class, $loader);

        $this->artisan('remote-sync:load', ['name' => 'pre-pull-x', '--force' => true])
            ->expectsOutputToContain('integrity check')
            ->assertFailed();
    });
});
