<?php

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Noo\LaravelRemoteSync\Snapshots\Importer;
use Noo\LaravelRemoteSync\Snapshots\SnapshotDump;
use Noo\LaravelRemoteSync\Snapshots\Snapshots;
use Noo\LaravelRemoteSync\Tests\Support\PrivateDatabase;

it('round-trips full, schema-only and excluded tables with the native database tools', function (string $driver) {
    if (getenv('REMOTE_SYNC_TEST_'.strtoupper($driver)) !== '1') {
        $this->markTestSkipped('Set REMOTE_SYNC_TEST_'.strtoupper($driver).'=1 to start a private server.');
    }

    PrivateDatabase::withServer($driver, function () use ($driver) {
        DB::setDefaultConnection('source');
        DB::statement('CREATE TABLE users (id integer PRIMARY KEY, email varchar(90) NOT NULL)');
        DB::statement('CREATE TABLE sessions (id integer PRIMARY KEY)');
        DB::statement('CREATE TABLE migrations (id integer PRIMARY KEY)');
        DB::table('users')->insert(['id' => 1, 'email' => 'admin@example.test']);
        if ($driver === 'pgsql') {
            DB::statement('GRANT SELECT ON users TO PUBLIC');
        }
        DB::table('sessions')->insert(['id' => 2]);
        DB::table('migrations')->insert(['id' => 3]);
        $dump = app(SnapshotDump::class);
        $dump->create('full');
        $dump->create('pull', empty: ['sessions']);
        $dump->create('push', exclude: ['sessions']);

        DB::setDefaultConnection('destination');
        $load = function (string $name) {
            $status = Artisan::call('remote-sync:load', ['name' => $name, '--force' => true]);
            $this->assertSame(0, $status, Artisan::output());
        };

        // Fresh and populated targets both get the excluded table's structure.
        $load('pull');
        expect(DB::table('sessions')->count())->toBe(0)
            ->and(DB::table('migrations')->pluck('id')->all())->toBe([3]);
        DB::table('users')->insert(['id' => 99, 'email' => 'stale@local.test']);
        DB::table('sessions')->insert(['id' => 91]);
        $load('pull');
        expect(DB::table('users')->pluck('id')->all())->toBe([1])
            ->and(DB::table('sessions')->count())->toBe(0);

        // On push, exclusion is different: leave the destination table alone.
        DB::table('sessions')->insert(['id' => 92]);
        $load('push');
        expect(DB::table('sessions')->pluck('id')->all())->toBe([92]);

        DB::statement('CREATE TABLE branch_only (id integer PRIMARY KEY)');
        $result = app(Importer::class)->import('full', dropTables: true);
        $this->assertTrue($result->successful(), $result->errorOutput());
        expect(DB::getSchemaBuilder()->hasTable('branch_only'))->toBeFalse()
            ->and(DB::table('sessions')->pluck('id')->all())->toBe([2])
            ->and(DB::table('users')->value('email'))->toBe('admin@example.test');
        if ($driver === 'pgsql') {
            expect(DB::scalar("SELECT tableowner FROM pg_tables WHERE schemaname='public' AND tablename='users'"))->toBe('destination_owner')
                ->and(DB::scalar("SELECT count(*) FROM information_schema.table_privileges WHERE table_name='users' AND grantee='PUBLIC'"))->toBe(0);

            // The empty table has a dependent included row. Refuse the invalid
            // FK instead of emptying the dependent table with TRUNCATE CASCADE.
            DB::setDefaultConnection('source');
            DB::statement('CREATE TABLE activity (session_id integer REFERENCES sessions(id))');
            DB::table('activity')->insert(['session_id' => 2]);
            $dump->create('incompatible', empty: ['sessions']);
            DB::setDefaultConnection('destination');
            $load('full');
            $bad = app(Importer::class)->import('incompatible');
            expect($bad->failed())->toBeTrue()
                ->and($bad->errorOutput())->toContain('foreign key')
                ->and(DB::table('sessions')->pluck('id')->all())->toBe([2])
                ->and(DB::getSchemaBuilder()->hasTable('activity'))->toBeFalse();
        }
    });
})->with(['pgsql', 'mysql']);

it('rolls back the entire PostgreSQL restore on dependencies, late SQL errors and corrupt input', function () {
    if (getenv('REMOTE_SYNC_TEST_PGSQL') !== '1') {
        $this->markTestSkipped('Set REMOTE_SYNC_TEST_PGSQL=1 to start a private server.');
    }

    PrivateDatabase::withServer('pgsql', function () {
        DB::setDefaultConnection('source');
        DB::statement('CREATE TABLE users (id integer PRIMARY KEY)');
        DB::statement('CREATE TABLE sessions (id integer PRIMARY KEY)');
        DB::table('users')->insert(['id' => 1]);
        DB::table('sessions')->insert(['id' => 2]);
        $dump = app(SnapshotDump::class);
        $dump->create('pull', empty: ['sessions']);
        $dump->create('broken');
        // A complete, valid SQL stream followed by a late error, after full pre-drops.
        file_put_contents(Snapshots::localPath('broken'), gzencode("\nSELECT * FROM missing_at_end;\n"), FILE_APPEND);
        file_put_contents(Snapshots::localPath('partial'), substr(gzencode("DROP TABLE audit;\n"), 0, -8));

        DB::setDefaultConnection('destination');
        DB::statement('CREATE TABLE users (id integer PRIMARY KEY)');
        DB::statement('CREATE TABLE sessions (id integer PRIMARY KEY, note text UNIQUE)');
        DB::statement('CREATE TABLE audit (user_id integer REFERENCES users(id))');
        DB::table('users')->insert(['id' => 77]);
        DB::table('sessions')->insert(['id' => 88, 'note' => 'local']);
        DB::table('audit')->insert(['user_id' => 77]);
        // Compare catalog identities too: dropping/recreating similar tables,
        // PKs, unique constraints or the local FK is not a successful rollback.
        $state = fn () => [
            DB::table('users')->get()->toJson(), DB::table('sessions')->get()->toJson(), DB::table('audit')->get()->toJson(),
            DB::select("SELECT oid, conname, conrelid, pg_get_constraintdef(oid) AS definition FROM pg_constraint WHERE connamespace = 'public'::regnamespace ORDER BY oid"),
        ];
        $original = $state();
        $importer = app(Importer::class);
        $dependency = $importer->import('pull');
        expect($dependency->failed())->toBeTrue()
            ->and($dependency->errorOutput())->toContain('audit')
            ->and($state())->toEqual($original);

        $late = $importer->import('broken', dropTables: true);
        expect($late->failed())->toBeTrue()
            ->and($late->errorOutput())->toContain('missing_at_end')
            ->and($state())->toEqual($original);

        $corrupt = $importer->import('partial', dropTables: true);
        expect($corrupt->failed())->toBeTrue()
            ->and($state())->toEqual($original);
    });
});
