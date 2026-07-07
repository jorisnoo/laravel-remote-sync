<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Remote Environments
    |--------------------------------------------------------------------------
    |
    | Each remote needs a host (SSH connection string) and a path (the app
    | root on the server). The path may point at an atomic-deploy root; a
    | /current layout is detected automatically. Set php_binary to a
    | specific binary (e.g. /usr/bin/php8.4) to skip auto-detection.
    |
    | Pushing is disabled per remote unless push is set to true.
    |
    */

    'remotes' => [
        'production' => [
            'host' => env('REMOTE_SYNC_PRODUCTION_HOST'),
            'path' => env('REMOTE_SYNC_PRODUCTION_PATH'),
            'push' => (bool) env('REMOTE_SYNC_PRODUCTION_PUSH', false),
            // 'php_binary' => '/usr/bin/php8.4',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Default Remote
    |--------------------------------------------------------------------------
    |
    | Used when no remote is given and more than one remote is configured.
    |
    */

    'default' => env('REMOTE_SYNC_DEFAULT'),

    /*
    |--------------------------------------------------------------------------
    | Storage Paths to Sync
    |--------------------------------------------------------------------------
    |
    | Paths relative to storage/ that are synced by the files scope.
    |
    */

    'paths' => [
        'app',
    ],

    /*
    |--------------------------------------------------------------------------
    | Excluded Paths
    |--------------------------------------------------------------------------
    |
    | Extra rsync exclude patterns (e.g. 'cache', '*.log', 'temp/**').
    | Dotfiles are always excluded, and snapshot directories are excluded
    | automatically wherever they actually live - no need to list them.
    |
    */

    'exclude_paths' => [],

    /*
    |--------------------------------------------------------------------------
    | Excluded Tables
    |--------------------------------------------------------------------------
    |
    | Tables synced as empty tables on pull and preserved on push. The
    | migrations table is always synced, and migrate --force always runs
    | after an import.
    |
    */

    'exclude_tables' => [
        // Cache
        'cache',
        'cache_locks',
        'health_cache',
        'health_cache_locks',

        // Monitoring
        'pulse_aggregates',
        'pulse_entries',
        'pulse_values',
        'telescope_entries',
        'telescope_entries_tags',
        'telescope_monitoring',

        // Sessions
        'sessions',

        // Queue/Jobs
        'jobs',
        'job_batches',
        'failed_jobs',

        // Auth tokens
        'password_reset_tokens',
        'personal_access_tokens',

        // Notifications
        'notifications',
    ],

    /*
    |--------------------------------------------------------------------------
    | Filter Users
    |--------------------------------------------------------------------------
    |
    | When set to an array of email addresses, only matching users are kept
    | in the local users table after a pull; all others are deleted.
    | Supports * wildcards (e.g. '*@example.com'). Set to false to disable.
    |
    */

    'filter_users' => false,

    /*
    |--------------------------------------------------------------------------
    | Production Guard
    |--------------------------------------------------------------------------
    |
    | Pull and push refuse to run when the local app environment is
    | production. Set this to true if this machine is intentionally a sync
    | source or target while running in production.
    |
    */

    'allow_production' => false,

    /*
    |--------------------------------------------------------------------------
    | Timeouts
    |--------------------------------------------------------------------------
    |
    | In seconds. 'remote' covers short remote commands (probing, listing
    | and deleting snapshots, migrations). 'transfer' covers data-sized
    | operations (snapshot create/load/import and rsync transfers).
    |
    */

    'timeouts' => [
        'remote' => 300,
        'transfer' => 1800,
    ],
];
