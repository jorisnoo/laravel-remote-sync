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
use Noo\LaravelRemoteSync\Support\CleanupStack;
use Noo\LaravelRemoteSync\Support\StoragePath;
use RuntimeException;

use function Laravel\Prompts\confirm;
use function Laravel\Prompts\select;
use function Laravel\Prompts\spin;
use function Laravel\Prompts\table;
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

    protected function resolveRemote(): ?Remote
    {
        $registry = app(RemoteRegistry::class);
        $names = $registry->names();

        if ($names === []) {
            $this->components->error(__('remote-sync::messages.errors.no_remotes_configured'));

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
                $this->components->error(__('remote-sync::messages.errors.ambiguous_remote', [
                    'names' => implode(', ', $names),
                ]));

                return null;
            }
        }

        try {
            return $registry->get($name);
        } catch (InvalidArgumentException $e) {
            $this->components->error($e->getMessage());

            return null;
        }
    }

    protected function verifyHostKey(Connection $connection): bool
    {
        $status = spin(
            callback: fn () => $connection->checkHostKey(),
            message: __('remote-sync::messages.spinners.verifying_host'),
        );

        if ($status === 'ok') {
            return true;
        }

        $hostname = $connection->remote->hostname();

        if ($status === 'changed') {
            $this->components->error(__('remote-sync::messages.errors.host_key_changed', ['host' => $hostname]));

            return false;
        }

        if (! $this->isInteractive()) {
            $this->components->error(__('remote-sync::messages.errors.host_key_unknown_noninteractive', [
                'host' => $hostname,
                'ssh_host' => $connection->remote->host,
            ]));

            return false;
        }

        $this->components->warn(__('remote-sync::messages.warnings.unknown_host', ['host' => $hostname]));

        $fingerprints = $connection->hostFingerprints();

        if ($fingerprints !== null) {
            $rows = collect(explode("\n", $fingerprints))
                ->filter()
                ->map(function (string $line) {
                    if (preg_match('/^\d+\s+(SHA256:\S+)\s+.*\((\w+)\)$/', trim($line), $matches)) {
                        return [$matches[2], $matches[1]];
                    }

                    return null;
                })
                ->filter()
                ->values()
                ->all();

            if ($rows !== []) {
                table(['Type', 'Fingerprint'], $rows);
            }
        }

        if (! confirm(label: __('remote-sync::prompts.confirm.accept_host_key', ['host' => $hostname]), default: false)) {
            return false;
        }

        $accepted = spin(
            callback: fn () => $connection->acceptHostKey(),
            message: __('remote-sync::messages.spinners.accepting_host_key'),
        );

        if (! $accepted) {
            $this->components->error(__('remote-sync::messages.errors.host_key_failed'));

            return false;
        }

        $this->components->info(__('remote-sync::messages.info.host_key_accepted', ['host' => $hostname]));

        return true;
    }

    protected function probeRemote(Connection $connection): ?RemoteInfo
    {
        try {
            return spin(
                callback: fn () => app(Probe::class)->run($connection),
                message: __('remote-sync::messages.spinners.probing', ['name' => $connection->remote->name]),
            );
        } catch (RuntimeException $e) {
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
        if (! $info->hasDbSnapshots) {
            $this->components->error(__('remote-sync::messages.errors.snapshots_missing_on_remote', ['name' => $remoteName]));
            $this->components->info(__('remote-sync::messages.info.doctor_hint', ['name' => $remoteName]));

            return false;
        }

        if ($info->driver === null) {
            $this->components->warn(__('remote-sync::messages.warnings.driver_detection_failed'));

            if ($this->isInteractive() && ! $this->option('force')) {
                return confirm(label: __('remote-sync::prompts.confirm.continue_without_driver'), default: false);
            }

            return true;
        }

        $localDriver = Importer::localDriver();

        if (Importer::normalizeDriver($info->driver) !== $localDriver) {
            $this->components->error(__('remote-sync::messages.errors.driver_mismatch', [
                'remote' => $info->driver,
                'local' => $localDriver,
            ]));

            return false;
        }

        return true;
    }

    /**
     * @return list<string>|null null when a path fails validation
     */
    protected function resolvePaths(): ?array
    {
        $paths = $this->option('path') ?: config('remote-sync.paths', []);
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
                $error = ($step['run'])();

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
