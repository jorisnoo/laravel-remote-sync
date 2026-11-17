<?php

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Noo\LaravelRemoteSync\Database\AppInfo;
use Noo\LaravelRemoteSync\Database\Inspector;

describe('Inspector', function () {
    it('describes the app', function () {
        Schema::create('migrations', function ($table) {
            $table->increments('id');
            $table->string('migration');
            $table->integer('batch');
        });
        Schema::create('users', fn ($table) => $table->id());
        DB::table('migrations')->insert([
            ['migration' => '0001_create_users_table', 'batch' => 1],
            ['migration' => '0002_create_posts_table', 'batch' => 2],
        ]);

        $info = (new Inspector)->inspect();

        expect($info->protocol)->toBe(Inspector::PROTOCOL)
            ->and($info->environment)->toBe('testing')
            ->and($info->driver)->toBe('sqlite')
            ->and($info->tables)->toEqualCanonicalizing(['migrations', 'users'])
            ->and($info->migrations)->toBe(['0001_create_users_table', '0002_create_posts_table'])
            ->and($info->storagePath)->toBe(storage_path())
            ->and($info->supportsDatabaseSync())->toBeFalse();
    });

    it('reads the migrations table name from config', function () {
        config()->set('database.migrations', ['table' => 'schema_migrations']);
        expect(Inspector::migrationsTable())->toBe('schema_migrations');

        config()->set('database.migrations', 'legacy_migrations');
        expect(Inspector::migrationsTable())->toBe('legacy_migrations');
    });
});

describe('AppInfo', function () {
    it('round-trips through an array', function () {
        $info = appInfo(['dumps' => [['name' => 'pre-pull-x', 'mtime' => 1]]]);

        expect(AppInfo::fromArray($info->toArray()))->toEqual($info);
    });

    it('rejects malformed input', function () {
        expect(fn () => AppInfo::fromArray(['driver' => 'mysql']))->toThrow(UnexpectedValueException::class);
    });
});
