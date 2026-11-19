# Upgrading

## 0.7 to 0.8

- Laravel 11 is no longer supported. Upgrade both applications to Laravel 12 or 13 on PHP 8.3+ before installing 0.8.
- Deploy this package as a regular dependency on each database remote. `remote-sync:info`, `remote-sync:dump` and `remote-sync:load` replace Tinker discovery and Spatie's SQL loader. Configure a local snapshots disk on both ends and install the native database tools and Bash.
- Verify the server's SSH host key independently and enroll it with SSH before running sync. Use SSH config aliases for nonstandard connections.
- Keep the configured database backup disk. New backups use the same `.sql.gz` format; restore new backups with `php artisan remote-sync:load <name>`. Do not assume older dumps omit ownership or contain replacement statements.
- Exclusions on pull now transfer structure without data; push still preserves excluded remote tables. Custom Spatie dump options do not apply to the sync-specific dumper. Failed migrations return a failure status.

## 0.6 to 0.7

Version 0.7 redesigned the commands. They prompt for nothing except the remote, the scope, and one final confirmation; every other decision is a flag with a safe default.

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
