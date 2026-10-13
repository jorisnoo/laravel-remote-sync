<?php

use Illuminate\Contracts\Process\ProcessResult;
use Illuminate\Support\Facades\Process;
use Noo\LaravelRemoteSync\Commands\InfoCommand;
use Noo\LaravelRemoteSync\Database\AppInfo;
use Noo\LaravelRemoteSync\Database\Inspector;
use Noo\LaravelRemoteSync\Tests\TestCase;

uses(TestCase::class)->in(__DIR__);

/*
| Shared fixtures. A sync test describes both sides with appInfo(), fakes
| every process with fakeProcesses(), then reads ranCommands() to check
| what ran, in which order.
*/

/**
 * @param  array<string, mixed>  $overrides
 */
function appInfo(array $overrides = []): AppInfo
{
    return AppInfo::fromArray(array_merge([
        'protocol' => Inspector::PROTOCOL,
        'environment' => 'staging',
        'driver' => 'mysql',
        'tables' => ['users', 'posts', 'cache', 'jobs', 'migrations'],
        'migrations' => ['0001_create_users_table', '0002_create_posts_table'],
        'storagePath' => '/srv/acme/releases/7/storage',
        'dumps' => [],
        'missingBinaries' => [],
    ], $overrides));
}

/**
 * @param  array<string, mixed>  $overrides
 */
function useLocalInfo(array $overrides = []): AppInfo
{
    $info = appInfo(array_merge(['environment' => 'local', 'storagePath' => storage_path()], $overrides));

    app()->instance(Inspector::class, Mockery::mock(Inspector::class, ['inspect' => $info]));

    return $info;
}

/**
 * @param  array<string, mixed>  $overrides  remote-sync:info fields
 */
function probeOutput(array $overrides = [], string $php = 'php8.4', string $rsync = '1'): string
{
    return implode("\n", [
        'WORK=/srv/acme/current',
        "PHP={$php}",
        "RSYNC={$rsync}",
        InfoCommand::KEY.'='.json_encode(appInfo($overrides)->toArray()),
    ]);
}

/**
 * Fake every process. The host key is trusted, the probe describes a
 * remote with $remote info, rsync previews find one new and one stale
 * file, and everything else succeeds. $custom maps a command substring
 * to a result and wins over the defaults.
 *
 * @param  array<string, mixed>  $remote
 * @param  array<string, ProcessResult|Closure>  $custom
 */
function fakeProcesses(array $remote = [], array $custom = []): void
{
    $GLOBALS['ranCommands'] = [];

    Process::fake(function ($process) use ($remote, $custom) {
        $command = is_array($process->command) ? implode(' ', $process->command) : $process->command;
        $GLOBALS['ranCommands'][] = $command;

        foreach ($custom as $needle => $result) {
            if (str_contains($command, $needle)) {
                return $result instanceof Closure ? $result($command) : $result;
            }
        }

        return match (true) {
            str_contains($command, 'WORK=') => Process::result(output: probeOutput($remote)),
            str_contains($command, '--dry-run') => Process::result(output: ">f+++++++++ new.jpg\n*deleting   stale.jpg\n"),
            default => Process::result(),
        };
    });
}

/**
 * @return list<string>
 */
function ranCommands(): array
{
    return $GLOBALS['ranCommands'] ?? [];
}

/**
 * Index of the first command containing every needle, or -1.
 */
function ranAt(string ...$needles): int
{
    foreach (ranCommands() as $index => $command) {
        if (array_filter($needles, fn (string $needle) => ! str_contains($command, $needle)) === []) {
            return $index;
        }
    }

    return -1;
}

function syncConfig(bool $push = true): void
{
    config()->set('remote-sync.remotes', [
        'staging' => ['host' => 'forge@staging.acme.test', 'path' => '/srv/acme', 'push' => $push],
    ]);
    config()->set('remote-sync.paths', ['app']);
    config()->set('remote-sync.exclude_tables', ['cache', 'jobs', 'migrations']);
    config()->set('remote-sync.filter_users', false);
}
