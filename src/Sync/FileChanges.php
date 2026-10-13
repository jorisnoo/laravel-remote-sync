<?php

namespace Noo\LaravelRemoteSync\Sync;

readonly class FileChanges
{
    /**
     * @param  list<string>  $transfers
     * @param  list<string>  $deletions
     */
    public function __construct(
        public array $transfers = [],
        public array $deletions = [],
    ) {}

    /**
     * Parse `rsync --itemize-changes` output: "*deleting path" lines are
     * deletions; file and symlink lines are transfers; directories and
     * rsync's own summary lines are ignored.
     */
    public static function fromItemized(string $output): self
    {
        $transfers = [];
        $deletions = [];

        foreach (preg_split('/\r?\n/', $output) ?: [] as $line) {
            if (preg_match('/^\*deleting\s+(.+)$/', $line, $match)) {
                $deletions[] = $match[1];
            } elseif (preg_match('/^[<>ch.][fL]\S* (.+?)(?: -> .*)?$/', $line, $match)) {
                $transfers[] = $match[1];
            }
        }

        return new self($transfers, $deletions);
    }
}
