<?php

namespace Noo\LaravelRemoteSync\Concerns;

use Noo\LaravelRemoteSync\Data\RemoteConfig;
use Noo\LaravelRemoteSync\RemoteSyncService;

use function Laravel\Prompts\select;
use function Laravel\Prompts\text;

trait InteractsWithRemote
{
    protected RemoteSyncService $syncService;

    protected RemoteConfig $remote;

    protected function initializeRemote(?string $remoteName): void
    {
        $this->syncService = app(RemoteSyncService::class);
        $this->remote = $this->syncService->getRemote($remoteName);

        if ($this->remote->isAtomic === null) {
            $isAtomic = $this->syncService->isAtomicDeployment($this->remote);
            $this->remote = $this->remote->withAtomicDetection($isAtomic);
        }
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
        return $this->confirmWithTypedYes(
            "This will replace your local {$operation} with data from [{$this->remote->name}]. Type \"yes\" to continue"
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
        $this->components->warn("You are about to push local {$operation} to [{$this->remote->name}]. This will OVERWRITE remote data.");

        return $this->confirmWithTypedYes(
            "Are you SURE you want to push to [{$this->remote->name}]? Type \"yes\" to continue"
        );
    }

    protected function confirmWithTypedYes(string $label): bool
    {
        $response = text(
            label: $label,
            placeholder: 'yes',
            required: true,
            validate: function (string $value) {
                if ($value !== 'yes') {
                    return 'Type "yes" to confirm';
                }

                return null;
            }
        );

        return $response === 'yes';
    }

    protected function wasOptionProvided(string $option): bool
    {
        $definition = $this->getDefinition();

        if (! $definition->hasOption($option)) {
            return false;
        }

        $default = $definition->getOption($option)->getDefault();

        return $this->option($option) !== $default;
    }

    protected function shouldSkipPrompts(): bool
    {
        return $this->option('force') === true;
    }

    protected function promptBackupOption(): bool
    {
        if ($this->shouldSkipPrompts() || $this->option('no-backup')) {
            return ! $this->option('no-backup');
        }

        $choice = select(
            label: 'Create a local backup before syncing?',
            options: [
                'yes' => 'Yes (recommended)',
                'no' => 'No',
            ],
            default: 'yes',
        );

        return $choice === 'yes';
    }

    protected function promptImportMode(): bool
    {
        if ($this->shouldSkipPrompts() || $this->option('full')) {
            return (bool) $this->option('full');
        }

        $choice = select(
            label: 'Import mode',
            options: [
                'standard' => 'Standard - excludes cache/session tables',
                'full' => 'Full - all tables, drops existing tables first',
            ],
            default: 'standard',
        );

        return $choice === 'full';
    }

    protected function promptKeepSnapshot(): bool
    {
        if ($this->shouldSkipPrompts() || $this->option('keep-snapshot')) {
            return (bool) $this->option('keep-snapshot');
        }

        $choice = select(
            label: 'Keep snapshot file after import?',
            options: [
                'no' => 'No (recommended)',
                'yes' => 'Yes - keep for debugging',
            ],
            default: 'no',
        );

        return $choice === 'yes';
    }

    protected function promptDeleteOption(string $context = 'local'): bool
    {
        if ($this->shouldSkipPrompts() || $this->option('delete')) {
            return (bool) $this->option('delete');
        }

        $label = $context === 'local'
            ? 'Delete local files not on remote?'
            : 'Delete remote files not present locally?';

        $choice = select(
            label: $label,
            options: [
                'no' => 'No - keep extra files (recommended)',
                'yes' => 'Yes - mirror exactly',
            ],
            default: 'no',
        );

        return $choice === 'yes';
    }

    protected function promptDryRunOption(): bool
    {
        if ($this->shouldSkipPrompts() || $this->option('dry-run')) {
            return (bool) $this->option('dry-run');
        }

        $choice = select(
            label: 'Preview changes first?',
            options: [
                'no' => 'No - proceed directly',
                'yes' => 'Yes - dry run first',
            ],
            default: 'no',
        );

        return $choice === 'yes';
    }

    protected function promptPathSelection(): ?string
    {
        $pathOption = $this->option('path');

        if ($this->shouldSkipPrompts() || $pathOption !== null) {
            return $pathOption;
        }

        $configuredPaths = config('remote-sync.paths', []);
        $pathsDisplay = implode(', ', $configuredPaths) ?: 'none configured';

        $choice = select(
            label: 'Which paths to sync?',
            options: [
                'all' => "All configured paths ({$pathsDisplay})",
                'specific' => 'Specific path only',
            ],
            default: 'all',
        );

        if ($choice === 'specific') {
            return text(
                label: 'Enter path (relative to storage/)',
                placeholder: 'app/public',
                required: true,
            );
        }

        return null;
    }

    protected function generateSnapshotName(): string
    {
        return 'remote-sync-'.date('Y-m-d-H-i-s');
    }
}
