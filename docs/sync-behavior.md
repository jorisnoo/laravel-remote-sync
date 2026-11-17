# Pull and push: how they behave

This is the reference for what `remote-sync:pull` and `remote-sync:push` do in every case. Pull and push are one operation, implemented once in `SyncCommand`, with source and target swapped:

> back up the target, dump the source, transfer the dump, load it into the target, migrate, clear the cache, then sync storage files.

The README covers everyday use; this document covers the edge cases. Keep it in step with the code: when behaviour changes, change the matching row here.

## 1. Building blocks

### 1.1 Requirements

| Side | Needs |
| --- | --- |
| Local | PHP, `ssh`, `rsync`, `gzip`; `mysqldump`+`mysql` or `pg_dump`+`psql`; a MySQL, MariaDB or PostgreSQL database. |
| Remote | This package installed as a regular dependency (not `require-dev`), `rsync`, `gzip`, and the same database tools. |
| Dependency | `spatie/db-dumper` builds the `mysqldump`/`pg_dump` commands and passes credentials through temporary files. |

### 1.2 Commands that run on both sides

| Command | Does |
| --- | --- |
| `remote-sync:info` | Prints one `REMOTE_SYNC_INFO=<json>` line: protocol version, `app.env`, database driver, tables, rows of the migrations table, storage path, dumps, and missing database tools. Hidden. |
| `remote-sync:dump {name} {--exclude=*} {--exclude-data=*}` | Writes `storage/remote-sync/{name}.sql.gz`. `--exclude` leaves a table out completely; `--exclude-data` keeps its structure but not its rows. A failed dump removes its partial file. Hidden. |
| `remote-sync:load {name} {--fresh} {--force}` | Checks the file with `gzip -t`, then pipes it into `mysql`/`psql`, which stops at the first error. `--fresh` drops every table first. Asks for confirmation unless `--force`; refuses without `--force` when not interactive. Also the restore command. |

Pull and push call these through `php artisan` in a child process locally, and over ssh on the remote, always with `--force`.

### 1.3 Terms

| Term | Meaning |
| --- | --- |
| Dump directory | `storage/remote-sync/` on each side. Created with a `.gitignore`. Holds only this package's dumps. |
| Transfer dump | `remote-sync-<timestamp>-<random>`. Removed after the run, except where stated. |
| Backup | `pre-pull-*` (local) or `pre-push-*` (remote). Always a full dump, so `remote-sync:load <backup> --fresh` restores the exact previous state. |
| Excluded tables | `exclude_tables`, minus the migrations table. Their rows never travel. |
| Protocol version | `Inspector::PROTOCOL`. Raised whenever info, dump or load change incompatibly. Both sides must match. |

### 1.4 Core rules

- **Pull** makes the local database equal to the remote's, then runs `migrate` to bring it up to the local code. Excluded tables arrive empty, with the remote's structure.
- **Push** replaces the remote's copy of every non-excluded table. The remote's excluded tables (sessions, tokens, jobs) stay as they are.

## 2. Before anything changes

Each failure here exits 1, and nothing has changed on either side.

### 2.1 Production guard

| Case | Behaviour |
| --- | --- |
| Local `app.env` is not production | Continue. |
| Local is production, `allow_production` false | Refuse. |
| Local is production, `allow_production` true | Continue; the confirmation becomes a typed `yes`. |
| Push to a remote that reports production | The plan warns. Push's usual typed `yes` applies; `--force` is allowed, because `push: true` is the opt-in. |

### 2.2 Choosing the remote

| Case | Behaviour |
| --- | --- |
| No remotes configured | Error. |
| Remote argument given | Use it, or list the configured remotes. |
| No argument, one remote | Use it. |
| No argument, several, interactive | Ask, preselecting a valid `default`. |
| No argument, several, non-interactive | Use a valid `default`, or error. |
| `host` or `path` missing, empty or a placeholder | Error naming the env var to set. |
| Push to a remote without `push: true` | Error, before any network call. |

### 2.3 Scope

| Case | Pull | Push |
| --- | --- | --- |
| `--database` and/or `--files` | Exactly those. | Exactly those. |
| Neither, interactive | Ask; both preselected. | Ask; database preselected. |
| Neither, interactive with `--force` | Both, without asking. | Ask. |
| Neither, non-interactive | Both. | Error: the scope is required. |
| Files selected, no paths configured | Warn and drop files. | Same. |
| Nothing left | "Nothing to sync.", exit 0. | Same. |

### 2.4 Paths

