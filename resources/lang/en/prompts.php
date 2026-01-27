<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Confirmation Prompts
    |--------------------------------------------------------------------------
    */

    'confirm' => [
        'pull' => 'This will replace your local :operation with data from [:name]. Type "yes" to continue',
        'push' => 'Are you SURE you want to push to [:name]? Type "yes" to continue',
        'delete_remote' => 'Push local files to [:name] with deletion? Type "yes" to continue',
        'cleanup' => 'Delete :summary? Type "yes" to continue',
        'validation' => 'Type "yes" to confirm',
    ],

    /*
    |--------------------------------------------------------------------------
    | Backup Options
    |--------------------------------------------------------------------------
    */

    'backup' => [
        'label' => 'Database: Create a local backup before pulling?',
        'yes' => 'Yes (recommended)',
        'no' => 'No',
    ],

    /*
    |--------------------------------------------------------------------------
    | Import Mode Options
    |--------------------------------------------------------------------------
    */

    'import_mode' => [
        'label' => 'Database: Import mode',
        'standard' => 'Standard - without excluded tables (they will be truncated)',
        'full' => 'Full - all tables (including excluded), exactly mirrors the remote db',
    ],

    /*
    |--------------------------------------------------------------------------
    | Keep Snapshot Options
    |--------------------------------------------------------------------------
    */

    'keep_snapshot' => [
        'label' => 'Database: Keep the downloaded snapshot file after import?',
        'no' => 'No',
        'yes' => 'Yes',
    ],

    /*
    |--------------------------------------------------------------------------
    | Empty Database Options
    |--------------------------------------------------------------------------
    */

    'empty_database' => [
        'label' => 'Your local database is empty. Run migrations first?',
        'hint' => 'This will create the database schema before importing data',
    ],

    /*
    |--------------------------------------------------------------------------
    | Delete Options
    |--------------------------------------------------------------------------
    */

    'delete' => [
        'local_label' => 'Files: Delete all local files not present on remote?',
        'remote_label' => 'Files: Delete files or remote which are not present locally?',
        'no' => 'No - keep these files',
        'yes' => 'Yes - mirror exactly',
    ],

    /*
    |--------------------------------------------------------------------------
    | Dry Run Options
    |--------------------------------------------------------------------------
    */

    'dry_run' => [
        'label' => 'Files: Preview changes first?',
        'no' => 'No - proceed directly',
        'yes' => 'Yes - dry run first',
    ],

    /*
    |--------------------------------------------------------------------------
    | Path Selection
    |--------------------------------------------------------------------------
    */

    'paths' => [
        'label' => 'Files: Which paths to pull?',
        'all' => 'All configured paths (:paths)',
        'specific' => 'Specific path only',
        'enter_label' => 'Files: Enter path (relative to storage/)',
        'placeholder' => 'app/public',
    ],

    /*
    |--------------------------------------------------------------------------
    | Remote Selection
    |--------------------------------------------------------------------------
    */

    'remote' => [
        'label' => 'Select remote environment',
        'push_label' => 'Select remote environment to push to',
    ],

    /*
    |--------------------------------------------------------------------------
    | Operations Selection
    |--------------------------------------------------------------------------
    */

    'operations' => [
        'pull_label' => 'What would you like to pull?',
        'push_label' => 'What would you like to push?',
        'database' => 'Database',
        'files' => 'Files',
    ],

    /*
    |--------------------------------------------------------------------------
    | Cleanup Targets
    |--------------------------------------------------------------------------
    */

    'cleanup_targets' => [
        'label' => 'Which snapshots to cleanup?',
        'local' => 'Local snapshots',
        'remote' => 'Remote snapshots',
    ],

    /*
    |--------------------------------------------------------------------------
    | Keep Count
    |--------------------------------------------------------------------------
    */

    'keep_count' => [
        'label' => 'How many recent snapshots to keep?',
        'validation' => 'Please enter a valid non-negative number',
    ],

    /*
    |--------------------------------------------------------------------------
    | Preview Options
    |--------------------------------------------------------------------------
    */

    'preview' => [
        'label' => 'Preview before deleting?',
        'yes' => 'Yes - show what will be deleted (recommended)',
        'no' => 'No - delete immediately',
    ],

];
