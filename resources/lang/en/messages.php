<?php

return [

    'errors' => [
        'production_refused' => 'Refusing to run in production. Set remote-sync.allow_production to true if this machine is intentionally a sync source or target.',
        'no_remotes_configured' => 'No remotes are configured. Add one to config/remote-sync.php.',
        'ambiguous_remote' => 'Multiple remotes are configured (:names). Pass one explicitly or set REMOTE_SYNC_DEFAULT.',
        'confirmation_required' => 'Confirmation required: re-run with --force.',
        'push_not_allowed' => "Push is not allowed for remote [:name]. Set 'push' => true for it in config/remote-sync.php to enable.",
        'push_scope_required' => 'Specify --database and/or --files when pushing non-interactively.',
        'snapshots_missing_on_remote' => 'spatie/laravel-db-snapshots is not installed on [:name]. Install it there to sync the database.',
        'driver_mismatch' => 'Database driver mismatch: the remote uses [:remote] but this app uses [:local]. Cross-database sync is not supported.',
        'corrupt_snapshot' => 'The downloaded snapshot failed its integrity check - nothing was imported.',
        'failed_import' => 'Import failed: :error',
        'failed_file_sync' => 'Failed to sync storage/:path: :error',
        'failed_local_backup' => 'Failed to create the local backup.',
        'failed_local_snapshot' => 'Failed to create the local snapshot.',
        'failed_remote_snapshot' => 'Failed to create the remote snapshot: :error',
        'failed_remote_backup' => 'Failed to create the remote backup: :error',
        'failed_download_snapshot' => 'Failed to download the snapshot: :error',
        'failed_upload_snapshot' => 'Failed to upload the snapshot: :error',
        'failed_remote_load' => 'Failed to load snapshot on remote: :error',
        'failed_delete_snapshot' => 'Failed to delete remote snapshot: :name',
        'remote_migrations_failed' => 'Migrations failed on the remote - run them there manually.',
        'host_key_changed' => 'WARNING: The host key for [:host] has changed. This could indicate a man-in-the-middle attack. Update your ~/.ssh/known_hosts file manually if this change is expected.',
        'host_key_unknown_noninteractive' => 'The authenticity of host [:host] cannot be verified. Run interactively to review the fingerprint, or run: ssh -o StrictHostKeyChecking=accept-new :ssh_host exit',
        'host_key_failed' => 'Failed to save the host key.',
    ],

    'warnings' => [
        'unknown_host' => 'The authenticity of host [:host] cannot be verified. It is not in your known_hosts file.',
        'driver_detection_failed' => 'Could not detect the remote database driver.',
        'no_paths_configured' => 'No storage paths are configured for file syncing.',
        'local_path_not_exists' => 'Local path does not exist: :path',
        'migrations_failed' => 'Migrations failed - run `php artisan migrate` manually.',
        'filter_users_skipped' => 'filter_users matched no users - skipped deleting anyone. Check your patterns in config/remote-sync.php.',
        'interrupt_cleanup' => 'Received interrupt signal, cleaning up...',
        'cleanup_failed' => 'Could not clean up :what (:error).',
    ],

    'info' => [
        'operation_cancelled' => 'Operation cancelled.',
        'no_operations_selected' => 'No operations selected.',
        'dry_run_done' => 'Dry run - nothing was changed.',
        'doctor_hint' => 'Run `php artisan remote-sync:doctor :name` for a full diagnosis.',
        'host_key_accepted' => 'Host key for [:host] has been saved.',
        'restore_hint' => 'Your previous database was backed up. Restore it with: php artisan snapshot:load :name',
        'remote_restore_hint' => 'The remote database was backed up first. Restore it on [:name] with: php artisan snapshot:load :backup --force',
        'snapshot_kept' => 'The downloaded snapshot was kept for inspection: :path',
        'filter_users_applied' => 'Users filtered: kept :kept, deleted :deleted.',
        'cleaned_up' => 'Removed :what.',
        'nothing_to_prune' => 'Nothing to prune (keeping the :keep most recent snapshots).',
        'local_prune_header' => 'Local snapshots to delete (keeping the :keep most recent):',
        'remote_prune_header' => 'Snapshots to delete on [:name] (keeping the :keep most recent):',
        'deleted_local_snapshots' => 'Deleted :count local snapshot.|Deleted :count local snapshots.',
        'deleted_remote_snapshots' => 'Deleted :count remote snapshot.|Deleted :count remote snapshots.',
    ],

    'success' => [
        'pulled' => 'Pulled :scope from [:name].',
        'pushed' => 'Pushed :scope to [:name].',
    ],

    'spinners' => [
        'verifying_host' => 'Verifying remote host...',
        'accepting_host_key' => 'Saving host key...',
        'probing' => 'Checking [:name]...',
        'building_plan' => 'Building sync plan...',
    ],

];
