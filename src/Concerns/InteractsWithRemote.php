<?php

namespace Noo\LaravelRemoteSync\Concerns;

use Noo\LaravelRemoteSync\Data\RemoteConfig;
use Noo\LaravelRemoteSync\RemoteSyncService;

use function Laravel\Prompts\confirm;
use function Laravel\Prompts\select;
use function Laravel\Prompts\spin;
use function Laravel\Prompts\table;
use function Laravel\Prompts\text;

trait InteractsWithRemote
{
    protected RemoteSyncService $syncService;

    protected RemoteConfig $remote;

    protected string $snapshotName;

    protected bool $remoteSnapshotCreated = false;

    /** @var array<int, string> */
    protected array $remoteTables = [];

    /** @var array<int, string> */
    protected array $localTables = [];

    /** @var array{local_only: array<int, string>, remote_only: array<int, string>} */
    protected array $migrationDiff = ['local_only' => [], 'remote_only' => []];

    protected bool $includeMigrations = false;

    /** @var array{driver: string|null, tables: list<string>, migrations: list<string>}|null */
    protected ?array $remoteDatabaseInfo = null;

    protected ?string $specificPath = null;

    protected int $filesToTransfer = 0;

    protected int $filesToDelete = 0;

    protected array $transferFiles = [];

    protected array $deleteFiles = [];

    protected function selectRemote(?string $label = null): ?string
    {
        $remotes = app(RemoteSyncService::class)->getAvailableRemotes();

        if (empty($remotes)) {
            return null;
        }

        if (count($remotes) === 1) {
            return $remotes[0];
        }

        return select(
            label: $label ?? __('remote-sync::prompts.remote.label'),
            options: $remotes,
            default: config('remote-sync.default'),
        );
    }

    protected function initializeRemote(?string $remoteName): bool
    {
        $this->syncService = app(RemoteSyncService::class);
        $this->remote = $this->syncService->getRemote($remoteName);

        if (! $this->verifyHostKey()) {
            return false;
        }

        if ($this->remote->isAtomic === null) {
            $isAtomic = $this->syncService->isAtomicDeployment($this->remote);
            $this->remote = $this->remote->withAtomicDetection($isAtomic);
        }

        return true;
    }

    protected function verifyHostKey(): bool
    {
        $status = spin(
            callback: fn () => $this->syncService->checkHostKey($this->remote),
            message: __('remote-sync::messages.spinners.verifying_host')
        );

        if ($status === 'ok') {
            return true;
        }

        $hostname = $this->syncService->extractHostname($this->remote->host);

        if ($status === 'changed') {
            $this->components->error(__('remote-sync::messages.errors.host_key_changed', ['host' => $hostname]));

            return false;
        }

        // status === 'unknown'
        $this->components->warn(__('remote-sync::messages.warnings.unknown_host', ['host' => $hostname]));

        $fingerprints = $this->syncService->getHostFingerprints($this->remote);

        if ($fingerprints) {
            $this->newLine();
            $rows = collect(explode("\n", $fingerprints))
                ->filter()
                ->map(function (string $line) {
                    // Format: "2048 SHA256:abc123... hostname (RSA)"
                    if (preg_match('/^\d+\s+(SHA256:\S+)\s+.*\((\w+)\)$/', trim($line), $matches)) {
                        return [$matches[2], $matches[1]];
                    }

                    return null;
                })
                ->filter()
                ->values()
                ->all();

            if (! empty($rows)) {
                table(['Type', 'Fingerprint'], $rows);
            }

            $this->newLine();
        }

        if (! confirm(label: __('remote-sync::prompts.confirm.accept_host_key', ['host' => $hostname]), default: false)) {
            return false;
        }

        $accepted = spin(
            callback: fn () => $this->syncService->acceptHostKey($this->remote),
            message: __('remote-sync::messages.spinners.accepting_host_key')
        );

        if (! $accepted) {
            $this->components->error(__('remote-sync::messages.errors.host_key_failed'));

            return false;
        }

        $this->components->info(__('remote-sync::messages.info.host_key_accepted', ['host' => $hostname]));

        return true;
    }

    protected function ensureNotProduction(): bool
    {
        if (app()->isProduction()) {
            $this->components->error(__('remote-sync::messages.errors.production_not_allowed'));

            return false;
        }

        return true;
    }

