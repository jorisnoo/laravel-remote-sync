<?php

namespace Noo\LaravelRemoteSync\Sync;

readonly class FilesPlan
{
    /**
     * @param  array<string, FileChanges>  $changes  Keyed by storage-relative path.
     */
    public function __construct(
        public array $changes,
        public bool $delete,
    ) {}

    /**
     * @return list<string>
     */
    public function paths(): array
    {
        return array_map('strval', array_keys($this->changes));
    }

    /**
     * @return list<string>
     */
    public function transfers(): array
    {
        return array_merge(...array_values(array_map(fn (FileChanges $changes) => $changes->transfers, $this->changes)));
    }

    /**
     * @return list<string>
     */
    public function deletions(): array
    {
        return array_merge(...array_values(array_map(fn (FileChanges $changes) => $changes->deletions, $this->changes)));
    }
}
