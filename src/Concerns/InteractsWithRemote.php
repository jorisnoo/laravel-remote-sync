<?php

namespace Noo\LaravelRemoteSync\Concerns;

use Noo\LaravelRemoteSync\Data\RemoteConfig;
use Noo\LaravelRemoteSync\RemoteSyncService;

trait InteractsWithRemote
{
    protected RemoteSyncService $syncService;

    protected RemoteConfig $remote;

    protected function initializeRemote(?string $remoteName): void
    {
        $this->syncService = app(RemoteSyncService::class);
        $this->remote = $this->syncService->getRemote($remoteName);
    }

    protected function ensureNotProduction(): bool
    {
        if (app()->isProduction()) {
            $this->components->error('This command cannot be run in production.');

            return false;
        }

        return true;
    }

    protected function confirmSync(string $operation): bool
    {
        return $this->components->confirm(
            "This will replace your local {$operation} with data from [{$this->remote->name}]. Continue?",
            false
        );
    }

    protected function generateSnapshotName(): string
    {
        return 'remote-sync-'.date('Y-m-d-H-i-s');
    }
}
