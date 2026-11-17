<?php

namespace Noo\LaravelRemoteSync\Commands\Concerns;

use InvalidArgumentException;
use Noo\LaravelRemoteSync\Remotes\Connection;
use Noo\LaravelRemoteSync\Remotes\HostKey;
use Noo\LaravelRemoteSync\Remotes\Probe;
use Noo\LaravelRemoteSync\Remotes\Remote;
use Noo\LaravelRemoteSync\Remotes\RemoteRegistry;
use Noo\LaravelRemoteSync\Sync\RemoteEndpoint;
use RuntimeException;

use function Laravel\Prompts\confirm;
use function Laravel\Prompts\select;
use function Laravel\Prompts\spin;
use function Laravel\Prompts\table;
use function Laravel\Prompts\text;

trait InteractsWithRemote
{
    protected function isInteractive(): bool
    {
        return $this->input->isInteractive();
    }

    /**
     * The remote argument, the only configured remote, an interactive choice,
     * or the configured default, in that order.
     */
    protected function resolveRemote(): ?Remote
    {
        $registry = app(RemoteRegistry::class);
        $names = $registry->names();
        $name = $this->argument('remote');

        if ($names === []) {
            $this->components->error('No remotes are configured. Add one to config/remote-sync.php.');

            return null;
        }

        if ($name === null && count($names) === 1) {
            $name = $names[0];
        }

        if ($name === null) {
            $default = $registry->defaultName();

            if ($this->isInteractive()) {
                $name = select('Which remote?', $names, default: in_array($default, $names, true) ? $default : $names[0]);
            } elseif ($default !== null && $registry->has($default)) {
                $name = $default;
            } else {
                $this->components->error('Several remotes are configured ('.implode(', ', $names).'). Pass one, or set REMOTE_SYNC_DEFAULT.');

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

    /**
     * Verify the host key, then learn everything about the remote in one
     * round trip.
     */
    protected function connect(Remote $remote): ?RemoteEndpoint
    {
        $connection = new Connection($remote);

        if (! $this->verifyHostKey($connection)) {
            return null;
        }

        try {
            $info = spin(fn () => app(Probe::class)->run($connection), "Connecting to [{$remote->name}]...");
        } catch (RuntimeException $e) {
            $this->components->error($e->getMessage());
            $this->components->info("Run `php artisan remote-sync:doctor {$remote->name}` for a full diagnosis.");

            return null;
        }

        return new RemoteEndpoint($connection, $info);
    }

    protected function verifyHostKey(Connection $connection): bool
    {
        $host = $connection->remote->hostname();
        $ssh = $connection->remote->host;

        return match (spin(fn () => $connection->checkHostKey(), 'Verifying the host key...')) {
            HostKey::Trusted => true,
            HostKey::Changed => $this->refuse("The host key of {$host} has CHANGED. This could be a man-in-the-middle attack. If the change is expected, update ~/.ssh/known_hosts yourself."),
            HostKey::AuthFailed => $this->refuse("SSH key authentication to {$ssh} failed. Make sure your key is loaded (ssh-add) and authorized on the server."),
            HostKey::Unknown => $this->isInteractive()
                ? $this->acceptHostKey($connection, $host)
                : $this->refuse("{$host} is not a known host. Run interactively to review its fingerprint, or run: ssh -o StrictHostKeyChecking=accept-new {$ssh} exit"),
        };
    }

    protected function acceptHostKey(Connection $connection, string $host): bool
    {
        $this->components->warn("{$host} is not in your known_hosts file.");

        $rows = collect(explode("\n", (string) $connection->hostFingerprints()))
            ->map(fn (string $line) => preg_match('/^\d+\s+(SHA256:\S+)\s+.*\((\w+)\)$/', trim($line), $m) ? [$m[2], $m[1]] : null)
            ->filter()
            ->values()
            ->all();

        if ($rows !== []) {
            table(['Type', 'Fingerprint'], $rows);
        }

        if (! confirm("Trust {$host} and continue?", default: false)) {
            return false;
        }

        return spin(fn () => $connection->acceptHostKey(), 'Saving the host key...')
            || $this->refuse('Could not save the host key.');
    }

    protected function refuse(string $message): bool
    {
        $this->components->error($message);

        return false;
    }

    /**
     * --force answers yes; without it, non-interactive runs stop here.
     * A typed confirmation treats anything but "yes" as no.
     */
    protected function confirmed(string $question, bool $typed = false): bool
    {
        if ($this->option('force')) {
            return true;
        }

        if (! $this->isInteractive()) {
            $this->components->error('Confirmation required: re-run with --force.');

            return false;
        }

        $confirmed = $typed
            ? text("{$question} Type \"yes\" to continue", placeholder: 'yes') === 'yes'
            : confirm($question, default: true);

        if (! $confirmed) {
            $this->components->info('Cancelled.');
        }

        return $confirmed;
    }
}