| Case | Behaviour |
| --- | --- |
| `--path` given (repeatable) | Use only those; otherwise `paths` from the config. |
| Normalisation | Trim `/`, remove duplicates. |
| `..`, a leading `.`, `//`, characters outside `[A-Za-z0-9._-/]`, or `remote-sync` | Error. |

### 2.5 SSH

| Case | Behaviour |
| --- | --- |
| Every ssh and rsync call | `BatchMode=yes`, `ConnectTimeout=10`: never waits at a hidden prompt. |
| Key authentication refused | Error: load the key with `ssh-add` and authorize it on the server. |
| Host key known | Continue. |
| Host key changed | Error with a man-in-the-middle warning; never offers to accept. |
| Host key unknown, non-interactive | Error with the `ssh -o StrictHostKeyChecking=accept-new` command to run. |
| Host key unknown, interactive | Show fingerprints from `ssh-keyscan`, ask (default no), accept with `accept-new`. Known limit: the scan ignores `~/.ssh/config` aliases, ports and jump hosts. |

### 2.6 Probe (one ssh round trip)

A shell script finds the app root, a working PHP binary and rsync, then runs `remote-sync:info`.

| Case | Behaviour |
| --- | --- |
| `path/current` exists | Work in `path/current` (atomic deployments). |
| `path` missing | Error "directory not found". |
| ssh fails | Error "Cannot reach [name]" with ssh's message. |
| No PHP binary passes the app's composer platform check | Error: set `php_binary`. |
| rsync missing | Error. |
| `remote-sync:info` is not a command there | Error: install the package as a regular dependency and deploy. |
| `remote-sync:info` fails otherwise (e.g. database down) | Error with the first lines of its output. |
| Protocol versions differ | Error naming the side to update. |

Every probe error is followed by a pointer to `remote-sync:doctor <remote>`.

### 2.7 Database checks (database scope only)

| Case | Behaviour |
| --- | --- |
| Either side's driver isn't mysql/mariadb or pgsql | Error. |
| Drivers differ (mariadb counts as mysql) | Error. |
| Database tools missing on either side | Error naming the tools and the side. |
| Push: the remote has migrations the local code lacks | Error: the pushed migrations table would forget them. |
| Push: the local side has migrations the remote has not run | The plan warns that they will be recorded as run. |

### 2.8 Plan, dry-run, confirmation

| Case | Behaviour |
| --- | --- |
| Plan | Tables to replace, recreate empty, keep or drop; the backup; files to transfer; files a `--delete` pass would remove; warnings. |
| File preview fails (e.g. source path missing) | Error "Could not compare storage/<path>". |
| `--dry-run` | Print the plan; run nothing, create nothing; exit 0. |
| `--force` | Answers the confirmation. Never implies `--delete`. |
| Non-interactive without `--force` | Print the plan, error, exit 1. |
| Pull, interactive | Yes/no (default yes); a typed `yes` when local is production. |
| Push, interactive | Typed `yes`; anything else cancels. |
| Declined | "Cancelled.", exit 0. |

The plan is a preview: execution compares again, so changes made in between are included.

## 3. Pull

### 3.1 Database steps

The first failing step stops the run. Cleanup runs either way (§7).

| # | Step | On failure |
| --- | --- | --- |
| 1 | Local backup `pre-pull-*` (full). Skipped with `--no-backup`. | Stop; nothing has changed. |
| 2 | Remote `remote-sync:dump remote-sync-… --exclude-data=<excluded>` (no options with `--full`). | Stop; the remote's partial file is removed. |
| 3 | rsync the dump into local `storage/remote-sync/`. | Stop; the partial local file is removed. |
| 4 | Local `remote-sync:load remote-sync-… --fresh`. A dump failing `gzip -t` is refused before any table is dropped. | Stop. Keep the dump and print its path, plus `php artisan remote-sync:load pre-pull-… --fresh` when there is a backup. |
| 5 | `filter_users`, when configured. Matching nobody only warns and deletes nothing. | Stop (only on an exception). |
| 6 | `migrate --force`. | Stop, exit 1; the data is already loaded. |
| 7 | `cache:clear`. | Stop, exit 1. |

### 3.2 Table outcomes

| Table | Standard pull | `--full` |
| --- | --- | --- |
| On the remote, not excluded | Replaced: structure and rows. | Replaced. |
| On the remote, excluded | Recreated empty, with the remote's structure, whether or not it existed locally. | Replaced, rows included. |
| Migrations table | Replaced. | Replaced. |
| Only local | Dropped; `migrate` recreates it, empty, if a local migration defines it. Listed in the plan. | Same. |
| Users, with `filter_users` | Users not matching are deleted, unless none would remain. | Same. |

Because every pull loads fresh, the result does not depend on what the local database held before: a fresh database gets every table, and excluded tables always match the copied migrations table.

