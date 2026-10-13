<?php

namespace Noo\LaravelRemoteSync\Remotes;

use Noo\LaravelRemoteSync\Commands\InfoCommand;
use Noo\LaravelRemoteSync\Database\AppInfo;
use Noo\LaravelRemoteSync\Database\Inspector;
use RuntimeException;
use UnexpectedValueException;

/**
 * Learns everything about a remote in one ssh round trip: the app root, a
 * working PHP binary, rsync, and the output of remote-sync:info.
 */
class Probe
{
    protected const NO_PATH = 'REMOTE_SYNC_NO_PATH';

    /** @var list<string> */
    protected const PHP_CANDIDATES = [
        'php', 'php8.5', 'php85', 'php8.4', 'php84', 'php8.3', 'php83',
    ];

    /**
     * @throws RuntimeException with a message that says what to fix
     */
    public function run(Connection $connection): RemoteInfo
    {
        $remote = $connection->remote;
        $result = $connection->run($this->script($remote));
        $output = $result->output();
        $values = $this->values($output);

        if (str_contains($output, self::NO_PATH)) {
            throw new RuntimeException("Cannot access '{$remote->path}' on [{$remote->name}]: directory not found. Check its path in config/remote-sync.php.");
        }

        if (! isset($values['WORK'])) {
            $error = trim($result->errorOutput()) ?: "ssh exited with code {$result->exitCode()}";

            throw new RuntimeException("Cannot reach [{$remote->name}] ({$remote->host}): {$error}");
        }

        if (($values['PHP'] ?? '') === '') {
            throw new RuntimeException("No working PHP binary found on [{$remote->name}]. Set 'php_binary' for it in config/remote-sync.php.");
        }

        if (($values['RSYNC'] ?? '') !== '1') {
            throw new RuntimeException("rsync is not installed on [{$remote->name}].");
        }

        return new RemoteInfo(
            workingPath: $values['WORK'],
            phpBinary: $values['PHP'],
            app: $this->appInfo($remote, $values, trim($result->errorOutput()) ?: trim($output)),
        );
    }

    public function script(Remote $remote): string
    {
        $candidates = implode(' ', array_map('escapeshellarg', $remote->phpBinary !== null ? [$remote->phpBinary] : self::PHP_CANDIDATES));

        // A candidate only counts if it passes the app's composer platform check.
        $works = '"$candidate" -r \'if (is_file("vendor/composer/platform_check.php")) { require "vendor/composer/platform_check.php"; }\' >/dev/null 2>&1';

        return implode(' ', [
            'BASE='.escapeshellarg($remote->path).';',
            'if [ -d "$BASE/current" ]; then WORK="$BASE/current"; else WORK="$BASE"; fi;',
            'cd "$WORK" 2>/dev/null || { echo '.self::NO_PATH.'; exit 1; };',
            'echo "WORK=$WORK";',
            "PHP=''; for candidate in {$candidates}; do",
            'if command -v "$candidate" >/dev/null 2>&1 && '.$works.'; then PHP="$candidate"; break; fi;',
            'done;',
            'echo "PHP=$PHP";',
            'if command -v rsync >/dev/null 2>&1; then echo RSYNC=1; else echo RSYNC=0; fi;',
            'if [ -n "$PHP" ]; then "$PHP" artisan remote-sync:info; fi',
        ]);
    }

    /**
     * @return array<string, string>
     */
    protected function values(string $output): array
    {
        $values = [];

        foreach (preg_split('/\r?\n/', $output) ?: [] as $line) {
            if (preg_match('/^([A-Z_]+)=(.*)$/', trim($line), $match)) {
                $values[$match[1]] = $match[2];
            }
        }

        return $values;
    }

    /**
     * @param  array<string, string>  $values
     */
    protected function appInfo(Remote $remote, array $values, string $error): AppInfo
    {
        if (! isset($values[InfoCommand::KEY])) {
            $lines = array_values(array_filter(array_map('trim', preg_split('/\r?\n/', $error) ?: [])));
            $missing = str_contains($error, 'not defined') || str_contains($error, 'no commands defined');

            throw new RuntimeException(
                "remote-sync:info failed on [{$remote->name}]: ".(implode(' ', array_slice($lines, 0, 2)) ?: 'no output.')
                .($missing ? ' Install jorisnoo/laravel-remote-sync there as a regular (not require-dev) dependency and deploy.' : '')
            );
        }

        try {
            $info = AppInfo::fromArray((array) json_decode($values[InfoCommand::KEY], true));
        } catch (UnexpectedValueException $e) {
            throw new RuntimeException("[{$remote->name}]: {$e->getMessage()}");
        }

        $local = Inspector::PROTOCOL;

        if ($info->protocol !== $local) {
            $update = $info->protocol < $local
                ? "deploy the newer jorisnoo/laravel-remote-sync to [{$remote->name}]"
                : 'update jorisnoo/laravel-remote-sync locally';

            throw new RuntimeException("[{$remote->name}] runs remote-sync protocol {$info->protocol}, this app runs {$local}: {$update}.");
        }

        return $info;
    }
}
