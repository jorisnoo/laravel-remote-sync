# Laravel Remote Sync

[![Latest Version on Packagist](https://img.shields.io/packagist/v/jorisnoo/laravel-remote-sync.svg?style=flat-square)](https://packagist.org/packages/jorisnoo/laravel-remote-sync)
[![GitHub Tests Action Status](https://img.shields.io/github/actions/workflow/status/jorisnoo/laravel-remote-sync/run-tests.yml?branch=main&label=tests&style=flat-square)](https://github.com/jorisnoo/laravel-remote-sync/actions?query=workflow%3Arun-tests+branch%3Amain)
[![Total Downloads](https://img.shields.io/packagist/dt/jorisnoo/laravel-remote-sync.svg?style=flat-square)](https://packagist.org/packages/jorisnoo/laravel-remote-sync)

Sync database and storage files from remote Laravel environments to your local machine. Uses [spatie/laravel-db-snapshots](https://github.com/spatie/laravel-db-snapshots) for database syncing and rsync for file transfers.

## Requirements

- PHP 8.3+
- Laravel 11 or 12
- SSH access to remote host (key-based auth recommended)
- `rsync` installed locally and on remote
- Remote server must have `spatie/laravel-db-snapshots` installed

## Installation

```bash
composer require jorisnoo/laravel-remote-sync
```

Publish the config file:

```bash
php artisan vendor:publish --tag="remote-sync-config"
```

## Configuration

Add your remote environments to `config/remote-sync.php` or use environment variables:

```env
REMOTE_SYNC_PRODUCTION_HOST=forge@your-server
REMOTE_SYNC_PRODUCTION_PATH=/home/forge/your-app.com

REMOTE_SYNC_STAGING_HOST=forge@staging-server
REMOTE_SYNC_STAGING_PATH=/home/forge/staging.your-app.com

REMOTE_SYNC_DEFAULT=production
```

### Config Options

```php
return [
    'remotes' => [
        'production' => [
            'host' => env('REMOTE_SYNC_PRODUCTION_HOST'),
            'path' => env('REMOTE_SYNC_PRODUCTION_PATH'),
        ],
    ],

    'default' => env('REMOTE_SYNC_DEFAULT', 'production'),

    // Storage paths to sync (relative to storage/)
    'paths' => [
        'app',
    ],

    // Tables to exclude from database snapshots
    'exclude_tables' => [
        'cache',
        'cache_locks',
        'sessions',
    ],

    // Timeouts in seconds
    'timeouts' => [
        'snapshot_create' => 300,
        'snapshot_download' => 600,
        'snapshot_cleanup' => 60,
        'file_sync' => 1800,
    ],
];
```

## Usage

### Interactive Pull

```bash
php artisan remote-sync:pull
```

Prompts you to select a remote and what to pull (database, files, or both).

Options:
- `--no-backup` - Skip creating a local backup before syncing
- `--keep-snapshot` - Keep the downloaded snapshot file after loading
- `--full` - Include all tables (ignores `exclude_tables` config) and drops all local tables before loading

### Pull Database Only

```bash
php artisan remote-sync:pull-database production
```

Options:
- `--no-backup` - Skip creating a local backup before syncing
- `--keep-snapshot` - Keep the downloaded snapshot file after loading
- `--full` - Include all tables (ignores `exclude_tables` config) and drops all local tables before loading

### Pull Files Only

```bash
php artisan remote-sync:pull-files production
```

Options:
- `--path=app/uploads` - Sync only a specific path
- `--delete` - Delete local files that don't exist on remote

### Push Commands

Push local data to a remote (requires `push_allowed: true` in config):

```bash
php artisan remote-sync:push              # Interactive
php artisan remote-sync:push-database     # Database only
php artisan remote-sync:push-files        # Files only
```

## Atomic Deployments

The package automatically detects if your remote server uses atomic deployments (Envoyer, Laravel Deployer, etc.) by checking for a `/current` symlink. When detected, it uses the correct working path.

- If `/current` exists → uses `{path}/current` as the working directory
- If `/current` doesn't exist → uses `{path}` directly

If your path already ends in `/current`, detection is skipped and atomic mode is assumed.

## Safety Features

- Commands refuse to run in production environment
- Confirmation prompt before syncing
- Local database backup created before sync (unless `--no-backup`)
- Graceful cleanup on interrupt (Ctrl+C)

## Testing

```bash
composer test
```

## License

The MIT License (MIT). Please see [License File](LICENSE.md) for more information.

---

Built with [Claude Code](https://claude.ai/code).
