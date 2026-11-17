# Upgrading

## 0.7 to 0.8

Version 0.8 drops spatie/laravel-db-snapshots. The package now ships its own `remote-sync:info`, `remote-sync:dump` and `remote-sync:load` commands, which run on both sides, so pull and push are the same operation in opposite directions.

### Requirements

- **Install the package on every remote as a regular dependency** (move it out of `require-dev` if needed) and deploy before syncing. spatie/laravel-db-snapshots and laravel/tinker are no longer needed for remote-sync.
- The remote now needs the database CLI tools too (`mysqldump` and `mysql`, or `pg_dump` and `psql`). `remote-sync:doctor` checks them.
- SSH keys must work without a prompt (ssh-agent or no passphrase). Every ssh call uses `BatchMode=yes`.

### Behavior

| Area | Before | After |
| --- | --- | --- |
| Dump location | spatie's snapshot disk, discovered at runtime | `storage/remote-sync` on both sides, with its own `.gitignore` |
| Pull, excluded tables | Left out of the dump, then truncated | Dumped as structure only and recreated empty |
| Pull, tables that exist only locally | Kept | Dropped; `migrate` recreates the ones your migrations define |
| Pull `--full` | Dropped local tables and imported everything | Also copies the rows of excluded tables (every pull now starts fresh) |
| Push loading | spatie `snapshot:load`, in PHP memory | Piped into the remote's `mysql`/`psql` |
| Push, migrations | Not checked | Refused when the remote has migrations your code lacks |
| Backups | Push backup left out the excluded tables | Always full dumps |
| Restore | `php artisan snapshot:load …` | `php artisan remote-sync:load … --fresh` |
| After loading | `migrate` (failures only warned), `optimize:clear` locally | `migrate` (failures fail the command), then `cache:clear`, on both sides |
| Version mismatch | Not detected | The probe compares protocol versions and names the side to update |
| Prune | Kept N across all snapshot kinds; `--all` touched other snapshots | Keeps N of each kind, only in `storage/remote-sync`; `--all` is gone |

### Flags and files

- `--keep-snapshot` is now `--keep-dump`, and push accepts it too.
- The translation files are gone; messages are plain English.
- Existing `pre-pull-*` / `pre-push-*` snapshots stay in spatie's snapshot directory. Restore from them with spatie's tools, or delete them once you no longer need them.

## 0.6 to 0.7

Version 0.7 is a full redesign. Commands prompt for nothing except the remote, the scope, and one final confirmation; every other decision is a flag with a safe default.

### Commands

| Before | After |
| --- | --- |
| `remote-sync:pull` (7-8 prompts) | `remote-sync:pull` (plan preview + one confirmation) |
| `remote-sync:push` | `remote-sync:push` (scope required non-interactively) |
| `remote-sync:cleanup-snapshots` | `remote-sync:prune` |
| - | `remote-sync:doctor` (new preflight diagnosis) |

### Flags

| Before | After |
| --- | --- |
| `pull` had no `--database` / `--files` | Both commands accept `--database` / `--files` |
| `--path=` (single) | `--path=` repeatable |
| `--no-clear-cache` | Removed - caches are always cleared after a DB pull |
| `push --remote-host= --remote-path=` | Removed - push only targets configured remotes |
| `--force` skipped every prompt | `--force` only answers the final confirmation; it never implies `--delete` |
| Files delete prompt defaulted to yes | Deletion is opt-in via `--delete` only |
| `prune --all` | New - includes snapshots not created by this package |

### Config (`config/remote-sync.php`)

| Before | After |
| --- | --- |
| `remotes.*.push_allowed` | `remotes.*.push` (env: `REMOTE_SYNC_*_PUSH`) |
| - | `remotes.*.php_binary` (optional, skips auto-detection) |
| Placeholder defaults (`forge@your-server`) | No fallbacks; placeholders are rejected with the env var to set |
| `timeouts` with 6 keys | `timeouts.remote` and `timeouts.transfer` |
| `exclude_paths => ['app/snapshots']` | `[]` - snapshot directories are excluded automatically |
| - | `allow_production` (default false) |

### Behavior

- Commands now refuse to run when the local environment is production (previously a confirmation). Set `allow_production => true` to override.
- The remote snapshot location is discovered from the remote's own db-snapshots config. Local and remote snapshot disks no longer need to match.
- The migrations table is always included in dumps, and `migrate --force` always runs after an import (locally on pull, remotely on push). The migration-diff preview is gone.
- Downloaded snapshots are integrity-checked (`gzip -t`) before anything touches the database. On a failed import the downloaded snapshot is kept and the restore command for the automatic backup is printed.
- Backup snapshots are now named `pre-pull-*` / `pre-push-*` (previously `local-before-sync-*` / `pre-push-backup-*`), and `prune` only deletes package-prefixed snapshots unless `--all` is passed.
- Non-interactive runs require `--force`, and an unknown SSH host key is never accepted automatically.
