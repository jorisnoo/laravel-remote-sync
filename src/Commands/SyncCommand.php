<?php

namespace Noo\LaravelRemoteSync\Commands;

use Closure;
use Illuminate\Console\Command;
use Illuminate\Contracts\Process\ProcessResult;
use Noo\LaravelRemoteSync\Commands\Concerns\InteractsWithRemote;
use Noo\LaravelRemoteSync\Database\Inspector;
use Noo\LaravelRemoteSync\Database\UserFilter;
use Noo\LaravelRemoteSync\Remotes\Connection;
use Noo\LaravelRemoteSync\Support\CleanupStack;
use Noo\LaravelRemoteSync\Support\StoragePath;
use Noo\LaravelRemoteSync\Sync\Direction;
use Noo\LaravelRemoteSync\Sync\Endpoint;
use Noo\LaravelRemoteSync\Sync\LocalEndpoint;
use Noo\LaravelRemoteSync\Sync\Plan;
use Noo\LaravelRemoteSync\Sync\Planner;
use Noo\LaravelRemoteSync\Sync\PlanRenderer;
use Noo\LaravelRemoteSync\Sync\RemoteEndpoint;
use Noo\LaravelRemoteSync\Sync\Rsync;
use RuntimeException;
use Throwable;

use function Laravel\Prompts\multiselect;
use function Laravel\Prompts\spin;

/**
 * Pull and push are one operation with source and target swapped:
 *
 *   back up the target, dump the source, transfer the dump, load it into
 *   the target, migrate, clear the cache, then sync storage files.
 *
 * The subclasses only declare their signature and direction.
 */
abstract class SyncCommand extends Command
{
    use InteractsWithRemote;

    protected Direction $direction;

    /** @var list<string> Printed under a failed step, e.g. how to restore. */
    protected array $failureNotes = [];

    public function handle(Inspector $inspector): int
    {
        if ($this->laravel->isProduction() && ! config('remote-sync.allow_production')) {
            return $this->failWith('Refusing to run in production. Set REMOTE_SYNC_ALLOW_PRODUCTION=true if this machine is meant to sync.');
        }

        $remote = $this->resolveRemote();

        if ($remote === null) {
            return self::FAILURE;
        }

        if ($this->direction === Direction::Push && ! $remote->push) {
            return $this->failWith("Pushing to [{$remote->name}] is not allowed. Set 'push' => true for it in config/remote-sync.php.");
        }

        $scope = $this->resolveScope();

        if ($scope === null) {
            return self::FAILURE;
        }

        [$database, $paths] = $scope;

        if (! $database && $paths === []) {
            $this->components->info('Nothing to sync.');

            return self::SUCCESS;
        }

        $remoteEndpoint = $this->connect($remote);

        if ($remoteEndpoint === null) {
            return self::FAILURE;
        }

        $local = new LocalEndpoint($inspector);
        [$source, $target] = $this->direction === Direction::Pull ? [$remoteEndpoint, $local] : [$local, $remoteEndpoint];
        $rsync = new Rsync(interactive: $this->isInteractive(), verbose: $this->output->isVerbose());

        try {
            if ($database) {
                $this->checkDatabases($local, $remoteEndpoint);
            }

            $plan = $this->plan(new Planner($source, $target, $rsync), $database, $paths);
        } catch (Throwable $e) {
            return $this->failWith($e->getMessage());
        }

        (new PlanRenderer($this->output))->render(
            $plan, $source, $target, "[{$remote->name}] {$remote->host}:{$remoteEndpoint->remote->workingPath}"
        );

        if ($this->option('dry-run')) {
            $this->components->info('Dry run: nothing was changed.');

            return self::SUCCESS;
        }

        $question = $this->direction === Direction::Pull
            ? "Pull the {$plan->scope()} from [{$remote->name}]?"
            : "Push the {$plan->scope()} to [{$remote->name}]?";

        if (! $this->confirmed($question, typed: $this->direction === Direction::Push || $this->laravel->isProduction())) {
            return $this->isInteractive() ? self::SUCCESS : self::FAILURE;
        }

        return $this->executePlan($plan, $source, $target, $rsync) ? self::SUCCESS : self::FAILURE;
    }

