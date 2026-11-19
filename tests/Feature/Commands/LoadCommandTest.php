<?php

use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Noo\LaravelRemoteSync\Snapshots\Importer;

beforeEach(function () {
    $this->root = sys_get_temp_dir().'/load-test-'.bin2hex(random_bytes(8));
    mkdir($this->root, 0700);
    config()->set('db-snapshots.disk', 'restore');
    config()->set('filesystems.disks.restore', ['driver' => 'local', 'root' => $this->root]);
});

afterEach(function () {
    File::deleteDirectory($this->root);
});

it('requires an explicit choice before restoring', function () {
    $this->mock(Importer::class)->shouldNotReceive('import');
    $this->artisan('remote-sync:load', ['name' => 'backup', '--no-interaction' => true])
        ->expectsOutputToContain('re-run with --force')->assertFailed();
    $this->artisan('remote-sync:load', ['name' => 'backup'])
        ->expectsConfirmation('Restore the snapshot and overwrite data in the configured database?', 'no')
        ->assertFailed();
});

it('runs a confirmed restore and reports the client result', function () {
    $this->mock(Importer::class)->shouldReceive('import')->with('backup')->once()
        ->andReturn(Process::result(exitCode: 3, errorOutput: 'dependent constraint'));
    $this->artisan('remote-sync:load', ['name' => 'backup'])
        ->expectsConfirmation('Restore the snapshot and overwrite data in the configured database?', 'yes')
        ->expectsOutputToContain('dependent constraint')->assertFailed();
});

it('does not interpret restore names as paths or flags', function (string $name) {
    Process::fake();
    $this->artisan('remote-sync:load', ['name' => $name, '--force' => true])
        ->expectsOutputToContain('Invalid snapshot name')->assertFailed();
    Process::assertNothingRan();
})->with(['../backup', '/backups/backup', 'sub/backup', 'sub\\backup', '-backup', "backup\n"]);

it('does not load a symlink or a missing snapshot', function () {
    file_put_contents($this->root.'/target', gzencode("SELECT 1;\n"));
    symlink($this->root.'/target', $this->root.'/linked.sql.gz');
    foreach (['linked', 'missing'] as $name) {
        $this->artisan('remote-sync:load', ['name' => $name, '--force' => true])
            ->expectsOutputToContain('Snapshot file not found')->assertFailed();
    }
});

it('rejects a non-local snapshots disk even when it has a root', function () {
    config()->set('filesystems.disks.restore.driver', 's3');
    file_put_contents($this->root.'/backup.sql.gz', gzencode("SELECT 1;\n"));
    Process::fake();
    $this->artisan('remote-sync:load', ['name' => 'backup', '--force' => true])
        ->expectsOutputToContain('local driver')->assertFailed();
    Process::assertNothingRan();
});
