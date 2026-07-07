<?php

use Illuminate\Support\Facades\Process;
use Mockery\MockInterface;
use Noo\LaravelRemoteSync\Snapshots\Importer;
use Noo\LaravelRemoteSync\Snapshots\Snapshots;
use Noo\LaravelRemoteSync\Tests\TestCase;

uses(TestCase::class)->in(__DIR__);

// Shared fixtures for command feature tests

function syncProbeOutput(array $overrides = []): string
{
    $json = json_encode(array_merge([
        'driver' => 'sqlite',
        'tables' => ['users', 'posts', 'cache', 'migrations'],
        'snapshot_dir' => '/home/forge/acme/storage/snapshots',
    ], $overrides['json'] ?? []));

    return implode("\n", [
        'ATOMIC='.($overrides['atomic'] ?? '0'),
        'PHP='.($overrides['php'] ?? 'php8.4'),
        'RSYNC='.($overrides['rsync'] ?? '1'),
        'SNAPSHOTS='.($overrides['snapshots'] ?? '1'),
        "REMOTE_SYNC_JSON={$json}",
    ]);
}

/**
 * Fake every process a sync command spawns: host-key checks, the probe,
 * rsync dry-runs, and gzip verification. $custom entries match on a
 * substring of the command and win over the defaults.
 */
function fakeSyncProcesses(array $probeOverrides = [], array $custom = []): void
{
    Process::fake(function ($process) use ($probeOverrides, $custom) {
        $command = is_array($process->command) ? implode(' ', $process->command) : $process->command;

        foreach ($custom as $needle => $result) {
            if (str_contains($command, $needle)) {
                return $result;
            }
        }

        if (str_contains($command, 'BatchMode=yes')) {
            return Process::result();
        }

        if (str_contains($command, 'BASE=')) {
            return Process::result(output: syncProbeOutput($probeOverrides));
        }

        if (str_contains($command, '--dry-run')) {
            return Process::result(output: ">f+++++++++ uploads/new.jpg\n*deleting   uploads/stale.jpg");
        }

        return Process::result();
    });
}

function mockSnapshots(): MockInterface
{
    $mock = Mockery::mock(Snapshots::class);
    $mock->shouldReceive('createLocal')->andReturn(0)->byDefault();
    $mock->shouldReceive('createRemote')->andReturn(Process::result())->byDefault();
    $mock->shouldReceive('download')->andReturn(Process::result())->byDefault();
    $mock->shouldReceive('upload')->andReturn(Process::result())->byDefault();
    $mock->shouldReceive('loadRemote')->andReturn(Process::result())->byDefault();
    $mock->shouldReceive('deleteRemote')->andReturn(Process::result())->byDefault();

    // bind() with a closure so parameterized app(Snapshots::class, [...]) still resolves the mock
    app()->bind(Snapshots::class, fn () => $mock);

    return $mock;
}

function mockImporter(): MockInterface
{
    $mock = Mockery::mock(Importer::class);
    $mock->shouldReceive('localTables')->andReturn(['users', 'cache', 'migrations'])->byDefault();
    $mock->shouldReceive('import')->andReturn(Process::result())->byDefault();
    $mock->shouldReceive('truncateExcluded')->andReturn(['cache'])->byDefault();
    $mock->shouldReceive('filterUsers')->andReturn(null)->byDefault();

    app()->bind(Importer::class, fn () => $mock);

    return $mock;
}
