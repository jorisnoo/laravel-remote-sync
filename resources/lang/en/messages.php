<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Error Messages
    |--------------------------------------------------------------------------
    */

    'errors' => [
        'production_not_allowed' => 'This command cannot be run in production.',
        'push_not_allowed' => "Push is not allowed for remote [:name]. Set 'push_allowed' to true in config to enable.",
        'no_remote_selected' => 'No remote environment selected.',
        'failed_remote_snapshot' => 'Failed to create remote snapshot: :error',
        'failed_download_snapshot' => 'Failed to download snapshot: :error',
        'failed_load_snapshot' => 'Failed to load snapshot.',
        'failed_remote_backup' => 'Failed to create remote backup: :error',
        'failed_local_snapshot' => 'Failed to create local snapshot.',
        'failed_upload_snapshot' => 'Failed to upload snapshot: :error',
        'failed_remote_load' => 'Failed to load snapshot on remote: :error',
        'driver_mismatch_pull' => 'Database driver mismatch: remote uses [:remote] but local uses [:local].',
        'driver_mismatch_push' => 'Database driver mismatch: local uses [:local] but remote uses [:remote].',
        'cross_database_not_supported' => 'Cross-database pull is not supported. Both environments must use the same database driver.',
        'failed_pull_path' => 'Failed to pull :path: :error',
        'failed_push_path' => 'Failed to push :path: :error',
        'failed_dry_run' => 'Dry run failed for :path: :error',
        'failed_list_snapshots' => 'Failed to list remote snapshots: :error',
        'failed_delete_snapshot' => 'Failed to delete remote snapshot: :name',
        'invalid_path' => 'Invalid path: :path',
        'storage_not_accessible' => 'Storage directory is not accessible.',
        'path_traversal' => 'Path traversal detected: :path must be within the storage directory.',
        'migrations_failed' => 'Migrations failed. Aborting pull.',
    ],

    /*
    |--------------------------------------------------------------------------
    | Info Messages
    |--------------------------------------------------------------------------
    */

    'info' => [
        'operation_cancelled' => 'Operation cancelled.',
        'creating_local_backup' => 'Creating local backup: :name',
        'remote_snapshot_created' => 'Remote snapshot created.',
        'downloading_snapshot' => 'Downloading snapshot from [:name]...',
        'snapshot_downloaded' => 'Snapshot downloaded.',
        'loading_snapshot' => 'Loading snapshot into database...',
        'snapshot_loaded' => 'Snapshot loaded.',
        'local_snapshot_removed' => 'Local snapshot file removed.',
        'remote_backup_created' => 'Remote backup created: :name',
        'creating_local_snapshot' => 'Creating local snapshot: :name',
        'local_snapshot_created' => 'Local snapshot created.',
        'uploading_snapshot' => 'Uploading snapshot to [:name]...',
        'snapshot_uploaded' => 'Snapshot uploaded.',
        'remote_snapshot_loaded' => 'Snapshot loaded on remote.',
        'pulling_path' => 'Pulling: :path',
        'pushing_path' => 'Pushing: :path',
        'no_operations_selected' => 'No operations selected.',
        'dry_run_mode' => 'Dry run mode - no changes will be made.',
        'would_sync_path' => 'Would sync: :path',
        'no_cleanup_targets' => 'No cleanup targets selected.',
        'no_snapshots_to_cleanup' => 'No snapshots to cleanup.',
        'running_migrations' => 'Running migrations...',
        'migrations_completed' => 'Migrations completed.',
        'local_snapshots_to_delete' => 'Local snapshots to delete (keeping :count most recent):',
        'remote_snapshots_to_delete' => 'Remote snapshots to delete from [:name] (keeping :count most recent):',
        'cleanup_cancelled' => 'Cleanup cancelled.',
        'deleted_local_snapshots' => 'Deleted :count local snapshot.|Deleted :count local snapshots.',
        'deleted_remote_snapshots' => 'Deleted :count remote snapshot.|Deleted :count remote snapshots.',
    ],

    /*
    |--------------------------------------------------------------------------
    | Warning Messages
    |--------------------------------------------------------------------------
    */

    'warnings' => [
        'interrupt_cleanup' => 'Received interrupt signal, cleaning up...',
        'manual_cleanup_needed' => 'Failed to delete remote snapshot. You may need to manually clean up: :name',
        'driver_detection_failed' => 'Could not detect remote database driver. Proceeding anyway...',
        'no_paths_pull' => 'No paths configured for pulling.',
        'no_paths_push' => 'No paths configured for pushing.',
        'delete_warning' => "WARNING: Files on [:name] that don't exist locally will be DELETED.",
        'dry_run_no_delete' => 'Dry run mode - no files were deleted.',
        'local_path_not_exists' => 'Local path does not exist: :path',
    ],

    /*
    |--------------------------------------------------------------------------
    | Success Messages
    |--------------------------------------------------------------------------
    */

    'success' => [
        'database_pulled' => 'Database pulled from [:name].',
        'database_pushed' => 'Database pushed to [:name].',
        'files_pulled' => 'Files pulled from [:name].',
        'files_pushed' => 'Files pushed to [:name].',
    ],

    /*
    |--------------------------------------------------------------------------
    | Spinner Messages
    |--------------------------------------------------------------------------
    */

    'spinners' => [
        'creating_remote_snapshot' => 'Creating snapshot on [:name]...',
        'cleaning_remote_snapshot' => 'Cleaning up remote snapshot...',
        'detecting_driver' => 'Detecting remote database driver...',
        'creating_remote_backup' => 'Creating backup on [:name]...',
        'loading_remote_snapshot' => 'Loading snapshot on [:name]...',
        'fetching_remote_table_info' => 'Fetching remote table info...',
        'analyzing_files_to_pull' => 'Analyzing files to pull...',
    ],

    /*
    |--------------------------------------------------------------------------
    | Preview Messages
    |--------------------------------------------------------------------------
    */

    'preview' => [
        'database_header' => 'Database pull preview:',
        'tables_to_pull' => 'Tables to pull',
        'source_rows' => 'Source rows (approx)',
        'target_rows' => 'Target rows to replace',
        'tables_to_truncate_header' => 'Tables that will be truncated (excluded from pull):',
        'files_header' => 'Files pull preview:',
        'files_to_transfer' => 'Files to transfer',
        'files_to_delete' => 'Files to delete',
    ],

    /*
    |--------------------------------------------------------------------------
    | Push Confirmation
    |--------------------------------------------------------------------------
    */

    'push' => [
        'overwrite_warning' => 'You are about to push local :operation to [:name]. This will OVERWRITE remote data.',
    ],

    /*
    |--------------------------------------------------------------------------
    | Cleanup Messages
    |--------------------------------------------------------------------------
    */

    'cleanup' => [
        'cleaning_local' => 'Cleaning up local snapshots',
    ],

];