    /**
     * The database flag and the storage paths to sync ([] for none), or
     * null when the input is invalid.
     *
     * @return array{0: bool, 1: list<string>}|null
     */
    protected function resolveScope(): ?array
    {
        if ($this->option('database') || $this->option('files')) {
            [$database, $files] = [(bool) $this->option('database'), (bool) $this->option('files')];
        } elseif (! $this->isInteractive() && $this->direction === Direction::Push) {
            $this->components->error('Pass --database and/or --files: pushing needs an explicit scope when not interactive.');

            return null;
        } elseif (! $this->isInteractive() || ($this->option('force') && $this->direction === Direction::Pull)) {
            [$database, $files] = [true, true];
        } else {
            $selected = multiselect(
                label: "What do you want to {$this->direction->value}?",
                options: ['database' => 'Database', 'files' => 'Storage files'],
                default: $this->direction === Direction::Pull ? ['database', 'files'] : ['database'],
                required: true,
            );

            [$database, $files] = [in_array('database', $selected, true), in_array('files', $selected, true)];
        }

        $paths = $files ? $this->resolvePaths() : [];

        if ($paths === null) {
            return null;
        }

        if ($files && $paths === []) {
            $this->components->warn('No storage paths are configured, skipping files.');
        }

        return [$database, $paths];
    }

    /**
     * @return list<string>|null null when a path is invalid
     */
    protected function resolvePaths(): ?array
    {
        $paths = array_values(array_unique(array_map(
            StoragePath::normalize(...),
            $this->option('path') ?: config('remote-sync.paths', []),
        )));

        foreach ($paths as $path) {
            if (($error = StoragePath::validate($path)) !== null) {
                $this->components->error($error);

                return null;
            }
        }

        return $paths;
    }

    /**
     * @throws RuntimeException when the databases cannot be synced
     */
    protected function checkDatabases(LocalEndpoint $local, RemoteEndpoint $remote): void
    {
        foreach ([$local, $remote] as $side) {
            $info = $side->info();

            if (! $info->supportsDatabaseSync()) {
                throw new RuntimeException("The {$side->label()} database uses {$info->driver}; only mysql and pgsql can be synced.");
            }

            if ($info->missingBinaries !== []) {
                throw new RuntimeException('Not installed on '.$side->label().': '.implode(', ', $info->missingBinaries).'.');
            }
        }

        if ($local->info()->driver !== $remote->info()->driver) {
            throw new RuntimeException("The {$remote->label()} database uses {$remote->info()->driver} and the local one {$local->info()->driver}; both must use the same driver.");
        }
    }

    /**
     * @param  list<string>  $paths
     */
    protected function plan(Planner $planner, bool $database, array $paths): Plan
    {
        $build = fn () => new Plan(
            direction: $this->direction,
            database: $database ? $planner->database(
                $this->direction,
                full: $this->hasOption('full') && $this->option('full'),
                backup: ! $this->option('no-backup'),
                keepTransfer: (bool) $this->option('keep-dump'),
            ) : null,
            files: $paths !== [] ? $planner->files($paths, delete: (bool) $this->option('delete')) : null,
        );

        // Verbose runs print rsync's dry-run output, which a spinner would garble.
        return $this->output->isVerbose() ? $build() : spin($build, 'Building the plan...');
    }

    protected function executePlan(Plan $plan, Endpoint $source, Endpoint $target, Rsync $rsync): bool
    {
        $cleanup = new CleanupStack;

        if (defined('SIGINT')) {
            $this->trap([SIGINT, SIGTERM], function () use ($cleanup) {
                $this->newLine();
                $this->components->warn('Interrupted, cleaning up...');
                $this->runCleanup($cleanup);

                exit(1);
            });
        }

        try {
            $succeeded = $this->runSteps($this->steps($plan, $source, $target, $rsync, $cleanup));
        } finally {
            $this->runCleanup($cleanup);
        }

        if ($succeeded) {
            $this->components->success($this->direction === Direction::Pull
                ? "Pulled the {$plan->scope()} from {$source->label()}."
                : "Pushed the {$plan->scope()} to {$target->label()}.");
        }

        return $succeeded;
    }

