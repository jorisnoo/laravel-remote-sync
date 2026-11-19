<?php

namespace Noo\LaravelRemoteSync\Commands\Concerns;

use Closure;
use InvalidArgumentException;
use Noo\LaravelRemoteSync\Remotes\Connection;
use Noo\LaravelRemoteSync\Remotes\Probe;
use Noo\LaravelRemoteSync\Remotes\Remote;
use Noo\LaravelRemoteSync\Remotes\RemoteInfo;
use Noo\LaravelRemoteSync\Remotes\RemoteRegistry;
use Noo\LaravelRemoteSync\Snapshots\Importer;
use Noo\LaravelRemoteSync\Snapshots\Snapshots;
use Noo\LaravelRemoteSync\Support\CleanupStack;
use Noo\LaravelRemoteSync\Support\StoragePath;
use Throwable;

use function Laravel\Prompts\confirm;
use function Laravel\Prompts\select;
use function Laravel\Prompts\spin;
use function Laravel\Prompts\text;

trait ResolvesRemote
{
    /** @var list<string> Guidance printed under a failed step (e.g. restore instructions). */
    protected array $failureNotes = [];

    protected function isInteractive(): bool
    {
        return $this->input->isInteractive();
    }

    /**
     * Pull and push refuse to run in production unless explicitly allowed.
     */
    protected function guardProduction(): bool
    {
        if (! app()->isProduction() || config('remote-sync.allow_production')) {
            return true;
        }

        $this->components->error(__('remote-sync::messages.errors.production_refused'));

        return false;
    }

    protected function runsInProduction(): bool
    {
        return app()->isProduction();
    }

    protected function resolveRemote(bool $warnOnFailure = false): ?Remote
    {
        $registry = app(RemoteRegistry::class);
        $names = $registry->names();
        $level = $warnOnFailure ? 'warn' : 'error';

        if ($names === []) {
            $this->components->{$level}(__('remote-sync::messages.errors.no_remotes_configured'));

            return null;
        }

        $name = $this->argument('remote');

        if ($name === null && count($names) === 1) {
            $name = $names[0];
        }

        if ($name === null) {
            $default = $registry->defaultName();

            if ($this->isInteractive()) {
                $name = select(
                    label: __('remote-sync::prompts.remote.label'),
                    options: $names,
                    default: in_array($default, $names, true) ? $default : $names[0],
                );
            } elseif ($default !== null && $registry->has($default)) {
                $name = $default;
            } else {
                $this->components->{$level}(__('remote-sync::messages.errors.ambiguous_remote', [
                    'names' => implode(', ', $names),
                ]));

                return null;
            }
        }

        try {
            return $registry->get($name);
        } catch (InvalidArgumentException $e) {
            $this->components->{$level}($e->getMessage());

            return null;
        }
    }

    protected function verifyHostKey(Connection $connection): bool
    {
        try {
            spin(
                callback: function () use ($connection): void {
                    $connection->checkHostKey();
                },
                message: __('remote-sync::messages.spinners.verifying_host'),
            );

            return true;
        } catch (Throwable $e) {
            $this->components->error($e->getMessage());

            return false;
        }
    }

    /**
     * Build the sync plan behind a spinner. When the console is verbose (-v),
     * the spinner is dropped so the file dry-run's live rsync output stays
     * legible instead of fighting the animation.
     *
     * @param  Closure(): mixed  $callback
     */
    protected function buildPlan(Closure $callback): mixed
    {
        if ($this->output->isVerbose()) {
            $this->components->info(__('remote-sync::messages.spinners.building_plan'));

            return $callback();
        }

        return spin(
            callback: $callback,
            message: __('remote-sync::messages.spinners.building_plan'),
        );
    }

    protected function probeRemote(Connection $connection): ?RemoteInfo
    {
        try {
            return spin(
                callback: fn () => app(Probe::class)->run($connection),
                message: __('remote-sync::messages.spinners.probing', ['name' => $connection->remote->name]),
            );
        } catch (Throwable $e) {
            $this->components->error($e->getMessage());
            $this->components->info(__('remote-sync::messages.info.doctor_hint', ['name' => $connection->remote->name]));

            return null;
        }
    }

    /**
     * The one confirmation before executing a plan. --force answers yes;
     * non-interactive runs without --force stop here; production runs
     * (with allow_production) escalate to a typed confirmation.
     */
    protected function confirmPlan(string $label): bool
    {
        if ($this->option('force')) {
            return true;
        }

        if (! $this->isInteractive()) {
            $this->components->error(__('remote-sync::messages.errors.confirmation_required'));

            return false;
        }

        if ($this->runsInProduction()) {
            return $this->confirmWithTypedYes($label.' '.__('remote-sync::prompts.confirm.typed_yes_suffix'));
        }

        return confirm(label: $label, default: true);
    }

    protected function confirmWithTypedYes(string $label): bool
    {
        $response = text(
            label: $label,
            placeholder: 'yes',
            required: true,
            validate: fn (string $value) => $value === 'yes' ? null : __('remote-sync::prompts.confirm.validation'),
        );

        return $response === 'yes';
    }

    protected function checkDatabasePreconditions(string $remoteName, RemoteInfo $info): bool
    {
        if (($error = $info->databaseProblem()) !== null) {
            $this->components->error($error);
            $this->components->info(__('remote-sync::messages.info.doctor_hint', ['name' => $remoteName]));

            return false;
        }

        if (Snapshots::configuredLocalDir() === null) {
            $this->components->error('Configure the local snapshots disk with a local driver and absolute directory root.');

            return false;
        }

        $localDriver = Importer::localDriver();

        if ($info->driver === null || Importer::normalizeDriver($info->driver) !== $localDriver) {
            $this->components->error(__('remote-sync::messages.errors.driver_mismatch', [
                'remote' => $info->driver,
                'local' => $localDriver,
            ]));

            return false;
        }

        return true;
    }

    /**
     * @param  list<string>  $requested
     * @return list<string>|null null when a path fails validation
     */
    protected function resolvePaths(array $requested): ?array
    {
        $paths = $requested ?: config('remote-sync.paths', []);
        $paths = array_values(array_unique(array_map(
            fn (string $path) => StoragePath::normalize($path),
            $paths
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
     * Run numbered execution steps. Each step returns an error message or
     * null; the first failure prints the error plus any queued failure
     * notes and stops.
     *
     * @param  list<array{label: string, run: Closure(): ?string}>  $steps
     */
    protected function runSteps(array $steps): bool
    {
        $total = count($steps);

        foreach ($steps as $index => $step) {
            $number = $index + 1;
            $error = null;

            $this->components->task("{$number}/{$total} {$step['label']}", function () use ($step, &$error) {
                try {
                    $error = ($step['run'])();
                } catch (Throwable $e) {
                    $error = $e->getMessage() ?: $e::class;
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
        $result = $cleanup->run();

        foreach ($result['ran'] as $label) {
            $this->components->info(__('remote-sync::messages.info.cleaned_up', ['what' => $label]));
        }

        foreach ($result['failed'] as $label => $error) {
            $this->components->warn(__('remote-sync::messages.warnings.cleanup_failed', ['what' => $label, 'error' => $error]));
        }
    }
}
