<?php

namespace Noo\LaravelRemoteSync\Support;

use Closure;
use Throwable;

/**
 * A LIFO stack of labeled cleanup actions, run from a finally block and
 * from signal traps. Running is idempotent: each action executes at most
 * once, even when a trap fires while the finally block is already running.
 */
class CleanupStack
{
    /** @var array<string, Closure> */
    protected array $cleanups = [];

    public function push(string $label, Closure $callback): void
    {
        $this->cleanups[$label] = $callback;
    }

    public function forget(string $label): void
    {
        unset($this->cleanups[$label]);
    }

    public function has(string $label): bool
    {
        return array_key_exists($label, $this->cleanups);
    }

    /**
     * Run all pending cleanups in reverse order of registration.
     *
     * @return array{ran: list<string>, failed: array<string, string>}
     */
    public function run(): array
    {
        $pending = array_reverse($this->cleanups, preserve_keys: true);
        $this->cleanups = [];

        $ran = [];
        $failed = [];

        foreach ($pending as $label => $callback) {
            try {
                $callback();
                $ran[] = $label;
            } catch (Throwable $e) {
                $failed[$label] = $e->getMessage();
            }
        }

        return ['ran' => $ran, 'failed' => $failed];
    }
}
