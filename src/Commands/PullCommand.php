<?php

namespace Noo\LaravelRemoteSync\Commands;

use Closure;
use Illuminate\Console\Command;
use Noo\LaravelRemoteSync\Commands\Concerns\ResolvesRemote;
use Noo\LaravelRemoteSync\Remotes\Connection;
use Noo\LaravelRemoteSync\Remotes\RemoteInfo;
use Noo\LaravelRemoteSync\Snapshots\Importer;
use Noo\LaravelRemoteSync\Snapshots\Snapshots;
use Noo\LaravelRemoteSync\Support\CleanupStack;
use Noo\LaravelRemoteSync\Sync\Direction;
use Noo\LaravelRemoteSync\Sync\Planner;
use Noo\LaravelRemoteSync\Sync\PlanRenderer;
use Noo\LaravelRemoteSync\Sync\Rsync;
use Noo\LaravelRemoteSync\Sync\SyncPlan;
use RuntimeException;

use function Laravel\Prompts\multiselect;

class PullCommand extends Command
{
    use ResolvesRemote;

    protected $signature = 'remote-sync:pull
        {remote? : The remote environment to pull from}
        {--database : Pull the database}
        {--files : Pull storage files}
        {--full : Import all tables (no exclusions) and drop local tables first}
        {--no-backup : Skip the local backup snapshot}
        {--keep-snapshot : Keep the downloaded snapshot file after import}
        {--delete : Delete local files that do not exist on the remote}
        {--path=* : Sync only these storage-relative paths}
        {--dry-run : Show the plan without changing anything}
        {--f|force : Answer the final confirmation with yes}';

    protected $description = 'Pull the database and/or storage files from a remote environment';

    public function handle(): int
    {
        if (! $this->guardProduction()) {
            return self::FAILURE;
        }

        $remote = $this->resolveRemote();

        if ($remote === null) {
            return self::FAILURE;
        }

        [$database, $files] = $this->resolveScope();

        $connection = new Connection($remote);

        if (! $this->verifyHostKey($connection)) {
            return self::FAILURE;
        }

        $info = $this->probeRemote($connection);

        if ($info === null) {
            return self::FAILURE;
        }

        if ($database && ! $this->checkDatabasePreconditions($remote->name, $info)) {
            return self::FAILURE;
        }

        $paths = [];

        if ($files) {
            $paths = $this->resolvePaths();

            if ($paths === null) {
                return self::FAILURE;
            }

            if ($paths === []) {
                $this->components->warn(__('remote-sync::messages.warnings.no_paths_configured'));
                $files = false;
            }
        }

        if (! $database && ! $files) {
            $this->components->info(__('remote-sync::messages.info.no_operations_selected'));

            return self::SUCCESS;
        }

        $rsync = app(Rsync::class, ['connection' => $connection, 'interactive' => $this->isInteractive(), 'verbose' => $this->output->isVerbose()]);
        $importer = app(Importer::class);
        $snapshots = app(Snapshots::class, ['connection' => $connection, 'info' => $info]);
        $planner = new Planner($info, $rsync, $importer);

        try {
            $plan = $this->buildPlan(fn (): SyncPlan => $planner->pull(
                database: $database,
                files: $files,
                full: (bool) $this->option('full'),
                backup: ! $this->option('no-backup'),
                keepSnapshot: (bool) $this->option('keep-snapshot'),
                delete: (bool) $this->option('delete'),
                paths: $paths,
            ));
        } catch (RuntimeException $e) {
            $this->components->error($e->getMessage());

            return self::FAILURE;
        }

        (new PlanRenderer($this->getOutput()))->render($remote, $info, $plan);

        if ($this->option('dry-run')) {
            $this->components->info(__('remote-sync::messages.info.dry_run_done'));

            return self::SUCCESS;
        }

        if (! $this->confirmPlan(__('remote-sync::prompts.confirm.pull', [
            'scope' => $plan->scopeSummary(),
            'name' => $remote->name,
        ]))) {
            if ($this->isInteractive()) {
                $this->components->info(__('remote-sync::messages.info.operation_cancelled'));
            }

            return $this->isInteractive() ? self::SUCCESS : self::FAILURE;
        }

        return $this->executePlan($remote->name, $info, $plan, $snapshots, $importer, $rsync);
    }

    /**
     * @return array{0: bool, 1: bool}
     */
    protected function resolveScope(): array
    {
        if ($this->option('database') || $this->option('files')) {
            return [(bool) $this->option('database'), (bool) $this->option('files')];
        }

        if (! $this->isInteractive() || $this->option('force')) {
            return [true, true];
        }

        $selected = multiselect(
            label: __('remote-sync::prompts.operations.pull_label'),
            options: [
                'database' => __('remote-sync::prompts.operations.database'),
                'files' => __('remote-sync::prompts.operations.files'),
            ],
            default: ['database', 'files'],
            required: true,
        );

        return [in_array('database', $selected, true), in_array('files', $selected, true)];
    }

    protected function executePlan(
        string $remoteName,
        RemoteInfo $info,
        SyncPlan $plan,
        Snapshots $snapshots,
        Importer $importer,
        Rsync $rsync
    ): int {
        $cleanup = new CleanupStack;

        $this->trap([SIGINT, SIGTERM], function () use ($cleanup) {
            $this->newLine();
            $this->components->warn(__('remote-sync::messages.warnings.interrupt_cleanup'));
            $this->runCleanup($cleanup);
            exit(1);
        });

        try {
            if (! $this->runSteps($this->buildSteps($remoteName, $info, $plan, $snapshots, $importer, $rsync, $cleanup))) {
                return self::FAILURE;
            }
        } finally {
            $this->runCleanup($cleanup);
        }

        $this->components->success(__('remote-sync::messages.success.pulled', [
            'scope' => $plan->scopeSummary(),
            'name' => $remoteName,
        ]));

        return self::SUCCESS;
    }