    protected function confirmPull(string $operation): bool
    {
        return $this->confirmWithTypedYes(
            __('remote-sync::prompts.confirm.pull', ['operation' => $operation, 'name' => $this->remote->name])
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
        return (bool) $this->option('keep-snapshot');
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
        return 'remote-sync-'.date('Y-m-d-H-i-s').'-'.bin2hex(random_bytes(4));
    }

    /**
     * @return array{driver: string|null, tables: list<string>, migrations: list<string>}
     */
    protected function fetchRemoteDatabaseInfo(): array
    {
        if ($this->remoteDatabaseInfo !== null) {
            return $this->remoteDatabaseInfo;
        }

        $this->remoteDatabaseInfo = spin(
            callback: fn () => $this->syncService->getRemoteDatabaseInfo($this->remote),
            message: __('remote-sync::messages.spinners.fetching_database_info')
        );

        return $this->remoteDatabaseInfo;
    }

    protected function validateDatabaseCompatibility(string $direction): bool
    {
        $localDriver = config('database.connections.'.config('database.default').'.driver');

        $remoteDriver = $this->fetchRemoteDatabaseInfo()['driver'];

        if ($remoteDriver === null) {
            $this->components->warn(__('remote-sync::messages.warnings.driver_detection_failed'));

            if (! $this->shouldSkipPrompts()
                && ! confirm(label: __('remote-sync::prompts.confirm.continue_without_driver'), default: false)) {
                return false;
            }

            return true;
        }

        $normalize = fn (string $d) => match (strtolower($d)) {
            'mariadb' => 'mysql',
            default => strtolower($d),
        };

        $normalizedLocal = $normalize($localDriver);
        $normalizedRemote = $normalize($remoteDriver);

        if ($normalizedLocal !== $normalizedRemote) {
            $errorKey = $direction === 'push'
                ? 'remote-sync::messages.errors.driver_mismatch_push'
                : 'remote-sync::messages.errors.driver_mismatch_pull';

            $this->components->error(
                __($errorKey, ['remote' => $remoteDriver, 'local' => $localDriver])
            );
            $this->components->error(
                __('remote-sync::messages.errors.cross_database_not_supported')
            );

            return false;
        }

        return true;
    }

    protected function hasMigrationMismatch(): bool
    {
        return ! empty($this->migrationDiff['local_only']) || ! empty($this->migrationDiff['remote_only']);
    }

    protected function getConfiguredPaths(): array
    {
        if ($this->specificPath !== null) {
            return [$this->specificPath];
        }

        return config('remote-sync.paths', []);
    }

    protected function cleanupLocalSnapshotFile(): void
    {
        $snapshotPath = $this->syncService->getSnapshotPath()."/{$this->snapshotName}.sql.gz";

        if (file_exists($snapshotPath)) {
            unlink($snapshotPath);
            $this->components->info(__('remote-sync::messages.info.local_snapshot_removed'));
        }
    }

    protected function cleanupRemoteSnapshot(): void
    {
        if (! $this->remoteSnapshotCreated) {
            return;
        }

        $result = spin(
            callback: fn () => $this->syncService->deleteRemoteSnapshot($this->remote, $this->snapshotName),
            message: __('remote-sync::messages.spinners.cleaning_remote_snapshot')
        );

        if (! $result->successful()) {
            $this->components->warn(__('remote-sync::messages.warnings.manual_cleanup_needed', ['name' => $this->snapshotName]));
        }
    }

    /**
     * Display a database sync preview.
     *
     * @param  array<int, string>  $sourceTables
     * @param  array<int, string>  $targetTables
     * @param  array<int, string>  $excludedTables
     * @param  array{local_only: array<int, string>, remote_only: array<int, string>}  $migrationDiff
     */
    protected function displayDatabasePreview(
        array $sourceTables,
        array $targetTables,
        array $excludedTables,
        array $migrationDiff,
        bool $fullMode,
        string $direction = 'pull'
    ): void {
        $includeMigrationsInSync = $direction === 'pull' && ! $fullMode;

        $allExcluded = $includeMigrationsInSync
            ? $excludedTables
            : array_unique(array_merge($excludedTables, RemoteSyncService::ALWAYS_PRESERVED_TABLES));

        $headerKey = $direction === 'push'
            ? 'remote-sync::messages.preview.database_push_header'
            : 'remote-sync::messages.preview.database_pull_header';

        $tablesToSync = $fullMode
            ? $sourceTables
            : array_values(array_diff($sourceTables, $allExcluded));

        sort($tablesToSync);

        $syncCount = count($tablesToSync);

        $this->newLine();
        $this->components->info(__($headerKey));

        $syncLabelKey = $fullMode
            ? 'remote-sync::messages.preview.syncing_tables_full'
            : 'remote-sync::messages.preview.syncing_tables';

        $this->components->twoColumnDetail(
            __($syncLabelKey),
            (string) $syncCount
        );
        $this->line('  '.implode(', ', $tablesToSync));

        if ($direction === 'push' && ! $fullMode) {
            $staleRemoteTables = array_values(array_diff($targetTables, $sourceTables, $allExcluded));

            if (! empty($staleRemoteTables)) {
                sort($staleRemoteTables);
                $staleCount = count($staleRemoteTables);

                $this->newLine();
                $this->components->warn(trans_choice(__('remote-sync::messages.preview.stale_remote_tables'), $staleCount, ['count' => $staleCount]));
                $this->line('  '.implode(', ', $staleRemoteTables));
            }
        }

        if (! $fullMode && ! empty($excludedTables)) {
            $existingExcluded = array_values(array_filter(
                $excludedTables,
                fn (string $table) => in_array($table, $targetTables, true)
            ));

            if (! empty($existingExcluded)) {
                sort($existingExcluded);

                $excludedCount = count($existingExcluded);

                $excludedLabelKey = $direction === 'push'
                    ? 'remote-sync::messages.preview.excluded_tables_preserved'
                    : 'remote-sync::messages.preview.excluded_tables_truncate';

                $this->newLine();
                $this->components->twoColumnDetail(
                    __($excludedLabelKey),
                    (string) $excludedCount
                );
                $this->line('  '.implode(', ', $existingExcluded));
            }
        }

        $filterUsers = config('remote-sync.filter_users', false);
        if ($direction === 'pull' && is_array($filterUsers) && ! empty($filterUsers)) {
            $this->components->warn(__('remote-sync::messages.preview.filter_users', ['count' => count($filterUsers)]));
        }

        $this->newLine();

        $localOnly = $migrationDiff['local_only'] ?? [];
        $remoteOnly = $migrationDiff['remote_only'] ?? [];

        if ($includeMigrationsInSync) {
            $this->components->twoColumnDetail(
                __('remote-sync::messages.preview.migrations'),
                __('remote-sync::messages.preview.migrations_will_run')
            );

            if (! empty($localOnly)) {
                $localCount = count($localOnly);
                $this->components->twoColumnDetail(
                    trans_choice(__('remote-sync::messages.preview.migrations_local_only'), $localCount, ['count' => $localCount]),
                    ''
                );
                foreach ($localOnly as $migration) {
                    $this->line('    - '.$migration);
                }
            }

            if (! empty($remoteOnly)) {
                $remoteCount = count($remoteOnly);
                $this->components->warn(trans_choice(__('remote-sync::messages.preview.migrations_remote_only_warning'), $remoteCount, ['count' => $remoteCount]));
                foreach ($remoteOnly as $migration) {
                    $this->line('    - '.$migration);
                }
            }
        } elseif ($fullMode) {
            $this->components->twoColumnDetail(
                __('remote-sync::messages.preview.migrations'),
                __('remote-sync::messages.preview.migrations_differ_full')
            );
        } elseif (! empty($localOnly) || ! empty($remoteOnly)) {
            $this->components->warn(__('remote-sync::messages.preview.migrations_differ'));

            if (! empty($localOnly)) {
                $localCount = count($localOnly);
                $this->components->twoColumnDetail(
                    trans_choice(__('remote-sync::messages.preview.migrations_local_only'), $localCount, ['count' => $localCount]),
                    ''
                );
                foreach ($localOnly as $migration) {
                    $this->line('    - '.$migration);
                }
            }

            if (! empty($remoteOnly)) {
                $remoteCount = count($remoteOnly);
                $this->components->twoColumnDetail(
                    trans_choice(__('remote-sync::messages.preview.migrations_remote_only'), $remoteCount, ['count' => $remoteCount]),
                    ''
                );
                foreach ($remoteOnly as $migration) {
                    $this->line('    - '.$migration);
                }
            }
        } else {
            $this->components->twoColumnDetail(
                __('remote-sync::messages.preview.migrations'),
                __('remote-sync::messages.preview.migrations_match')
            );
        }

        $this->newLine();
    }

    /**
     * Compare local and remote migration records.
     *
     * @return array{local_only: array<int, string>, remote_only: array<int, string>}
     */
    protected function compareMigrations(): array
    {
        $localMigrations = $this->syncService->getLocalMigrationRecords();
        $remoteMigrations = $this->fetchRemoteDatabaseInfo()['migrations'];

        return [
            'local_only' => array_values(array_diff($localMigrations, $remoteMigrations)),
            'remote_only' => array_values(array_diff($remoteMigrations, $localMigrations)),
        ];
    }

    /**
     * Display a files sync preview.
     */
    protected function displayFilesPreview(int $filesToTransfer, int $filesToDelete, string $direction = 'pull', array $transferFiles = [], array $deleteFiles = []): void
    {
        $headerKey = $direction === 'push'
            ? 'remote-sync::messages.preview.files_push_header'
            : 'remote-sync::messages.preview.files_pull_header';

        $this->newLine();
        $this->components->info(__($headerKey));

        $this->components->twoColumnDetail(
            __('remote-sync::messages.preview.files_to_transfer'),
            (string) $filesToTransfer
        );

        if (! empty($transferFiles)) {
            $this->components->bulletList($transferFiles);
        }

        if (! empty($deleteFiles)) {
            $deleteKey = $direction === 'push'
                ? 'remote-sync::messages.warnings.files_to_delete_on_remote'
                : 'remote-sync::messages.warnings.files_to_delete_locally';

            $this->components->warn(trans_choice($deleteKey, $filesToDelete, ['count' => $filesToDelete, 'name' => $this->remote->name]));
            $this->components->bulletList($deleteFiles);
        } else {
            $this->components->twoColumnDetail(
                __('remote-sync::messages.preview.files_to_delete'),
                '0'
            );
        }

        $this->newLine();
    }

    /**
     * Parse rsync dry-run output with itemize-changes to count files.
     *
     * @return array{transfer: int, delete: int, transfer_files: list<string>, delete_files: list<string>}
     */
    protected function parseRsyncDryRunOutput(string $output): array
    {
        $lines = explode("\n", $output);
        $transfer = 0;
        $delete = 0;
        $transferFiles = [];
        $deleteFiles = [];

        foreach ($lines as $line) {
            $line = trim($line);

            if ($line === '' || str_starts_with($line, 'sending') || str_starts_with($line, 'receiving')) {
                continue;
            }

            if (str_starts_with($line, '*deleting')) {
                $delete++;
                $deleteFiles[] = trim(substr($line, strlen('*deleting')));

                continue;
            }

            if (preg_match('/^[<>ch.][fdLDS]/', $line)) {
                if (! str_ends_with($line, '/')) {
                    $transfer++;
                    $transferFiles[] = preg_replace('/^[<>ch.][fdLDS][^ ]* /', '', $line);
                }
            }
        }

        return ['transfer' => $transfer, 'delete' => $delete, 'transfer_files' => $transferFiles, 'delete_files' => $deleteFiles];
    }

    protected function validateStoragePath(string $path): ?string
    {
        $storagePath = storage_path();

        $normalizedPath = str_replace(['../', '..\\'], '', $path);
        $normalizedPath = ltrim($normalizedPath, '/\\');

        $fullPath = $storagePath.DIRECTORY_SEPARATOR.$normalizedPath;
        $realPath = realpath(dirname($fullPath));

        if ($realPath === false) {
            $parentPath = dirname($normalizedPath);

            if ($parentPath !== '.' && $parentPath !== '') {
                return __('remote-sync::messages.errors.invalid_path', ['path' => $path]);
            }

            return null;
        }

        $realStoragePath = realpath($storagePath);

        if ($realStoragePath === false) {
            return __('remote-sync::messages.errors.storage_not_accessible');
        }

        if (! str_starts_with($realPath, $realStoragePath)) {
            return __('remote-sync::messages.errors.path_traversal', ['path' => $path]);
        }

        return null;
    }
}
