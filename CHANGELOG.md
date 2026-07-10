# Changelog

All notable changes to this project will be documented in this file.

## [0.7.1](https://github.com/jorisnoo/laravel-remote-sync/releases/tag/v0.7.1) (2026-07-10)
## [0.7.0](https://github.com/jorisnoo/laravel-remote-sync/releases/tag/v0.7.0) (2026-07-08)

### Features

- add prune and doctor commands, delete legacy core, rewrite docs ([c1ed8de](https://github.com/jorisnoo/laravel-remote-sync/commit/c1ed8de32646c4eb3a9002af5e3c9035999a3f03))
- add redesigned push command with hard push gate and explicit scope ([1342b49](https://github.com/jorisnoo/laravel-remote-sync/commit/1342b490ad4f1b2dbe57c4b1179ca09e95d0d60c))
- add SyncPlan, Planner, PlanRenderer, and the redesigned pull command ([bdf5649](https://github.com/jorisnoo/laravel-remote-sync/commit/bdf56493f852eb64e56cce816c86b91853fd2117))
- add Rsync builder, Snapshots lifecycle, Importer, CleanupStack, and StoragePath validation ([f2283d2](https://github.com/jorisnoo/laravel-remote-sync/commit/f2283d27d1235126f53751550af62fe3f2d2afa2))
- add Connection and single-round-trip Probe with RemoteInfo ([0e361d9](https://github.com/jorisnoo/laravel-remote-sync/commit/0e361d94ed507c69150b4a75a68ffcd54d877cbd))
- add Remote DTO, RemoteRegistry with placeholder validation, and v1 config schema ([bd879d5](https://github.com/jorisnoo/laravel-remote-sync/commit/bd879d5764824f28e4a0099e0a246d322c7246d4))

### Code Refactoring

- extract buildPlan method and add verbose rsync output ([eb3035f](https://github.com/jorisnoo/laravel-remote-sync/commit/eb3035fc1b856adf76d564f6090b8f3985eef28a))
## [0.6.2](https://github.com/jorisnoo/laravel-remote-sync/releases/tag/v0.6.2) (2026-07-06)

### Features

- auto-detect PHP binary version on remote servers ([b0fac45](https://github.com/jorisnoo/laravel-remote-sync/commit/b0fac45e06317499a6cb0353995515a9c14619e3))
- **justfile:** add default recipe and install/update targets ([d292b07](https://github.com/jorisnoo/laravel-remote-sync/commit/d292b079855b202e7900da164475066f67541ac4))
- exclude dotfiles from sync operations ([9a33db9](https://github.com/jorisnoo/laravel-remote-sync/commit/9a33db9b54382fdc88f1df523a996342070adb81))

### Code Refactoring

- **pull:** consolidate database and files confirmations into single prompt ([312e110](https://github.com/jorisnoo/laravel-remote-sync/commit/312e110217cdcf0f676b45e86c83e9ed65de2f93))

### Tests

- **push-files:** add withoutTty mock expectation ([1b14a6b](https://github.com/jorisnoo/laravel-remote-sync/commit/1b14a6bc845d6f799e60956f15b14d547dc05cb2))

### Build System

- **deps:** bump actions/checkout from 6 to 7 ([2dcdf4a](https://github.com/jorisnoo/laravel-remote-sync/commit/2dcdf4a3662445c111b318772006e590732cc8db))

### Continuous Integration

- simplify dependabot auto-merge workflow ([e9ed70f](https://github.com/jorisnoo/laravel-remote-sync/commit/e9ed70fc07fb5bfc3542835135b3c9c294a67420))
## [0.6.1](https://github.com/jorisnoo/laravel-remote-sync/releases/tag/v0.6.1) (2026-07-06)

### Features

- auto-detect PHP binary version on remote servers ([b0fac45](https://github.com/jorisnoo/laravel-remote-sync/commit/b0fac45e06317499a6cb0353995515a9c14619e3))
- **justfile:** add default recipe and install/update targets ([d292b07](https://github.com/jorisnoo/laravel-remote-sync/commit/d292b079855b202e7900da164475066f67541ac4))
- exclude dotfiles from sync operations ([9a33db9](https://github.com/jorisnoo/laravel-remote-sync/commit/9a33db9b54382fdc88f1df523a996342070adb81))

### Code Refactoring

- **pull:** consolidate database and files confirmations into single prompt ([312e110](https://github.com/jorisnoo/laravel-remote-sync/commit/312e110217cdcf0f676b45e86c83e9ed65de2f93))

### Tests

- **push-files:** add withoutTty mock expectation ([1b14a6b](https://github.com/jorisnoo/laravel-remote-sync/commit/1b14a6bc845d6f799e60956f15b14d547dc05cb2))

### Build System

- **deps:** bump actions/checkout from 6 to 7 ([2dcdf4a](https://github.com/jorisnoo/laravel-remote-sync/commit/2dcdf4a3662445c111b318772006e590732cc8db))

### Continuous Integration

- simplify dependabot auto-merge workflow ([e9ed70f](https://github.com/jorisnoo/laravel-remote-sync/commit/e9ed70fc07fb5bfc3542835135b3c9c294a67420))
## [0.6.0](https://github.com/jorisnoo/laravel-remote-sync/releases/tag/v0.6.0) (2026-03-26)

### Features

- add interactive confirmation for production environment operations ([1242e7d](https://github.com/jorisnoo/laravel-remote-sync/commit/1242e7d09040893c125d058f6788597b6fb8e312))
- add direct remote config and selective sync options ([1397b51](https://github.com/jorisnoo/laravel-remote-sync/commit/1397b51d3776dac49152a34a439c5c896ff40e63))
- show files to delete with warn styling in file sync preview ([6574ff7](https://github.com/jorisnoo/laravel-remote-sync/commit/6574ff707eab2ecb2f8af03f056c0c9e4901b0b8))
- run migrations on remote after push ([70b8998](https://github.com/jorisnoo/laravel-remote-sync/commit/70b89988adc28f794debfb5f3beff4ddf641579a))

### Code Refactoring

- consolidate remote database info into single method call ([01c2ae3](https://github.com/jorisnoo/laravel-remote-sync/commit/01c2ae31323e909ee709e8648a54092770091030))

### Continuous Integration

- remove deprecated workflows and simplify dependabot config ([e8f14e5](https://github.com/jorisnoo/laravel-remote-sync/commit/e8f14e5ac671a5ea9d1ab95a082f7d741bcae06e))
## [0.5.0](https://github.com/jorisnoo/laravel-remote-sync/releases/tag/v0.5.0) (2026-03-13)

### Features

- run migrate after pull instead of comparing migrations ([85dea9c](https://github.com/jorisnoo/laravel-remote-sync/commit/85dea9ceb0972080788ff289c084f917f1ba127a))
- clear application cache after database pull ([1aeada1](https://github.com/jorisnoo/laravel-remote-sync/commit/1aeada1887957f8315df4506a0c34265fd50b4ba))
- add wildcard support for user email filtering ([cb015d1](https://github.com/jorisnoo/laravel-remote-sync/commit/cb015d1d8e449afbae468141af8b20077a181af4))
- add SSH host key verification before remote operations ([da2c93a](https://github.com/jorisnoo/laravel-remote-sync/commit/da2c93a15db7b38c262206d9b59c0de44c841eac))

### Bug Fixes

- wildcard user filtering deleting all users ([4c94b13](https://github.com/jorisnoo/laravel-remote-sync/commit/4c94b13916143ddf4929765ee5d67b6eddc8f45e))

### Code Refactoring

- simplify pull/push confirmation flow and remove migration mismatch checks ([ce02689](https://github.com/jorisnoo/laravel-remote-sync/commit/ce026896a19f43668c2a213b8ad2b62431e67566))
- revert preview bullet lists to inline comma-separated format ([980b109](https://github.com/jorisnoo/laravel-remote-sync/commit/980b1094483820fe3c36593c83df0d6121ce5c0a))
## [0.4.1](https://github.com/jorisnoo/laravel-remote-sync/releases/tag/v0.4.1) (2026-03-13)

### Features

- add SSH host key verification before remote operations ([da2c93a](https://github.com/jorisnoo/laravel-remote-sync/commit/da2c93a15db7b38c262206d9b59c0de44c841eac))
## [0.4.0](https://github.com/jorisnoo/laravel-remote-sync/releases/tag/v0.4.0) (2026-03-10)

### Features

- make rsync timeout configurable via remote-sync.timeouts.file_sync config ([88f8dcb](https://github.com/jorisnoo/laravel-remote-sync/commit/88f8dcb846231c224a6363c53efd51f498b451d3))
## [0.3.9](https://github.com/jorisnoo/laravel-remote-sync/releases/tag/v0.3.9) (2026-03-09)

### Features

- add migration mismatch detection and confirmation before push/pull operations ([e39ead0](https://github.com/jorisnoo/laravel-remote-sync/commit/e39ead07d7425db2d4d3b50b30b0aac4d5d23b69))
- display individual file names in sync preview for transfer and delete operations ([640de88](https://github.com/jorisnoo/laravel-remote-sync/commit/640de88a765e25f29d0bdfc2370b4994a7a6d3f3))

### Bug Fixes

- replace example comments with actual exclude path in remote-sync config ([ca7e965](https://github.com/jorisnoo/laravel-remote-sync/commit/ca7e9657a13dbc3fb9787bd71f7105c05a94313b))
## [0.3.8](https://github.com/jorisnoo/laravel-remote-sync/releases/tag/v0.3.8) (2026-03-09)

### Features

- add --force option to skip confirmation prompt in push command ([ae121a5](https://github.com/jorisnoo/laravel-remote-sync/commit/ae121a57f1c6e4c15d3a94e3ebee1c70e0782b2c))
## [0.3.7](https://github.com/jorisnoo/laravel-remote-sync/releases/tag/v0.3.7) (2026-03-07)

### Features

- add withoutTty option to make TTY usage configurable for rsync processes ([a53666d](https://github.com/jorisnoo/laravel-remote-sync/commit/a53666d36012dc6e1a4906d8827dc2b04341b21a))
## [0.3.6](https://github.com/jorisnoo/laravel-remote-sync/releases/tag/v0.3.6) (2026-03-05)

### Features

- add filter_users option to keep only allowed users after database pull ([517176a](https://github.com/jorisnoo/laravel-remote-sync/commit/517176abb0aabbdaee0d40cf55e94b5dfc1f7f0c))
- add migration comparison preview, preserve migrations table during sync, and detect remote snapshot subdirectory ([862889d](https://github.com/jorisnoo/laravel-remote-sync/commit/862889d85e4261660f204ff35eea594f36b56327))
## [0.3.5](https://github.com/jorisnoo/laravel-remote-sync/releases/tag/v0.3.5) (2026-02-11)

### Code Refactoring

- load snapshots via direct CLI piping instead of artisan command and extract shared test helper ([4a91abd](https://github.com/jorisnoo/laravel-remote-sync/commit/4a91abd13efb45481636046200f264949498728c))
## [0.3.4](https://github.com/jorisnoo/laravel-remote-sync/releases/tag/v0.3.4) (2026-02-11)

### Bug Fixes

- use --stream flag for snapshot loading instead of removing memory limit ([7d5a605](https://github.com/jorisnoo/laravel-remote-sync/commit/7d5a605f2704a5d548aaf71ddaa59049c05daf75))

### Code Refactoring

- extract selectRemote into InteractsWithRemote trait and add remote selection to individual commands ([73e5849](https://github.com/jorisnoo/laravel-remote-sync/commit/73e58491dc9894f190b164bf6fa67ff53891a44f))
## [0.3.3](https://github.com/jorisnoo/laravel-remote-sync/releases/tag/v0.3.3) (2026-02-11)

### Features

- add snapshot_load timeout, direction-aware file preview headers, and cross-platform stat fallback ([c80951d](https://github.com/jorisnoo/laravel-remote-sync/commit/c80951d32f1a0d2f977e030360c529194c52f2f7))
- support exclude_tables config in push-db command with direction-aware preview labels ([272dfdf](https://github.com/jorisnoo/laravel-remote-sync/commit/272dfdf4fe103b695e518c0753f9741d023e2ee4))
- add preview for push-db and push-files commands ([85e8194](https://github.com/jorisnoo/laravel-remote-sync/commit/85e81947822179521a570e0b014a6f983043df81))

### Bug Fixes

- temporarily remove memory limit during snapshot load to prevent out-of-memory errors ([dd09cf5](https://github.com/jorisnoo/laravel-remote-sync/commit/dd09cf50991777322b9d42365bc2f980d1689c36))
- add Laravel 11 compatibility for getCurrentSchemaName ([6f78fba](https://github.com/jorisnoo/laravel-remote-sync/commit/6f78fba9213c18f3204024d9efb3c96fcc2b9e64))
- scope getTableListing to current database to avoid cross-database pollution ([3ece619](https://github.com/jorisnoo/laravel-remote-sync/commit/3ece619afb965ed0252b96dbfb94c8c783aac5ac))

### Code Refactoring

- simplify database preview to show table names only, remove row counts, and improve pluralized labels ([bd53a92](https://github.com/jorisnoo/laravel-remote-sync/commit/bd53a9248b8ea6ad97d7859b2d32cb0e5d5ead9e))
- rename Sync commands to Pull for clearer terminology ([b0ec5d6](https://github.com/jorisnoo/laravel-remote-sync/commit/b0ec5d6e1314058752fc5cc20a011a8e053a2bfc))

### Build System

- add support URLs and author homepage for Packagist ([74f75ee](https://github.com/jorisnoo/laravel-remote-sync/commit/74f75eed30e782bb01aa5a1e488315ae961bc4a4))
## [0.3.2](https://github.com/jorisnoo/laravel-remote-sync/releases/tag/v0.3.2) (2026-02-11)

### Features

- add snapshot_load timeout, direction-aware file preview headers, and cross-platform stat fallback ([c80951d](https://github.com/jorisnoo/laravel-remote-sync/commit/c80951d32f1a0d2f977e030360c529194c52f2f7))
- support exclude_tables config in push-db command with direction-aware preview labels ([272dfdf](https://github.com/jorisnoo/laravel-remote-sync/commit/272dfdf4fe103b695e518c0753f9741d023e2ee4))
- add preview for push-db and push-files commands ([85e8194](https://github.com/jorisnoo/laravel-remote-sync/commit/85e81947822179521a570e0b014a6f983043df81))

### Bug Fixes

- temporarily remove memory limit during snapshot load to prevent out-of-memory errors ([dd09cf5](https://github.com/jorisnoo/laravel-remote-sync/commit/dd09cf50991777322b9d42365bc2f980d1689c36))
- add Laravel 11 compatibility for getCurrentSchemaName ([6f78fba](https://github.com/jorisnoo/laravel-remote-sync/commit/6f78fba9213c18f3204024d9efb3c96fcc2b9e64))
- scope getTableListing to current database to avoid cross-database pollution ([3ece619](https://github.com/jorisnoo/laravel-remote-sync/commit/3ece619afb965ed0252b96dbfb94c8c783aac5ac))

### Code Refactoring

- simplify database preview to show table names only, remove row counts, and improve pluralized labels ([bd53a92](https://github.com/jorisnoo/laravel-remote-sync/commit/bd53a9248b8ea6ad97d7859b2d32cb0e5d5ead9e))
- rename Sync commands to Pull for clearer terminology ([b0ec5d6](https://github.com/jorisnoo/laravel-remote-sync/commit/b0ec5d6e1314058752fc5cc20a011a8e053a2bfc))

### Build System

- add support URLs and author homepage for Packagist ([74f75ee](https://github.com/jorisnoo/laravel-remote-sync/commit/74f75eed30e782bb01aa5a1e488315ae961bc4a4))
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
