# Changelog

All notable changes to this project will be documented in this file.

## [0.2.2](https://github.com/jorisnoo/laravel-remote-sync/releases/tag/v0.2.2) (2026-01-20)

### Features

- detect db driver mismatch ([b2c6a5a](https://github.com/jorisnoo/laravel-remote-sync/commit/b2c6a5a2c6004dba8907e748f27379368a0bd6db))

### Bug Fixes

- snapshot loading ([821b612](https://github.com/jorisnoo/laravel-remote-sync/commit/821b612b31f0a59592067e20c9c96694c03a903b))
- try to prevent memory exhaustion ([642c04a](https://github.com/jorisnoo/laravel-remote-sync/commit/642c04a600722d8326c871ba9fc6afec92dab5ca))
- try to prevent memory exhaustion ([0a8dade](https://github.com/jorisnoo/laravel-remote-sync/commit/0a8dade10c17d14e19d9e1b155cda93f4dd1b6bd))
- get db snapshot storage from config ([e09a037](https://github.com/jorisnoo/laravel-remote-sync/commit/e09a037437acaa2fa659ae6fb97fcf4528ec6b79))
- handle empty files array in config ([0c1a3ae](https://github.com/jorisnoo/laravel-remote-sync/commit/0c1a3aec43c94f4a1f13774fd231c86dc04e75da))
## [0.2.1](https://github.com/jorisnoo/laravel-remote-sync/releases/tag/v0.2.1) (2026-01-20)

### Bug Fixes

- bug when resolving service ([4fd9bc5](https://github.com/jorisnoo/laravel-remote-sync/commit/4fd9bc5c3a6379fc68dc7495e33bef3591a268fa))

### Documentation

- update changelog ([5310530](https://github.com/jorisnoo/laravel-remote-sync/commit/5310530ff59ab57a88d8afeb801531e0bc274e40))
## [0.2.0](https://github.com/jorisnoo/laravel-remote-sync/releases/tag/0.2.0) (2026-01-20)

### Features

- Auto-detect atomic deployments (Envoyer, Deployer, etc.)
- Update default excluded tables

### Changed

- Rename commands to `remote-sync` namespace (`remote-sync:sync`, `remote-sync:database`, `remote-sync:files`)

## [0.1.0](https://github.com/jorisnoo/laravel-remote-sync/releases/tag/0.1.0) (2026-01-19)

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