### 3.3 Files

After the database, per path, from the remote's `storage/<path>/` to the local one (§5).

## 4. Push

### 4.1 Assumptions

Push sends back a changed copy of the remote's own data.

- **Same records.** The local data came from the remote or the same source, so ids in the preserved sessions, tokens and jobs still mean the same records. Not detectable; the README states it.
- **Same migrations.** Checked before anything runs (§2.7).

### 4.2 Database steps

| # | Step | On failure |
| --- | --- | --- |
| 1 | Remote backup `pre-push-*` (full). Skipped with `--no-backup`. | Stop; nothing has changed. |
| 2 | Local `remote-sync:dump remote-sync-… --exclude=<excluded>`. On Postgres, sequences owned by excluded tables are left out too. | Stop. |
| 3 | `mkdir -p` the remote dump directory, then rsync the dump there. | Stop; the partial remote file is removed. |
| 4 | Remote `remote-sync:load remote-sync-…` without `--fresh`: the dump's own `DROP TABLE`s replace the tables it contains. | Stop. Keep the dump on the remote and print its path, plus `php artisan remote-sync:load pre-push-… --fresh` to run on the server when there is a backup. |
| 5 | Remote `migrate --force`. | Stop, exit 1. |
| 6 | Remote `cache:clear`. | Stop, exit 1. |

### 4.3 Table outcomes

| Table | Push |
| --- | --- |
| Local, not excluded | Replaces the remote's copy. |
| Excluded, on the remote | Untouched, rows and structure. |
| Excluded, missing on the remote | Stays missing. Rare, since deployed remotes have run their migrations. |
| Migrations table | Replaced by the local copy (guarded by §2.7). |
| Only on the remote | Untouched. |
| `filter_users` | Not applied. |

### 4.4 Files

After the database, per path, from the local `storage/<path>/` to the remote's (§5).

## 5. Files (both directions)

| Case | Behaviour |
| --- | --- |
| New or changed on the source | Copied with rsync `-a`: permissions, times, symlinks; owner and group only as root. |
| Only on the target | Kept; removed only with `--delete`. The plan always counts them. |
| Dotfiles | Never copied, never deleted. |
| `exclude_paths` | Not copied, and protected from `--delete`. |
| Source path missing | Planning fails (§2.8). |
| Target path missing | Created right before that path syncs, never while planning. |
| Interactive | rsync shows progress. |
| Overwritten or deleted files | Not backed up. |

## 6. Dumps and prune

| Case | Behaviour |
| --- | --- |
| Location | `storage/remote-sync/` on each side. On atomic deployments that is the shared storage when `storage` is linked there. |
| Leftover transfer dumps | Only from `--keep-dump`, a failed load, or a killed run. |
| `remote-sync:prune` | Keeps the newest N (`--keep`, default 5) of each kind (`pre-pull`, `pre-push`, `remote-sync`), locally and/or on the remote; touches nothing else. |

## 7. Cleanup, interruption, exit codes

| Case | Behaviour |
| --- | --- |
| End of a run, success or not | Remove the transfer dump on the target, then on the source, partial files included. |
| Failed load, or `--keep-dump` | The target's transfer dump is kept. |
| A removal fails | Warn with the path to delete by hand; the exit code is unchanged. |
| Ctrl+C or SIGTERM | Run the cleanup, exit 1. A half-applied step is recovered from the backup. |
| Exit 0 | Success, `--dry-run`, an interactive cancel, or nothing to sync. |
| Exit 1 | Everything else. |

## 8. Non-interactive use (cron, CI)

| Command | Needs |
| --- | --- |
| Pull | A resolvable remote, a known host key, and `--force`. The scope defaults to both. |
| Push | The same, plus `push: true` and an explicit `--database` and/or `--files`. |
| Either | Without `--force`, the plan is printed and the command exits 1. |

## 9. Design decisions

1. **Own dump commands instead of spatie/laravel-db-snapshots.** The same code dumps and loads on both sides, loads stream through the CLI clients, and restores are always `load --fresh`. The cost: the remote must run this package, at the same protocol version.
2. **`BatchMode=yes` everywhere.** No hidden prompts; keys must work without input.
3. **Pull loads fresh.** Reproducible results and a consistent migrations table, at the cost of dropping local-only tables. The backup and the plan listing are the safety net.
4. **Push preserves excluded tables.** Logging everyone out and dropping queued jobs on every push would be worse than the id assumption in §4.1.
5. **Full backups.** They include large monitoring tables, but make every restore exact.
6. **No file backups.** rsync `--backup-dir` would be the lean addition if needed.
