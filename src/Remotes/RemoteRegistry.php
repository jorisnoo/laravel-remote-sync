<?php

namespace Noo\LaravelRemoteSync\Remotes;

use InvalidArgumentException;

class RemoteRegistry
{
    /**
     * @return list<string>
     */
    public function names(): array
    {
        return array_keys(config('remote-sync.remotes', []));
    }

    public function has(string $name): bool
    {
        return in_array($name, $this->names(), true);
    }

    public function defaultName(): ?string
    {
        $default = config('remote-sync.default');

        return is_string($default) && $default !== '' ? $default : null;
    }

    public function get(string $name): Remote
    {
        $remotes = config('remote-sync.remotes', []);

        if (! array_key_exists($name, $remotes)) {
            $configured = implode(', ', $this->names());

            throw new InvalidArgumentException($configured === ''
                ? "Remote '{$name}' is not configured, and no remotes are defined in config/remote-sync.php."
                : "Remote '{$name}' is not configured. Configured remotes: {$configured}.");
        }

        $config = $remotes[$name];

        $phpBinary = $config['php_binary'] ?? null;

        return new Remote(
            name: $name,
            host: $this->validated($name, 'host', $config['host'] ?? null),
            path: rtrim($this->validated($name, 'path', $config['path'] ?? null), '/'),
            push: (bool) ($config['push'] ?? false),
            phpBinary: is_string($phpBinary) && trim($phpBinary) !== '' ? trim($phpBinary) : null,
        );
    }

    protected function validated(string $remote, string $key, mixed $value): string
    {
        $envVar = 'REMOTE_SYNC_'.str_replace('-', '_', strtoupper($remote)).'_'.strtoupper($key);

        if (! is_string($value) || trim($value) === '') {
            throw new InvalidArgumentException(
                "Remote '{$remote}' has no {$key} configured. Set {$envVar} in your .env or edit config/remote-sync.php."
            );
        }

        $value = trim($value);

        if (str_contains($value, 'your-server') || str_contains($value, 'example.com')) {
            throw new InvalidArgumentException(
                "Remote '{$remote}' still uses the placeholder {$key} '{$value}'. Set {$envVar} in your .env or edit config/remote-sync.php."
            );
        }

        return $value;
    }
}
