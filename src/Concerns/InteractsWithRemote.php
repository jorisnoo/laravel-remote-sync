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

    protected function ensurePushAllowed(): bool
    {
        if (! $this->remote->pushAllowed) {
            $this->components->error("Push is not allowed for remote [{$this->remote->name}]. Set 'push_allowed' to true in config to enable.");

            return false;
        }

        return true;
    }

    protected function confirmPush(string $operation): bool
    {
        if (! $this->components->confirm(
            "You are about to push local {$operation} to [{$this->remote->name}]. This will OVERWRITE remote data. Continue?",
            false
        )) {
            return false;
        }

        return $this->components->confirm(
            "Are you SURE you want to push to [{$this->remote->name}]? This action cannot be undone.",
            false
        );
    }

    protected function generateSnapshotName(): string
    {
        return 'remote-sync-'.date('Y-m-d-H-i-s');
    }
}
