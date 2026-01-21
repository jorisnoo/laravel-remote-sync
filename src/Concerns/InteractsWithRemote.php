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
            $this->components->error(__('remote-sync::messages.errors.production_not_allowed'));

            return false;
        }

        return true;
    }

    protected function confirmSync(string $operation): bool
    {
        return $this->confirmWithTypedYes(
            __('remote-sync::prompts.confirm.sync', ['operation' => $operation, 'name' => $this->remote->name])
        );
    }

    protected function ensurePushAllowed(): bool
    {
        if (! $this->remote->pushAllowed) {
            $this->components->error(__('remote-sync::messages.errors.push_not_allowed', ['name' => $this->remote->name]));

            return false;
        }

        return true;
    }

    protected function confirmPush(string $operation): bool
    {
        $this->components->warn(__('remote-sync::messages.push.overwrite_warning', ['operation' => $operation, 'name' => $this->remote->name]));

        return $this->confirmWithTypedYes(
            __('remote-sync::prompts.confirm.push', ['name' => $this->remote->name])
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
                    return __('remote-sync::prompts.confirm.validation');
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
            label: __('remote-sync::prompts.backup.label'),
            options: [
                'yes' => __('remote-sync::prompts.backup.yes'),
                'no' => __('remote-sync::prompts.backup.no'),
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
            label: __('remote-sync::prompts.import_mode.label'),
            options: [
                'standard' => __('remote-sync::prompts.import_mode.standard'),
                'full' => __('remote-sync::prompts.import_mode.full'),
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
            label: __('remote-sync::prompts.keep_snapshot.label'),
            options: [
                'no' => __('remote-sync::prompts.keep_snapshot.no'),
                'yes' => __('remote-sync::prompts.keep_snapshot.yes'),
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
            ? __('remote-sync::prompts.delete.local_label')
            : __('remote-sync::prompts.delete.remote_label');

        $choice = select(
            label: $label,
            options: [
                'yes' => __('remote-sync::prompts.delete.yes'),
                'no' => __('remote-sync::prompts.delete.no'),
            ],
            default: 'yes',
        );

        return $choice === 'yes';
    }

    protected function promptDryRunOption(): bool
    {
        if ($this->shouldSkipPrompts() || $this->option('dry-run')) {
            return (bool) $this->option('dry-run');
        }

        $choice = select(
            label: __('remote-sync::prompts.dry_run.label'),
            options: [
                'no' => __('remote-sync::prompts.dry_run.no'),
                'yes' => __('remote-sync::prompts.dry_run.yes'),
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
            label: __('remote-sync::prompts.paths.label'),
            options: [
                'all' => __('remote-sync::prompts.paths.all', ['paths' => $pathsDisplay]),
                'specific' => __('remote-sync::prompts.paths.specific'),
            ],
            default: 'all',
        );

        if ($choice === 'specific') {
            return text(
                label: __('remote-sync::prompts.paths.enter_label'),
                placeholder: __('remote-sync::prompts.paths.placeholder'),
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
