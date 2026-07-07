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

    public function transferCount(): int
    {
        return count($this->transfers);
    }

    public function deletionCount(): int
    {
        return count($this->deletions);
    }

    public function merge(self $other): self
    {
        return new self(
            [...$this->transfers, ...$other->transfers],
            [...$this->deletions, ...$other->deletions],
        );
    }
}
