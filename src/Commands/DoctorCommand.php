<?php

namespace Noo\LaravelRemoteSync\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Process;
use InvalidArgumentException;
use Noo\LaravelRemoteSync\Remotes\Connection;
use Noo\LaravelRemoteSync\Remotes\Probe;
use Noo\LaravelRemoteSync\Remotes\RemoteRegistry;
use Noo\LaravelRemoteSync\Snapshots\Importer;
use Noo\LaravelRemoteSync\Snapshots\Snapshots;
use Noo\LaravelRemoteSync\Sync\Rsync;
use Throwable;

class DoctorCommand extends Command
{
    protected $signature = 'remote-sync:doctor
        {remote? : Check a single remote (default: all configured remotes)}';

    protected $description = 'Check local and remote prerequisites for remote-sync';

    protected bool $healthy = true;

    public function handle(): int
    {
        $this->healthy = true;

        $this->checkLocal();

        $registry = app(RemoteRegistry::class);
        $names = $this->argument('remote') !== null
            ? [$this->argument('remote')]
            : $registry->names();

        if ($names === []) {
            $this->failCheck('Remotes', 'none configured in config/remote-sync.php');
        }

        foreach ($names as $name) {
            $this->checkRemote($registry, $name);
        }

        $this->newLine();

        if ($this->healthy) {
            $this->components->success('Everything looks good.');

            return self::SUCCESS;
        }

        $this->components->error('Some checks failed - fix the items marked FAIL above.');

        return self::FAILURE;
    }

    protected function checkLocal(): void
    {
        $this->newLine();
        $this->components->info('Local environment');

        $driver = Importer::localDriver();

        if (in_array($driver, ['mysql', 'pgsql'], true)) {
            $this->passCheck('Database driver', $driver);
            $maria = config('database.connections.'.config('database.default').'.driver') === 'mariadb';
            foreach ($driver === 'pgsql' ? ['psql', 'pg_dump'] : ($maria ? ['mariadb', 'mariadb-dump'] : ['mysql', 'mysqldump']) as $binary) {
                $this->checkLocalBinary($binary);
            }
        } else {
            $this->failCheck('Database driver', "{$driver} (imports need mysql or pgsql)");
        }

        foreach (['bash', 'gzip', 'ssh'] as $binary) {
            $this->checkLocalBinary($binary);
        }
        $this->checkRsync();

        $snapshotDir = Snapshots::configuredLocalDir();
        if ($snapshotDir === null) {
            $this->failCheck('Snapshot directory', 'configure a local snapshots disk with an absolute root');

            return;
        }
        $writable = is_dir($snapshotDir) ? is_writable($snapshotDir) : is_writable(dirname($snapshotDir));

        $writable
            ? $this->passCheck('Snapshot directory', $snapshotDir)
            : $this->failCheck('Snapshot directory', "{$snapshotDir} is not writable");
    }

    protected function checkRsync(?Connection $connection = null): void
    {
        try {
            $this->passCheck('rsync', Rsync::version($connection));
        } catch (Throwable $e) {
            $this->failCheck('rsync', $e->getMessage());
        }
    }

    protected function checkLocalBinary(string $binary): void
    {
        Process::timeout(10)->run("command -v {$binary}")->successful()
            ? $this->passCheck($binary, 'found')
            : $this->failCheck($binary, 'not found on PATH');
    }

    protected function checkRemote(RemoteRegistry $registry, string $name): void
    {
        $this->newLine();
        $this->components->info("Remote [{$name}]");

        try {
            $remote = $registry->get($name);
        } catch (InvalidArgumentException $e) {
            $this->failCheck('Configuration', $e->getMessage());

            return;
        }

        $this->passCheck('Configuration', "{$remote->host}:{$remote->path}");

        $connection = new Connection($remote);

        try {
            $connection->checkHostKey();
        } catch (Throwable $e) {
            $this->failCheck('SSH', $e->getMessage());

            return;
        }

        $this->passCheck('Host key', 'verified');

        try {
            $info = app(Probe::class)->run($connection);
        } catch (Throwable $e) {
            $this->failCheck('Connection', $e->getMessage());

            return;
        }

        $this->passCheck('Application path', $info->workingPath.($info->isAtomic ? ' (atomic deployment)' : ''));

        $info->phpBinary !== null
            ? $this->passCheck('PHP binary', $info->phpBinary)
            : $this->failCheck('PHP binary', "none of the known binaries work - set 'php_binary' for this remote");

        if (($error = $info->databaseProblem()) !== null) {
            $this->failCheck('Remote database prerequisites', $error);
        } else {
            $this->passCheck('Remote database prerequisites', 'compatible protocol, snapshots package and local disk');
        }

        if ($info->hasRsync) {
            $this->checkRsync($connection);
        } else {
            $this->failCheck('rsync', 'not found on the remote');
        }

        if ($info->driver === null) {
            $this->failCheck('Database driver', 'could not be detected by remote-sync:info');
        } elseif (Importer::normalizeDriver($info->driver) === Importer::localDriver()) {
            $this->passCheck('Database driver', "{$info->driver} (matches local)");
        } else {
            $this->failCheck('Database driver', "{$info->driver} does not match local ".Importer::localDriver());
        }

        if ($info->snapshotDir !== '') {
            $this->passCheck('Snapshot directory', $info->snapshotDir);
        }
    }

    protected function passCheck(string $check, string $detail): void
    {
        $this->components->twoColumnDetail($check, "<fg=green>OK</> {$detail}");
    }

    protected function failCheck(string $check, string $detail): void
    {
        $this->healthy = false;
        $this->components->twoColumnDetail($check, "<fg=red>FAIL</> {$detail}");
    }
}