    /**
     * @return list<array{label: string, run: Closure(): ?string}>
     */
    protected function buildSteps(
        string $remoteName,
        RemoteInfo $info,
        SyncPlan $plan,
        Snapshots $snapshots,
        Importer $importer,
        Rsync $rsync,
        CleanupStack $cleanup
    ): array {
        $steps = [];

        if ($plan->database) {
            if ($plan->backup) {
                $steps[] = [
                    'label' => "Creating local backup {$plan->backupName}",
                    'run' => function () use ($snapshots, $plan): ?string {
                        return $snapshots->createLocal($plan->backupName) === 0
                            ? null
                            : __('remote-sync::messages.errors.failed_local_backup');
                    },
                ];
            }

            $steps[] = [
                'label' => "Creating snapshot on [{$remoteName}]",
                'run' => function () use ($snapshots, $plan, $cleanup, $remoteName): ?string {
                    $result = $snapshots->createRemote(
                        $plan->snapshotName,
                        $plan->full ? [] : Importer::excludedTables()
                    );

                    if (! $result->successful()) {
                        return __('remote-sync::messages.errors.failed_remote_snapshot', ['error' => trim($result->errorOutput())]);
                    }

                    $cleanup->push("temporary snapshot on [{$remoteName}]", function () use ($snapshots, $plan) {
                        if (! $snapshots->deleteRemote($plan->snapshotName)->successful()) {
                            throw new RuntimeException("delete it manually: {$plan->snapshotName}");
                        }
                    });

                    return null;
                },
            ];

            $steps[] = [
                'label' => "Downloading snapshot from [{$remoteName}]",
                'run' => function () use ($snapshots, $plan, $cleanup): ?string {
                    $result = $snapshots->download($plan->snapshotName);

                    if (! $result->successful()) {
                        return __('remote-sync::messages.errors.failed_download_snapshot', ['error' => trim($result->errorOutput())]);
                    }

                    if (! $plan->keepSnapshot) {
                        $cleanup->push('downloaded snapshot file', fn () => Snapshots::deleteLocal($plan->snapshotName));
                    }

                    return null;
                },
            ];

            $steps[] = [
                'label' => 'Verifying snapshot integrity',
                'run' => function () use ($plan): ?string {
                    return Snapshots::verifyGzip($plan->snapshotName)
                        ? null
                        : __('remote-sync::messages.errors.corrupt_snapshot');
                },
            ];

            $steps[] = [
                'label' => 'Importing database',
                'run' => function () use ($importer, $plan, $cleanup): ?string {
                    try {
                        $result = $importer->import($plan->snapshotName, dropTables: $plan->full);
                    } catch (RuntimeException $e) {
                        $this->noteImportFailure($plan, $cleanup);

                        return $e->getMessage();
                    }

                    if (! $result->successful()) {
                        $this->noteImportFailure($plan, $cleanup);

                        return __('remote-sync::messages.errors.failed_import', ['error' => trim($result->errorOutput())]);
                    }

                    if (! $plan->full) {
                        $importer->truncateExcluded();
                    }

                    $filtered = $importer->filterUsers();

                    if ($filtered !== null && $filtered['skipped']) {
                        $this->components->warn(__('remote-sync::messages.warnings.filter_users_skipped'));
                    } elseif ($filtered !== null) {
                        $this->components->info(__('remote-sync::messages.info.filter_users_applied', [
                            'kept' => $filtered['kept'],
                            'deleted' => $filtered['deleted'],
                        ]));
                    }

                    return null;
                },
            ];

            $steps[] = [
                'label' => 'Running migrations and clearing caches',
                'run' => function (): ?string {
                    if ($this->callSilently('migrate', ['--force' => true]) !== 0) {
                        $this->components->warn(__('remote-sync::messages.warnings.migrations_failed'));
                    }

                    $this->callSilently('optimize:clear');

                    return null;
                },
            ];
        }

        foreach ($plan->paths as $path) {
            $steps[] = [
                'label' => "Syncing files storage/{$path}",
                'run' => function () use ($rsync, $info, $plan, $path): ?string {
                    $localPath = storage_path($path);

                    if (! is_dir($localPath)) {
                        mkdir($localPath, 0755, true);
                    }

                    $result = $rsync->transfer(
                        Direction::Pull,
                        "{$info->storagePath()}/{$path}/",
                        rtrim($localPath, '/').'/',
                        excludes: $plan->fileExcludes[$path] ?? [],
                        delete: $plan->delete,
                    );

                    return $result->successful()
                        ? null
                        : __('remote-sync::messages.errors.failed_file_sync', ['path' => $path, 'error' => trim($result->errorOutput())]);
                },
            ];
        }

        return $steps;
    }

    protected function noteImportFailure(SyncPlan $plan, CleanupStack $cleanup): void
    {
        $cleanup->forget('downloaded snapshot file');

        if ($plan->backup) {
            $this->failureNotes[] = __('remote-sync::messages.info.restore_hint', ['name' => $plan->backupName]);
        }

        $this->failureNotes[] = __('remote-sync::messages.info.snapshot_kept', [
            'path' => Snapshots::localPath($plan->snapshotName),
        ]);
    }
}
