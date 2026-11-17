<?php

namespace Noo\LaravelRemoteSync\Commands;

use Illuminate\Console\Command;
use InvalidArgumentException;
use Noo\LaravelRemoteSync\Database\AppInfo;
use Noo\LaravelRemoteSync\Database\Dumps;
use Noo\LaravelRemoteSync\Database\Inspector;
use Noo\LaravelRemoteSync\Remotes\Connection;
use Noo\LaravelRemoteSync\Remotes\HostKey;
use Noo\LaravelRemoteSync\Remotes\Probe;
use Noo\LaravelRemoteSync\Remotes\RemoteRegistry;
use RuntimeException;
use Symfony\Component\Process\ExecutableFinder;
use Throwable;

class DoctorCommand extends Command
{
    protected $signature = 'remote-sync:doctor
        {remote? : Check a single remote (default: all configured remotes)}';

    protected $description = 'Check the local and remote prerequisites for remote-sync';

    protected bool $healthy = true;

    public function handle(Inspector $inspector, RemoteRegistry $registry): int
    {
        $this->healthy = true;

        $local = $this->checkLocal($inspector);
        $names = $this->argument('remote') !== null ? [$this->argument('remote')] : $registry->names();

        if ($names === []) {
            $this->check('Remotes', false, 'none configured in config/remote-sync.php');
        }

        foreach ($names as $name) {
            $this->checkRemote($registry, $name, $local);
        }

        $this->newLine();

        if (! $this->healthy) {
            $this->components->error('Some checks failed: fix the items marked FAIL above.');

            return self::FAILURE;
        }

        $this->components->success('Everything looks good.');

        return self::SUCCESS;
    }

    protected function checkLocal(Inspector $inspector): ?AppInfo
    {
        $this->newLine();
        $this->components->info('Local');

        $finder = new ExecutableFinder;

        foreach (['ssh', 'rsync'] as $binary) {
            $this->check($binary, $finder->find($binary) !== null, 'found', 'not found on PATH');
        }

        try {
            $info = $inspector->inspect();
        } catch (Throwable $e) {
            $this->check('Database', false, '', $e->getMessage());

            return null;
        }

        $this->checkDatabase($info);

        $directory = storage_path(Dumps::DIRECTORY);
        $this->check('Dump directory', is_writable(is_dir($directory) ? $directory : storage_path()), $directory, "{$directory} is not writable");

        return $info;
    }

    protected function checkRemote(RemoteRegistry $registry, string $name, ?AppInfo $local): void
    {
        $this->newLine();
        $this->components->info("Remote [{$name}]");

        try {
            $remote = $registry->get($name);
        } catch (InvalidArgumentException $e) {
            $this->check('Configuration', false, '', $e->getMessage());

            return;
        }

        $this->check('Configuration', true, "{$remote->host}:{$remote->path}");

        $connection = new Connection($remote);
        $hostKey = $connection->checkHostKey();

        $this->check('SSH', $hostKey === HostKey::Trusted, 'connected', match ($hostKey) {
            HostKey::Changed => 'host key CHANGED: possible man-in-the-middle, check ~/.ssh/known_hosts',
            HostKey::Unknown => 'unknown host key: connect once interactively (e.g. remote-sync:pull) to trust it',
            default => 'key authentication failed: load your key with ssh-add',
        });

        if ($hostKey !== HostKey::Trusted) {
            return;
        }

        try {
            $info = app(Probe::class)->run($connection);
        } catch (RuntimeException $e) {
            $this->check('Application', false, '', $e->getMessage());

            return;
        }

        $this->check('Application', true, "{$info->workingPath} ({$info->app->environment})");
        $this->check('PHP', true, $info->phpBinary);
        $this->check('remote-sync', true, 'protocol '.$info->app->protocol);
        $this->checkDatabase($info->app, $local);
    }

    protected function checkDatabase(AppInfo $info, ?AppInfo $local = null): void
    {
        if (! $info->supportsDatabaseSync()) {
            $this->check('Database', false, '', "{$info->driver}: only mysql and pgsql can be synced");

            return;
        }

        if ($local !== null && $local->driver !== $info->driver) {
            $this->check('Database', false, '', "{$info->driver} does not match the local {$local->driver}");
        } else {
            $this->check('Database', true, $info->driver);
        }

        $this->check('Database tools', $info->missingBinaries === [], 'found', 'missing: '.implode(', ', $info->missingBinaries));
    }

    protected function check(string $name, bool $passed, string $detail, string $failure = ''): void
    {
        $this->healthy = $this->healthy && $passed;

        $this->components->twoColumnDetail($name, $passed ? "<fg=green>OK</> {$detail}" : "<fg=red>FAIL</> {$failure}");
    }
}
