# Upgrading

## 0.x to 1.0

Version 1.0 is a full redesign. Commands prompt for nothing except the remote, the scope, and one final confirmation; every other decision is a flag with a safe default.

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
