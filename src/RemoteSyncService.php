<?php

namespace Noo\LaravelRemoteSync;

use Illuminate\Contracts\Process\ProcessResult;
use Illuminate\Support\Facades\Process;
use InvalidArgumentException;
use Noo\LaravelRemoteSync\Data\RemoteConfig;

class RemoteSyncService
{
    public function getRemote(?string $name = null): RemoteConfig
    {
        $name ??= config('remote-sync.default');
        $remotes = config('remote-sync.remotes', []);

        if (! isset($remotes[$name])) {
            throw new InvalidArgumentException("Remote '{$name}' is not configured.");
        }

        $remote = $remotes[$name];

        if (empty($remote['host']) || empty($remote['path'])) {
            throw new InvalidArgumentException("Remote '{$name}' is missing host or path configuration.");
        }

        return new RemoteConfig(
            name: $name,
            host: $remote['host'],
            path: $remote['path'],
            pushAllowed: $remote['push_allowed'] ?? false,
        );
    }

    public function getAvailableRemotes(): array
    {
        return array_keys(config('remote-sync.remotes', []));
    }

    public function executeRemoteCommand(RemoteConfig $remote, string $command, ?int $timeout = null): ProcessResult
    {
        $timeout ??= 120;

        return Process::timeout($timeout)->run([
            'ssh',
            $remote->host,
            $command,
        ]);
    }

    public function rsync(
        RemoteConfig $remote,
        string $sourcePath,
        string $destinationPath,
        array $options = [],
        ?int $timeout = null
    ): ProcessResult {
        $timeout ??= config('remote-sync.timeouts.file_sync', 1800);

        $defaultOptions = ['-avz', '--progress'];
        $options = array_merge($defaultOptions, $options);

        $source = "{$remote->host}:{$sourcePath}";

        return Process::timeout($timeout)
            ->tty()
            ->run(array_merge(['rsync'], $options, [$source, $destinationPath]));
    }

    public function createRemoteSnapshot(RemoteConfig $remote, string $snapshotName): ProcessResult
    {
        $excludeTables = config('remote-sync.exclude_tables', []);
        $excludeFlags = collect($excludeTables)
            ->map(fn (string $table) => "--exclude={$table}")
            ->implode(' ');

        $command = "cd {$remote->currentPath()} && php artisan snapshot:create {$snapshotName} {$excludeFlags} --compress";
        $timeout = config('remote-sync.timeouts.snapshot_create', 300);

        return $this->executeRemoteCommand($remote, $command, $timeout);
    }

    public function getRemoteSnapshotPath(RemoteConfig $remote, string $snapshotName): string
    {
        return "{$remote->storagePath()}/snapshots/{$snapshotName}.sql.gz";
    }

    public function downloadSnapshot(RemoteConfig $remote, string $snapshotName, string $localPath): ProcessResult
    {
        $remotePath = $this->getRemoteSnapshotPath($remote, $snapshotName);
        $timeout = config('remote-sync.timeouts.snapshot_download', 600);

        return Process::timeout($timeout)
            ->tty()
            ->run([
                'rsync',
                '-avz',
                '--progress',
                "{$remote->host}:{$remotePath}",
                $localPath,
            ]);
    }

    public function deleteRemoteSnapshot(RemoteConfig $remote, string $snapshotName): ProcessResult
    {
        $command = "cd {$remote->currentPath()} && php artisan snapshot:delete {$snapshotName} --no-interaction";
        $timeout = config('remote-sync.timeouts.snapshot_cleanup', 60);

        return $this->executeRemoteCommand($remote, $command, $timeout);
    }

    public function rsyncUpload(
        RemoteConfig $remote,
        string $sourcePath,
        string $destinationPath,
        array $options = [],
        ?int $timeout = null
    ): ProcessResult {
        $timeout ??= config('remote-sync.timeouts.file_sync', 1800);

        $defaultOptions = ['-avz', '--progress'];
        $options = array_merge($defaultOptions, $options);

        $destination = "{$remote->host}:{$destinationPath}";

        return Process::timeout($timeout)
            ->tty()
            ->run(array_merge(['rsync'], $options, [$sourcePath, $destination]));
    }

    public function uploadSnapshot(RemoteConfig $remote, string $snapshotName, string $localPath): ProcessResult
    {
        $remotePath = "{$remote->storagePath()}/snapshots/";
        $localFile = "{$localPath}/{$snapshotName}.sql.gz";
        $timeout = config('remote-sync.timeouts.snapshot_upload', 600);

        return Process::timeout($timeout)
            ->tty()
            ->run([
                'rsync',
                '-avz',
                '--progress',
                '--partial',
                $localFile,
                "{$remote->host}:{$remotePath}",
            ]);
    }

    public function loadRemoteSnapshot(RemoteConfig $remote, string $snapshotName): ProcessResult
    {
        $command = "cd {$remote->currentPath()} && php artisan snapshot:load {$snapshotName} --force";
        $timeout = config('remote-sync.timeouts.snapshot_create', 300);

        return $this->executeRemoteCommand($remote, $command, $timeout);
    }

    public function createRemoteBackup(RemoteConfig $remote, string $backupName): ProcessResult
    {
        $excludeTables = config('remote-sync.exclude_tables', []);
        $excludeFlags = collect($excludeTables)
            ->map(fn (string $table) => "--exclude={$table}")
            ->implode(' ');

        $command = "cd {$remote->currentPath()} && php artisan snapshot:create {$backupName} {$excludeFlags} --compress";
        $timeout = config('remote-sync.timeouts.snapshot_create', 300);

        return $this->executeRemoteCommand($remote, $command, $timeout);
    }
}
