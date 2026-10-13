<?php

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Noo\LaravelRemoteSync\Database\UserFilter;

beforeEach(function () {
    Schema::create('users', function ($table) {
        $table->increments('id');
        $table->string('email');
    });

    DB::table('users')->insert([
        ['email' => 'keep@acme.test'],
        ['email' => 'jane@acme.test'],
        ['email' => 'other@elsewhere.test'],
    ]);
});

describe('UserFilter', function () {
    it('does nothing when filtering is off', function () {
        config()->set('remote-sync.filter_users', false);

        expect((new UserFilter)->apply())->toBeNull()
            ->and(DB::table('users')->count())->toBe(3);
    });

    it('keeps wildcard matches and deletes the rest', function () {
        config()->set('remote-sync.filter_users', ['*@acme.test']);

        expect((new UserFilter)->apply())->toBe(['kept' => 2, 'deleted' => 1, 'skipped' => false])
            ->and(DB::table('users')->pluck('email')->all())->toBe(['keep@acme.test', 'jane@acme.test']);
    });

    it('keeps exact matches', function () {
        config()->set('remote-sync.filter_users', ['other@elsewhere.test', 'keep@acme.test']);

        expect((new UserFilter)->apply()['kept'])->toBe(2)
            ->and(DB::table('users')->pluck('email')->all())->toBe(['keep@acme.test', 'other@elsewhere.test']);
    });

    it('refuses to delete every user', function () {
        config()->set('remote-sync.filter_users', ['nobody@nowhere.test']);

        expect((new UserFilter)->apply())->toBe(['kept' => 0, 'deleted' => 0, 'skipped' => true])
            ->and(DB::table('users')->count())->toBe(3);
    });

    it('does nothing without a users table', function () {
        Schema::drop('users');
        config()->set('remote-sync.filter_users', ['keep@acme.test']);

        expect((new UserFilter)->apply())->toBeNull();
    });
});
