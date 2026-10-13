<?php

namespace Noo\LaravelRemoteSync\Sync;

readonly class DatabasePlan
{
    /**
     * @param  string  $transfer  Name of the temporary dump.
     * @param  string|null  $backup  Name of the target's backup, null when skipped.
     * @param  bool  $fresh  Load with --fresh: the target ends up exactly as the dump.
     * @param  list<string>  $dumpOptions  Options for remote-sync:dump on the source.
     * @param  list<string>  $replace  Tables copied with their rows.
     * @param  list<string>  $empty  Tables copied as structure only.
     * @param  list<string>  $preserve  Target tables left untouched.
     * @param  list<string>  $drop  Target tables that will no longer exist.
     * @param  bool  $filterUsers  Apply filter_users after loading (pull only).
     * @param  list<string>  $warnings
     */
    public function __construct(
        public string $transfer,
        public ?string $backup,
        public bool $fresh,
        public bool $keepTransfer,
        public array $dumpOptions = [],
        public array $replace = [],
        public array $empty = [],
        public array $preserve = [],
        public array $drop = [],
        public bool $filterUsers = false,
        public array $warnings = [],
    ) {}
}