    /**
     * @return array<string, Closure(): ?string> step label => step, returning an error or null
     */
    protected function steps(Plan $plan, Endpoint $source, Endpoint $target, Rsync $rsync, CleanupStack $cleanup): array
    {
        $steps = [];

        if ($database = $plan->database) {
            $from = $source->dumpPath($database->transfer);
            $to = $target->dumpPath($database->transfer);
            $targetDump = "the dump on {$target->label()}";

            if ($database->backup !== null) {
                $steps["Backing up the {$target->label()} database as {$database->backup}"] = fn () => $this->artisan($target, ['remote-sync:dump', $database->backup]);
            }

            $steps["Dumping the {$source->label()} database"] = function () use ($source, $database, $from, $cleanup) {
                $cleanup->push("the dump on {$source->label()}", fn () => $source->remove($from) ?: throw new RuntimeException($from));

                return $this->artisan($source, ['remote-sync:dump', $database->transfer, ...$database->dumpOptions]);
            };

            $steps["Transferring the dump to {$target->label()}"] = function () use ($source, $target, $rsync, $from, $to, $targetDump, $cleanup) {
                $cleanup->push($targetDump, fn () => $target->remove($to) ?: throw new RuntimeException($to));
                $target->makeDirectory(dirname($to));

                return $this->failure($rsync->copy($source->rsyncPath($from), $target->rsyncPath($to)));
            };

            $steps["Loading the dump into the {$target->label()} database"] = function () use ($target, $database, $to, $targetDump, $cleanup) {
                $error = $this->artisan($target, ['remote-sync:load', $database->transfer, '--force', ...($database->fresh ? ['--fresh'] : [])]);

                if ($error !== null || $database->keepTransfer) {
                    $cleanup->forget($targetDump);
                }

                if ($error !== null) {
                    if ($database->backup !== null) {
                        $restore = "`php artisan remote-sync:load {$database->backup} --fresh`";
                        $this->failureNotes[] = "Restore the previous {$target->label()} database ".($target instanceof RemoteEndpoint ? "by running {$restore} on the server." : "with {$restore}.");
                    }

                    $this->failureNotes[] = "The dump was kept for inspection: {$to}";
                }

                return $error;
            };

            if ($database->filterUsers) {
                // Matching nobody only warns: the load is done, and migrate
                // and cache:clear must still run.
                $steps['Filtering users'] = function () {
                    if (app(UserFilter::class)->apply()['skipped'] ?? false) {
                        $this->components->warn('filter_users matches no user, so none were deleted. Check the patterns in config/remote-sync.php.');
                    }

                    return null;
                };
            }

            $steps["Running migrations on the {$target->label()} database"] = fn () => $this->artisan($target, ['migrate', '--force']);

            $steps["Clearing the {$target->label()} cache"] = fn () => $this->artisan($target, ['cache:clear']);
        }

        foreach ($plan->files?->paths() ?? [] as $path) {
            $steps["Syncing storage/{$path}"] = function () use ($source, $target, $rsync, $path, $plan) {
                $target->makeDirectory($target->storagePath($path));

                return $this->failure($rsync->sync(
                    $source->rsyncPath($source->storagePath($path).'/'),
                    $target->rsyncPath($target->storagePath($path).'/'),
                    delete: $plan->files->delete,
                ));
            };
        }

        return $steps;
    }

    /**
     * Run the steps in order and stop at the first error, printing it with
     * any failure notes the step left.
     *
     * @param  array<string, Closure(): ?string>  $steps
     */
    protected function runSteps(array $steps): bool
    {
        $number = 0;

        foreach ($steps as $label => $step) {
            $error = null;
            $number++;

            $this->components->task("{$number}/".count($steps)." {$label}", function () use ($step, &$error) {
                try {
                    $error = $step();
                } catch (Throwable $e) {
                    $error = $e->getMessage();
                }

                return $error === null;
            });

            if ($error !== null) {
                $this->components->error($error);

                foreach ($this->failureNotes as $note) {
                    $this->line("  {$note}");
                }

                return false;
            }
        }

        return true;
    }

    protected function runCleanup(CleanupStack $cleanup): void
    {
        foreach ($cleanup->run()['failed'] as $what => $path) {
            $this->components->warn("Could not remove {$what}; delete it manually: {$path}");
        }
    }

    /**
     * @param  list<string>  $arguments
     */
    protected function artisan(Endpoint $endpoint, array $arguments): ?string
    {
        return $this->failure($endpoint->artisan($arguments, Connection::transferTimeout()));
    }

    protected function failure(ProcessResult $result): ?string
    {
        if ($result->successful()) {
            return null;
        }

        // Drop the "ERROR" label an artisan child prints; ours adds its own.
        $output = preg_replace('/^ERROR {2,}/', '', trim($result->errorOutput()) ?: trim($result->output()));

        return $output !== '' ? $output : "Exited with code {$result->exitCode()}.";
    }

    protected function failWith(string $message): int
    {
        $this->components->error($message);

        return self::FAILURE;
    }
}
