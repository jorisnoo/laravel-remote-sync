# Changelog

All notable changes to this project will be documented in this file.

## [0.3.1](https://github.com/jorisnoo/laravel-remote-sync/releases/tag/v0.3.1) (2026-01-21)

### Features

- add empty database check with migration prompt for standard import ([9c17379](https://github.com/jorisnoo/laravel-remote-sync/commit/9c17379c9a4249fa0e402dd9256d965abc80195b))
- ignore dotfiles when syncing ([c66f351](https://github.com/jorisnoo/laravel-remote-sync/commit/c66f3510e4df56b15c3ca01e6f593079921eef96))

### Bug Fixes

- drop Windows from test matrix due to sqlite3 unavailability ([d293a02](https://github.com/jorisnoo/laravel-remote-sync/commit/d293a0233b1e56f4dbb3717fab46251f09df85b8))
- add Windows compatibility for signal constants ([22ea864](https://github.com/jorisnoo/laravel-remote-sync/commit/22ea8647bed6901292b99b75ab63b96df79b7eb1))
- add security hardening for command injection and path traversal ([3759ef7](https://github.com/jorisnoo/laravel-remote-sync/commit/3759ef7cc02467a8c2e78e9d4b3f7c7cd2c184de))
- tests ([26bc901](https://github.com/jorisnoo/laravel-remote-sync/commit/26bc901c86a577de951f0e74cce99165d42276c0))

### Build System

- link to changelog in release file ([43d966a](https://github.com/jorisnoo/laravel-remote-sync/commit/43d966ab8d199a7f93c58bdb59cafa1131a27e95))
- update release workflow ([c574102](https://github.com/jorisnoo/laravel-remote-sync/commit/c5741024883a2876b1b03dd37194f0d99ba2f596))
## [0.3.0](https://github.com/jorisnoo/laravel-remote-sync/releases/tag/v0.3.0) (2026-01-21)

### Features

- add dry-run option to files sync ([54a97c0](https://github.com/jorisnoo/laravel-remote-sync/commit/54a97c0349fde30d48d60d4ac5dad349ca0a40c0))
- add file exclude paths ([64e4df8](https://github.com/jorisnoo/laravel-remote-sync/commit/64e4df8e692bb83b0c8b79b9114fd3ef8a52a3cf))
- add interactive prompts to all commands ([d3ba847](https://github.com/jorisnoo/laravel-remote-sync/commit/d3ba847226e54ac11024fccec513338ec7415775))
- add a cleanup command ([04e4aa3](https://github.com/jorisnoo/laravel-remote-sync/commit/04e4aa3e123f31c8e1cba6f9e5a860320c393793))
- allow pulling full database, w/ dropping before import ([67396e2](https://github.com/jorisnoo/laravel-remote-sync/commit/67396e27094993f928432eccd9a8b40c90797acd))
- allow pulling full database, w/ dropping before import ([e86b8dd](https://github.com/jorisnoo/laravel-remote-sync/commit/e86b8dd39eaca68e3d467df19ad5afa6bfc08108))

### Bug Fixes

- prevent path duplication when remote path ends with /current ([93a58c1](https://github.com/jorisnoo/laravel-remote-sync/commit/93a58c1bc24ca5578b15b1425eb03f36f1d75a1a))
- use configured snapshot path for remote operations ([4f51add](https://github.com/jorisnoo/laravel-remote-sync/commit/4f51adddab0a21e6d0a9e3fb59934978ba9ff09d))

### Code Refactoring

- extract lang files ([7e92dff](https://github.com/jorisnoo/laravel-remote-sync/commit/7e92dffb18dec13058369ac86369f8ff1ee2d411))

### Documentation

- update reame ([39ae132](https://github.com/jorisnoo/laravel-remote-sync/commit/39ae132ac965a7912255a521fbaf5dd2024ce99f))

### Build System

- release workflow ([5a5d1d9](https://github.com/jorisnoo/laravel-remote-sync/commit/5a5d1d9a76c4055066cba743c05851d94c0086b1))

### Styles

- lint ([e5ef717](https://github.com/jorisnoo/laravel-remote-sync/commit/e5ef7171fc1380eec296ccc8e11ac0f4535cda34))
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
