# Laravel Remote Sync

[![Latest Version on Packagist](https://img.shields.io/packagist/v/jorisnoo/laravel-remote-sync.svg?style=flat-square)](https://packagist.org/packages/jorisnoo/laravel-remote-sync)
[![GitHub Tests Action Status](https://img.shields.io/github/actions/workflow/status/jorisnoo/laravel-remote-sync/run-tests.yml?branch=main&label=tests&style=flat-square)](https://github.com/jorisnoo/laravel-remote-sync/actions?query=workflow%3Arun-tests+branch%3Amain)
[![Total Downloads](https://img.shields.io/packagist/dt/jorisnoo/laravel-remote-sync.svg?style=flat-square)](https://packagist.org/packages/jorisnoo/laravel-remote-sync)

Pull a remote Laravel app's database and storage files to your machine, or push yours to a remote, over SSH. Every run shows a plan of exactly what will change, backs up the side being overwritten, and asks once.

Pull and push are the same operation in opposite directions: back up the target, dump the source, transfer the dump, load it, migrate, clear the cache, then sync files with rsync. Dumps are piped straight into the `mysql`/`psql` client on both sides, so memory use does not grow with the database.

## Requirements

On your machine and on every remote:

- PHP 8.3+, Laravel 11, 12 or 13
- A MySQL, MariaDB or PostgreSQL database, with its CLI tools (`mysqldump` and `mysql`, or `pg_dump` and `psql`)
- `rsync` and `gzip`
- **This package, installed as a regular dependency** (not `require-dev`), because the remote runs its commands too

Locally you also need `ssh`, with key access to the remotes. Keys must work without a prompt (loaded in ssh-agent, or without a passphrase).

## Installation

```bash
composer require jorisnoo/laravel-remote-sync
php artisan vendor:publish --tag="remote-sync-config"
```

Deploy, so the remote has the package too, then point your `.env` at it:

```env
REMOTE_SYNC_PRODUCTION_HOST=forge@your-server.com
REMOTE_SYNC_PRODUCTION_PATH=/home/forge/your-app.com
```

Check everything before the first sync:

```bash
php artisan remote-sync:doctor
```

Doctor checks your local tools and database and, for each remote, SSH access, the app path, PHP, the package version, the database driver and its tools.

## Configuration

The published `config/remote-sync.php`:

```php
return [
    'remotes' => [
        'production' => [
            'host' => env('REMOTE_SYNC_PRODUCTION_HOST'),
            'path' => env('REMOTE_SYNC_PRODUCTION_PATH'), // a /current atomic-deploy layout is detected
            'push' => (bool) env('REMOTE_SYNC_PRODUCTION_PUSH', false),
            // 'php_binary' => '/usr/bin/php8.4',
        ],
    ],

    // Used when several remotes are configured and none is passed.
    'default' => env('REMOTE_SYNC_DEFAULT'),

    // Storage-relative paths synced by the files scope.
    'paths' => ['app'],

    // Extra rsync excludes. Dotfiles are always excluded.
    'exclude_paths' => [],

    // Tables whose rows never travel (see below).
    'exclude_tables' => ['cache', 'sessions', 'jobs', /* ... */],

    // false, or emails / *-wildcards of users to KEEP locally after a pull.
    'filter_users' => false,

    // Pull and push refuse to run when app.env is production unless this is true.
    'allow_production' => env('REMOTE_SYNC_ALLOW_PRODUCTION', false),

    'timeouts' => [
        'remote' => 300,      // short ssh commands
        'transfer' => 1800,   // dumping, loading, migrating, rsync
    ],
];
```

Add remotes by repeating the pattern; the env var names follow the remote's key (`staging` reads `REMOTE_SYNC_STAGING_HOST`, and so on). Remotes with missing or placeholder values are rejected with the exact env var to set.

## Pulling

```bash
php artisan remote-sync:pull
```

Interactively this asks at most three things: which remote (when several are configured), what to pull, and one confirmation after showing the plan.

A database pull makes your local database **equal to the remote's**, then runs `migrate` to bring it up to your code:

| Table | Result |
| --- | --- |
| On the remote | Replaced, structure and rows |
| In `exclude_tables` | Recreated **empty**, with the remote's structure |
| `migrations` | Always copied, never excluded |
| Only in your local database | Dropped (`migrate` recreates it if one of your migrations defines it) |

A backup of your local database (`pre-pull-*`) is made first. If loading fails, the dump is kept and the exact command to restore the backup is printed.

| Flag | Effect |
| --- | --- |
| `--database` / `--files` | Select the scope (neither means both) |
| `--full` | Also copy the rows of excluded tables |
| `--no-backup` | Skip the local backup |
| `--keep-dump` | Keep the transferred dump in `storage/remote-sync` |
| `--delete` | Delete local files that no longer exist on the remote |
| `--path=app/public` | Sync only these storage-relative paths (repeatable) |
| `--dry-run` | Show the plan and exit |
| `--force` / `-f` | Answer the confirmation with yes |

`--force` never implies `--delete`; the plan always tells you how many files a `--delete` pass would remove.

For a cron or CI refresh:

```bash
php artisan remote-sync:pull production --force
```

Without `--force`, non-interactive runs print the plan and exit with an error.

## Pushing

Pushing overwrites data on the remote, so it is stricter:

```bash
php artisan remote-sync:push staging --database
```

- The remote needs `'push' => true` in its config.
- Non-interactive runs must state the scope (`--database` and/or `--files`).
- The interactive confirmation is a typed `yes`.

A database push replaces the remote's copy of every table except the excluded ones, which stay as they are: sessions, tokens and queued jobs survive. The remote is backed up first (`pre-push-*`), then migrated and its cache cleared.

Push assumes you are sending back a changed copy of the remote's own data:

- **Same records.** Your local data came from the remote (or the same source), so the ids in the preserved sessions, tokens and jobs still point at the right records.
- **Same migrations.** Push refuses when the remote has run migrations your code does not have, and warns when yours will be recorded as run on the remote.

Flags mirror pull, without `--full`.

## Restoring a backup

```bash
php artisan remote-sync:load pre-pull-2026-10-13-120000 --fresh
```

`--fresh` drops every table first, so the database ends up exactly as it was. For a `pre-push-*` backup, run the same command on the server.

## Pruning dumps

Transfer dumps are removed after each run; backups accumulate in `storage/remote-sync`:

```bash
php artisan remote-sync:prune                      # local and remote, keep 5 of each kind
php artisan remote-sync:prune --local --keep=3
php artisan remote-sync:prune production --remote --dry-run
```

## Safety

- One plan and one confirmation before anything changes; `--dry-run` everywhere.
- The side being overwritten is backed up by default, and every failure says how to restore.
- Unknown SSH hosts show their fingerprints before you trust them; changed host keys abort; non-interactive runs never accept a new key.
- Ctrl+C removes temporary dumps on both sides.
- Both sides must run the same version of the package's sync protocol; a mismatch is reported before anything runs.
- `filter_users` refuses to act when its patterns would delete every user.
- `storage/remote-sync` has its own `.gitignore`, so dumps are never committed.

## Security notes

- Database passwords reach `mysqldump`/`pg_dump` through temporary credential files and `mysql`/`psql` through environment variables, never as command-line arguments.
- Sync paths, dump names and table names are validated against strict allowlists before they reach a shell.
- The package never handles SSH passwords.

## Testing

```bash
composer test
```

## License

The MIT License (MIT). Please see [License File](LICENSE.md) for more information.

---

Built with [Claude Code](https://claude.ai/code).

## Maintainer releases

See [RELEASING.md](RELEASING.md) for versioning, changelog entries and the release command.
