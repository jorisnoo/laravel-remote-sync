<?php

namespace Noo\LaravelRemoteSync\Sync;

use Illuminate\Contracts\Process\ProcessResult;
use Illuminate\Support\Facades\Process;
use Noo\LaravelRemoteSync\Remotes\Connection;
use RuntimeException;
use Symfony\Component\Process\Process as SymfonyProcess;

class Rsync
{
    public function __construct(
        protected Connection $connection,
        protected bool $interactive = false,
        protected bool $verbose = false,
    ) {}

    /**
     * @param  list<string>  $excludes  Extra exclude patterns for this transfer (on top of dotfiles and config).
     */
    public function transfer(
        Direction $direction,
        string $remotePath,
        string $localPath,
        array $excludes = [],
        bool $delete = false
    ): ProcessResult {
        $options = ['-avz', '--partial'];

        if ($this->interactive) {
            $options[] = '--info=progress2';
        }

        $process = Process::timeout($this->connection->transferTimeout());

        if ($this->interactive && SymfonyProcess::isTtySupported()) {
            $process = $process->tty();
        }

        return $process->run($this->command($direction, $remotePath, $localPath, $options, $excludes, $delete));
    }

    /**
     * @param  list<string>  $excludes
     *
     * @throws RuntimeException when the dry-run itself fails
     */
    public function dryRun(
        Direction $direction,
        string $remotePath,
        string $localPath,
        array $excludes = [],
        bool $delete = false
    ): FileChanges {
        $options = ['-avz', '--dry-run', '--itemize-changes'];

        if ($this->verbose) {
            $options[] = '--stats';
        }

        $command = $this->command($direction, $remotePath, $localPath, $options, $excludes, $delete);

        if ($this->verbose) {
            fwrite(STDERR, '  $ '.implode(' ', $command).PHP_EOL);
        }

        $result = Process::timeout($this->connection->transferTimeout())->run(
            $command,
            $this->verbose ? fn (string $type, string $buffer) => fwrite($type === 'err' ? STDERR : STDOUT, $buffer) : null
        );

        if (! $result->successful()) {
            throw new RuntimeException(trim($result->errorOutput()) ?: 'rsync dry run failed');
        }

        return $this->parse($result->output());
    }

    /**
     * @param  list<string>  $options
     * @param  list<string>  $excludes
     * @return list<string>
     */
    protected function command(
        Direction $direction,
        string $remotePath,
        string $localPath,
        array $options,
        array $excludes,
        bool $delete
    ): array {
        $configuredExcludes = config('remote-sync.exclude_paths', []);

        $excludeOptions = collect(['.*'])
            ->merge($configuredExcludes)
            ->merge($excludes)
            ->unique()
            ->map(fn (string $pattern) => "--exclude={$pattern}")
            ->all();

        if ($delete) {
            $options[] = '--delete';
        }

        $remoteSpec = "{$this->connection->remote->host}:{$remotePath}";

        [$source, $destination] = $direction === Direction::Pull
            ? [$remoteSpec, $localPath]
            : [$localPath, $remoteSpec];

        return array_merge(['rsync'], $options, $excludeOptions, [$source, $destination]);
    }

    public function parse(string $output): FileChanges
    {
        $transfers = [];
        $deletions = [];

        foreach (explode("\n", $output) as $line) {
            $line = trim($line);

            if ($line === '' || str_starts_with($line, 'sending') || str_starts_with($line, 'receiving')) {
                continue;
            }

            if (str_starts_with($line, '*deleting')) {
                $deletions[] = trim(substr($line, strlen('*deleting')));

                continue;
            }

            if (preg_match('/^[<>ch.][fdLDS]/', $line) && ! str_ends_with($line, '/')) {
                $transfers[] = preg_replace('/^[<>ch.][fdLDS][^ ]* /', '', $line);
            }
        }

        return new FileChanges($transfers, $deletions);
    }
}
