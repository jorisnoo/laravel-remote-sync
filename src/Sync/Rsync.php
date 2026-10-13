<?php

namespace Noo\LaravelRemoteSync\Sync;

use Illuminate\Contracts\Process\ProcessResult;
use Illuminate\Support\Facades\Process;
use Noo\LaravelRemoteSync\Remotes\Connection;
use RuntimeException;
use Symfony\Component\Process\Process as SymfonyProcess;

/**
 * Builds and runs rsync between two paths in rsync notation (local paths
 * or host:path). Directory syncs always skip dotfiles and exclude_paths;
 * rsync also protects excluded files from --delete.
 */
class Rsync
{
    public function __construct(
        protected bool $interactive = false,
        protected bool $verbose = false,
    ) {}

    public function copy(string $from, string $to): ProcessResult
    {
        return Process::timeout(Connection::transferTimeout())
            ->run($this->command(['-az', '--partial'], $from, $to));
    }

    public function sync(string $from, string $to, bool $delete): ProcessResult
    {
        $options = ['-az', '--partial', ...$this->excludes()];

        if ($delete) {
            $options[] = '--delete';
        }

        if ($this->interactive) {
            $options[] = '--info=progress2';
        }

        $process = Process::timeout(Connection::transferTimeout());

        if ($this->interactive && SymfonyProcess::isTtySupported()) {
            $process = $process->tty();
        }

        return $process->run($this->command($options, $from, $to));
    }

    /**
     * Dry-run a sync with --delete, so the plan can say what a deletion pass
     * would remove even when the user did not ask for one.
     *
     * @throws RuntimeException when rsync fails
     */
    public function preview(string $from, string $to): FileChanges
    {
        $command = $this->command(['-az', '--dry-run', '--itemize-changes', '--delete', ...$this->excludes()], $from, $to);

        if ($this->verbose) {
            fwrite(STDERR, '  $ '.implode(' ', $command).PHP_EOL);
        }

        $result = Process::timeout(Connection::transferTimeout())->run($command);

        if ($this->verbose) {
            fwrite(STDERR, $result->output().$result->errorOutput());
        }

        if (! $result->successful()) {
            throw new RuntimeException(trim($result->errorOutput()) ?: "rsync exited with code {$result->exitCode()}");
        }

        return FileChanges::fromItemized($result->output());
    }

    /**
     * @param  list<string>  $options
     * @return list<string>
     */
    protected function command(array $options, string $from, string $to): array
    {
        return ['rsync', '-e', Connection::shell(), ...$options, $from, $to];
    }

    /**
     * @return list<string>
     */
    protected function excludes(): array
    {
        return collect(['.*', ...config('remote-sync.exclude_paths', [])])
            ->unique()
            ->map(fn (string $pattern) => "--exclude={$pattern}")
            ->values()
            ->all();
    }
}
