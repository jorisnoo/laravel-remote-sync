<?php

namespace Noo\LaravelRemoteSync\Sync;

/**
 * Everything a sync will do, computed before anything changes. A null
 * part is out of scope.
 */
readonly class Plan
{
    public function __construct(
        public Direction $direction,
        public ?DatabasePlan $database = null,
        public ?FilesPlan $files = null,
    ) {}

    public function scope(): string
    {
        return implode(' and ', array_filter([
            $this->database ? 'database' : null,
            $this->files ? 'files' : null,
        ]));
    }

    /**
     * @return list<string>
     */
    public function warnings(): array
    {
        return $this->database->warnings ?? [];
    }
}
