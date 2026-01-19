<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Remote Environments
    |--------------------------------------------------------------------------
    |
    | Define your remote environments here. Each environment should have
    | a host (SSH connection string) and path (the application root path
    | on the remote server).
    |
    */

    'remotes' => [
        'production' => [
            'host' => env('REMOTE_SYNC_PRODUCTION_HOST'),
            'path' => env('REMOTE_SYNC_PRODUCTION_PATH'),
        ],
        'staging' => [
            'host' => env('REMOTE_SYNC_STAGING_HOST'),
            'path' => env('REMOTE_SYNC_STAGING_PATH'),
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Default Remote
    |--------------------------------------------------------------------------
    |
    | The default remote environment to use when none is specified.
    |
    */

    'default' => env('REMOTE_SYNC_DEFAULT', 'production'),

    /*
    |--------------------------------------------------------------------------
    | Storage Paths to Sync
    |--------------------------------------------------------------------------
    |
    | Paths relative to the remote storage/ directory that should be
    | synced when running the files sync command.
    |
    */

    'paths' => [
        'app',
    ],

    /*
    |--------------------------------------------------------------------------
    | Excluded Tables
    |--------------------------------------------------------------------------
    |
    | Tables to exclude from database snapshots. These are typically
    | cache tables, monitoring tables, or other tables that don't need
    | to be synced.
    |
    */

    'exclude_tables' => [
        'cache',
        'cache_locks',
        'health_cache',
        'health_cache_locks',
        'pulse_aggregates',
        'pulse_entries',
        'pulse_values',
        'telescope_entries',
        'telescope_entries_tags',
        'telescope_monitoring',
        'sessions',
    ],

    /*
    |--------------------------------------------------------------------------
    | Timeouts
    |--------------------------------------------------------------------------
    |
    | Timeout settings in seconds for various operations.
    |
    */

    'timeouts' => [
        'snapshot_create' => 300,
        'snapshot_download' => 600,
        'snapshot_cleanup' => 60,
        'file_sync' => 1800,
    ],
];
