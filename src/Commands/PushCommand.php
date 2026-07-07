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
use function Laravel\Prompts\spin;

class PushCommand extends Command
{
    use ResolvesRemote;

    protected $signature = 'remote-sync:push
        {remote? : The remote environment to push to}
        {--database : Push the database}
        {--files : Push storage files}
        {--no-backup : Skip the remote backup snapshot}
        {--delete : Delete remote files that do not exist locally}
        {--path=* : Sync only these storage-relative paths}
        {--dry-run : Show the plan without changing anything}
        {--f|force : Answer the final confirmation with yes}';

    protected $description = 'Push the local database and/or storage files to a remote environment';

    public function handle(): int
    {
        if (! $this->guardProduction()) {
            return self::FAILURE;
        }

        $remote = $this->resolveRemote();

        if ($remote === null) {
            return self::FAILURE;
        }

        if (! $remote->push) {
            $this->components->error(__('remote-sync::messages.errors.push_not_allowed', ['name' => $remote->name]));

            return self::FAILURE;
        }

        $scope = $this->resolveScope();

        if ($scope === null) {
            return self::FAILURE;
        }

        [$database, $files] = $scope;

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

        $rsync = app(Rsync::class, ['connection' => $connection, 'interactive' => $this->isInteractive()]);
        $importer = app(Importer::class);
        $snapshots = app(Snapshots::class, ['connection' => $connection, 'info' => $info]);
        $planner = new Planner($info, $rsync, $importer);

        try {
            $plan = spin(
                callback: fn (): SyncPlan => $planner->push(
                    database: $database,
                    files: $files,
                    backup: ! $this->option('no-backup'),
                    delete: (bool) $this->option('delete'),
                    paths: $paths,
                ),
                message: __('remote-sync::messages.spinners.building_plan'),
            );
        } catch (RuntimeException $e) {
            $this->components->error($e->getMessage());

            return self::FAILURE;
        }

        (new PlanRenderer($this->getOutput()))->render($remote, $info, $plan);

        if ($this->option('dry-run')) {
            $this->components->info(__('remote-sync::messages.info.dry_run_done'));

            return self::SUCCESS;
        }

        if (! $this->confirmPush($remote->name, $plan)) {
            if ($this->isInteractive()) {
                $this->components->info(__('remote-sync::messages.info.operation_cancelled'));

                return self::SUCCESS;
            }

            return self::FAILURE;
        }

        return $this->executePlan($remote->name, $connection, $info, $plan, $snapshots, $rsync);
    }

    /**
     * Pushing overwrites shared data, so the scope must be explicit in
     * scripts and the interactive confirmation is always a typed yes.
     *
     * @return array{0: bool, 1: bool}|null
     */
    protected function resolveScope(): ?array
    {
        if ($this->option('database') || $this->option('files')) {
            return [(bool) $this->option('database'), (bool) $this->option('files')];
        }

        if (! $this->isInteractive()) {
            $this->components->error(__('remote-sync::messages.errors.push_scope_required'));

            return null;
        }

        $selected = multiselect(
            label: __('remote-sync::prompts.operations.push_label'),
            options: [
                'database' => __('remote-sync::prompts.operations.database'),
                'files' => __('remote-sync::prompts.operations.files'),
            ],
            default: ['database'],
            required: true,
        );

        return [in_array('database', $selected, true), in_array('files', $selected, true)];
    }

    protected function confirmPush(string $remoteName, SyncPlan $plan): bool
    {
        if ($this->option('force')) {
            return true;
        }

        if (! $this->isInteractive()) {
            $this->components->error(__('remote-sync::messages.errors.confirmation_required'));

            return false;
        }

        return $this->confirmWithTypedYes(__('remote-sync::prompts.confirm.push', [
            'scope' => $plan->scopeSummary(),
            'name' => $remoteName,
        ]));
    }

    protected function executePlan(
        string $remoteName,
        Connection $connection,
        RemoteInfo $info,
        SyncPlan $plan,
        Snapshots $snapshots,
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
            if (! $this->runSteps($this->buildSteps($remoteName, $connection, $info, $plan, $snapshots, $rsync, $cleanup))) {
                return self::FAILURE;
            }
        } finally {
            $this->runCleanup($cleanup);
        }

        $this->components->success(__('remote-sync::messages.success.pushed', [
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
        Connection $connection,
        RemoteInfo $info,
        SyncPlan $plan,
        Snapshots $snapshots,
        Rsync $rsync,
        CleanupStack $cleanup
    ): array {
        $steps = [];

        if ($plan->database) {
            if ($plan->backup) {
                $steps[] = [
                    'label' => "Creating remote backup {$plan->backupName} on [{$remoteName}]",
                    'run' => function () use ($snapshots, $plan): ?string {
                        $result = $snapshots->createRemote($plan->backupName, Importer::excludedTables());

                        return $result->successful()
                            ? null
                            : __('remote-sync::messages.errors.failed_remote_backup', ['error' => trim($result->errorOutput())]);
                    },
                ];
            }

            $steps[] = [
                'label' => 'Creating local snapshot',
                'run' => function () use ($snapshots, $plan, $cleanup): ?string {
                    if ($snapshots->createLocal($plan->snapshotName, Importer::excludedTables()) !== 0) {
                        return __('remote-sync::messages.errors.failed_local_snapshot');
                    }

                    $cleanup->push('local snapshot file', fn () => Snapshots::deleteLocal($plan->snapshotName));

                    return null;
                },
            ];

            $steps[] = [
                'label' => "Uploading snapshot to [{$remoteName}]",
                'run' => function () use ($snapshots, $plan, $cleanup, $remoteName): ?string {
                    $result = $snapshots->upload($plan->snapshotName);

                    if (! $result->successful()) {
                        return __('remote-sync::messages.errors.failed_upload_snapshot', ['error' => trim($result->errorOutput())]);
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
                'label' => "Loading snapshot on [{$remoteName}]",
                'run' => function () use ($snapshots, $plan, $remoteName): ?string {
                    $result = $snapshots->loadRemote($plan->snapshotName);

                    if (! $result->successful()) {
                        if ($plan->backup) {
                            $this->failureNotes[] = __('remote-sync::messages.info.remote_restore_hint', [
                                'name' => $remoteName,
                                'backup' => $plan->backupName,
                            ]);
                        }

                        return __('remote-sync::messages.errors.failed_remote_load', ['error' => trim($result->errorOutput())]);
                    }

                    return null;
                },
            ];

            $steps[] = [
                'label' => "Running migrations on [{$remoteName}]",
                'run' => function () use ($connection, $info): ?string {
                    $result = $connection->artisan($info, 'migrate --force');

                    if (! $result->successful()) {
                        $this->components->warn(__('remote-sync::messages.errors.remote_migrations_failed'));
                    }

                    return null;
                },
            ];
        }

        foreach ($plan->paths as $path) {
            $steps[] = [
                'label' => "Syncing files storage/{$path} to [{$remoteName}]",
                'run' => function () use ($rsync, $info, $plan, $path): ?string {
                    $localPath = storage_path($path);

                    if (! is_dir($localPath)) {
                        $this->components->warn(__('remote-sync::messages.warnings.local_path_not_exists', ['path' => $path]));

                        return null;
                    }

                    $result = $rsync->transfer(
                        Direction::Push,
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
}
