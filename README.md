# Laravel Remote Sync

[![Latest Version on Packagist](https://img.shields.io/packagist/v/jorisnoo/laravel-remote-sync.svg?style=flat-square)](https://packagist.org/packages/jorisnoo/laravel-remote-sync)
[![GitHub Tests Action Status](https://img.shields.io/github/actions/workflow/status/jorisnoo/laravel-remote-sync/run-tests.yml?branch=main&label=tests&style=flat-square)](https://github.com/jorisnoo/laravel-remote-sync/actions?query=workflow%3Arun-tests+branch%3Amain)
[![Total Downloads](https://img.shields.io/packagist/dt/jorisnoo/laravel-remote-sync.svg?style=flat-square)](https://packagist.org/packages/jorisnoo/laravel-remote-sync)

Pull a remote Laravel app's database and storage files to your local machine, or push them the other way. Both ends use native database clients, the configured snapshots disk and rsync over SSH.

## Requirements

Locally:

- PHP 8.3+ and Laravel 12 or 13
- `pg_dump` and `psql` for PostgreSQL, `mysqldump` and `mysql` for MySQL, or `mariadb-dump` and `mariadb` for MariaDB. Use clients compatible with the database server and dump format.
- Bash, `ssh`, `rsync`, and `gzip` on your PATH. Stock macOS openrsync is supported; Homebrew rsync is optional.
- SSH key access to the remote, with its host key already verified and recorded in known_hosts

On each remote:

- Install the matching `jorisnoo/laravel-remote-sync` 0.8 release as a normal dependency (not `require-dev`); it requires `spatie/laravel-db-snapshots` >= 2.7. Tinker is not required.
- Install Bash, `rsync`, `gzip` and the native dump and restore clients for the remote database.
- A PHP binary that satisfies the app. The default `php` is tried first, then versioned binaries (`php8.5`, `php8.4`, ...). You can also pin one with `php_binary` in the config.

The remote's snapshot location, database driver, and deployment layout are discovered on each run. Database sync refuses missing or incompatible protocol information and requires a local filesystem disk with an absolute root on each machine. Use a simple absolute application path (letters, digits, `_`, `-`, `.`, `/`) and an SSH config alias for custom ports, IPv6 or bastions.

## Installation

```bash
composer require jorisnoo/laravel-remote-sync
php artisan vendor:publish --tag="remote-sync-config"
```

Configure a snapshots disk for spatie/laravel-db-snapshots in `config/filesystems.php` (on both sides, but they may differ):

```php
'disks' => [
    'snapshots' => [
        'driver' => 'local',
        'root' => database_path('snapshots'),
    ],
],
```

Then point your `.env` at the remote:

```env
REMOTE_SYNC_PRODUCTION_HOST=forge@your-server.com
REMOTE_SYNC_PRODUCTION_PATH=/home/forge/your-app.com
```

Verify the whole setup before the first sync:

```bash
php artisan remote-sync:doctor
```

Doctor checks your local binaries and snapshots disk and, for each configured remote, the SSH host key, application path, PHP binary, package compatibility, rsync version and database driver. Older rsync and openrsync use no progress flag.

## Configuration

The published `config/remote-sync.php`:

```php
return [
    'remotes' => [
        'production' => [
            'host' => env('REMOTE_SYNC_PRODUCTION_HOST'),
            'path' => env('REMOTE_SYNC_PRODUCTION_PATH'),
            'push' => (bool) env('REMOTE_SYNC_PRODUCTION_PUSH', false),
            // 'php_binary' => '/usr/bin/php8.4',
        ],
    ],

    // Used when several remotes are configured and none is passed.
    'default' => env('REMOTE_SYNC_DEFAULT'),

    // Storage-relative paths synced by the files scope.
    'paths' => ['app'],

    // Extra rsync excludes. Dotfiles and snapshot directories are always excluded.
    'exclude_paths' => [],

    // Synced as empty tables on pull, preserved on push. The migrations
    // table is always synced and `migrate --force` runs after every import.
    'exclude_tables' => ['cache', 'sessions', 'jobs', /* ... */],

    // false, or emails / *-wildcards of users to KEEP locally after a pull.
    'filter_users' => false,

    // Pull and push refuse to run when app.env is production unless this is true.
    'allow_production' => env('REMOTE_SYNC_ALLOW_PRODUCTION', false),

    'timeouts' => [
        'remote' => 300,      // short remote commands
        'transfer' => 1800,   // dumps, imports, rsync transfers
    ],
];
```

Add more remotes by repeating the pattern - the env var names follow the remote's key (`staging` reads `REMOTE_SYNC_STAGING_HOST`, and so on). Remotes with missing or placeholder values are rejected with a message naming the exact env var to set.

## Pulling

```bash
php artisan remote-sync:pull
```

Interactively this asks at most three things: which remote (only when several are configured), what to pull (database, files, or both), and one final confirmation - after showing you a plan of exactly what will happen: which tables are imported and truncated, which files are transferred, what a `--delete` pass would remove, and which backup is created.

Everything else is a flag:

| Flag | Effect |
| --- | --- |
| `--database` / `--files` | Select the scope (skips the prompt; neither means both) |
| `--full` | Import all tables, dropping local tables first |
| `--no-backup` | Skip the local `pre-pull-*` backup snapshot |
| `--keep-snapshot` | Keep the downloaded snapshot file after import |
| `--delete` | Delete local files that no longer exist on the remote |
| `--path=app/public` | Sync only specific storage-relative paths (repeatable) |
| `--dry-run` | Print the plan and exit without changing anything |
| `--force` / `-f` | Answer the final confirmation with yes |

A standard pull creates a full local backup, dumps the remote database, and downloads and verifies the snapshot. Excluded tables arrive with their schema but no data, including on the first pull. Local-only tables remain unless `--full` is set. After import the command runs `migrate --force` and `optimize:clear`; failure stops the sync with a non-zero exit status.

PostgreSQL dumps omit ownership and grants and load in one transaction. If a local-only foreign key conflicts with the replacement, the import fails and the previous data and constraints remain intact. Resolve the dependency before retrying. MySQL DDL is not transactional: keep the default backup. Use `php artisan remote-sync:load <backup-name>` to restore; allow temporary space for the uncompressed SQL at the destination. MariaDB sandbox headers are never stripped; install a current `mariadb` client when loading those dumps.

Sync uses the app's default database connection and its own dump options, not `db-snapshots.extraOptions`, `tables`, `exclude` or custom `dump` options. MySQL uses a single transaction, quick reads and no table locks. Use transactional tables and avoid concurrent DDL during dumps.

Deletion is strictly opt-in: `--force` never implies `--delete`, and the plan preview tells you how many local-only files a `--delete` pass would remove before you commit to anything.

For a cron or CI refresh:

```bash
php artisan remote-sync:pull production --database --files --force
```

Without `--force`, non-interactive runs print the plan and exit with an error, so a misconfigured cron line cannot sync anything by accident.

## Pushing

Pushing overwrites data on the remote, so it is stricter:

```bash
php artisan remote-sync:push staging --database
```

- The remote must have `'push' => true` in its config.
- Non-interactive runs must state the scope explicitly (`--database` and/or `--files`).
- The interactive confirmation requires typing `yes`.

A database push backs up the remote first (`pre-push-*`), uploads a local snapshot, loads it on the remote without dropping tables (excluded tables are preserved), and runs remote migrations. If the load fails, the restore command for the remote backup is printed. Flags mirror pull: `--no-backup`, `--delete`, `--path=`, `--dry-run`, `--force`.

## Pruning snapshots

Transfer snapshots are removed automatically after each run, but backups (`pre-pull-*`, `pre-push-*`) accumulate:

```bash
php artisan remote-sync:prune                      # local + remote, keep the 5 newest
php artisan remote-sync:prune --local --keep=3
php artisan remote-sync:prune production --remote --dry-run
```

Prune only touches snapshots created by this package (`remote-sync-*`, `pre-pull-*`, `pre-push-*`). Pass `--all` to include your own spatie snapshots as well. `--keep` must be a non-negative integer; use `--keep=0` only to remove all eligible snapshots. If no remote can be selected, implicit local+remote mode warns and prunes locally.

## Safety

- Pull and push refuse to run when the local app environment is production; set `REMOTE_SYNC_ALLOW_PRODUCTION=true` in your `.env` only when that is intentional (the confirmation then requires a typed `yes`).
- One plan preview and one confirmation before anything changes; `--dry-run` everywhere.
- A full backup is created by default in either direction. After a backup succeeds its restore command is included with any subsequent step failure.
- SSH uses strict host-key checking on every connection, including rsync. For an unknown host, connect separately with SSH and compare the fingerprint against one obtained independently; the sync commands never enroll new keys.
- Interrupting a run (Ctrl+C) removes temporary snapshots on both sides.
- `filter_users` matches emails case-insensitively; only `*` is a wildcard. Filtering refuses to act when no users would remain.

## Security notes

- Database passwords are handed to `mysql`/`psql` via the `MYSQL_PWD`/`PGPASSWORD` environment variables, never as command-line arguments.
- Sync paths are validated against a strict character allowlist before being used in remote rsync specs.
- Use SSH keys; the package never handles SSH passwords.

## Testing

```bash
composer test
```

The release gate additionally enables the PostgreSQL and MySQL integration suites. See [RELEASING.md](RELEASING.md) for their local prerequisites; they never connect to an existing database server.

## License

The MIT License (MIT). Please see [License File](LICENSE.md) for more information.

---

Built with [Claude Code](https://claude.ai/code).

## Maintainer releases

See [RELEASING.md](RELEASING.md) for versioning, changelog entries and the release command.
