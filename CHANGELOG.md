# Changelog

All notable changes to this project will be documented in this file.

## [0.1.0](https://github.com/jorisnoo/laravel-remote-sync/releases/tag/v0.1.0) (2026-01-19)

Initial release.

### Features

- Sync database from remote Laravel environments using spatie/laravel-db-snapshots
- Sync storage files from remote using rsync
- Push local database and files to remote environments
- Interactive sync command (`sync:remote`) with prompts for remote and sync type
- Database-only sync command (`sync:database`)
- Files-only sync command (`sync:files`)
- Multiple remote environment support with configurable defaults
- Configurable table exclusions for database snapshots
- Safety features: production environment protection, confirmation prompts, automatic local backups, graceful cleanup on interrupt
- Option to keep empty tables in database snapshots
