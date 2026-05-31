# Replace raw DBAL stores with Doctrine ORM entities, repositories, and migrations

## Goal
User directive: stop using ad hoc DBAL table creation/custom normalizers for runtime DB state. Convert project-owned database tables to standard Doctrine ORM entities/repositories with real migrations, and run migrations once when the agent starts.

Context/root problem:
- `background_process.object_normalizer` was introduced only to hydrate snake_case DB rows into `BackgroundProcessRecord`, but because services autoconfigure normalizers, it entered the global serializer chain and broke unrelated RunState/RunStatus denormalization during E2E (`NotNormalizableValueException: RunStatus is not instantiable`).
- Raw `CREATE TABLE IF NOT EXISTS` / `ALTER TABLE` inside runtime stores is brittle and bypasses Doctrine's normal schema lifecycle.
- The desired direction is standard Doctrine usage: mapped Entity classes, Repository services, migrations, and a startup migration runner.

Initial inventory from `rg`:
- `src/CodingAgent/Tool/BackgroundProcess/ProcessStore.php`: DBAL `Connection`, manual `CREATE TABLE IF NOT EXISTS background_process`, `ALTER TABLE`, raw insert/update/select/delete, custom `DenormalizerInterface` hydration.
- `src/CodingAgent/Tool/Store/DbalToolBatchStore.php`: DBAL `Connection`, lazy manual `CREATE TABLE IF NOT EXISTS`, raw fetch/update/insert for tool batch state.
- Tests manually creating SQLite DBAL connections: `tests/CodingAgent/Tool/BackgroundProcessManagerTest.php`, `tests/CodingAgent/Tool/BgStatusToolTest.php`, `tests/CodingAgent/Tool/Store/DbalToolBatchStoreTest.php`.
- E2E diagnostics read `messenger.sqlite` via PDO in `ControllerE2eTestCase` and `TuiAgentSmokeTest`; diagnostics can stay raw read-only if justified, but production-owned tables should move to ORM/migrations.
- Messenger Doctrine transport still legitimately uses Doctrine transport tables; do not replace Symfony Messenger internals.

Implementation notes:
- Add Doctrine ORM mapping config if not already enabled for `src/CodingAgent/**/Entity` (and/or `src/AgentCore/**/Entity` if needed). Keep architecture boundaries intact.
- Introduce entities and repositories for project-owned tables, at minimum background process records and tool batch records.
- Remove dedicated serializer/ObjectNormalizer from background process persistence; row-to-DTO conversion should be explicit from entities or entity itself should provide read model conversion.
- Replace runtime schema creation/ALTER code with Doctrine migrations.
- Add a startup migration runner that runs once per agent process/startup for the Hatfield project DB before consumers/tools need the tables. It must be safe/idempotent, concurrency-safe enough for controller + consumers, and must not run migrations repeatedly per tool call.
- Respect runtime cwd/AppConfig DB path semantics: database remains under the Hatfield project `.hatfield/` location, not kernel project dir.
- Update docs/settings/session/runtime docs if database lifecycle behavior is user-visible.

Out of scope unless discovered necessary:
- Replacing Symfony Messenger Doctrine transport implementation itself.
- Rewriting read-only E2E diagnostic PDO queries, unless they depend on project-owned tables being removed/renamed.

## Acceptance criteria
- All project-owned DB tables currently created via raw DBAL runtime `CREATE TABLE`/`ALTER TABLE` are represented as Doctrine ORM entities with repository services.
- Schema changes are managed by Doctrine migrations, not ad hoc store initialization. Migrations run once on agent startup for the current Hatfield project database and are safe when multiple agent/consumer processes start.
- `background_process.object_normalizer` and its custom name converter are removed; no custom serializer normalizer is used for DB row hydration.
- Background process manager/status behavior and tool batch persistence behavior remain functionally equivalent.
- Tests are updated to use repositories/entity manager or production service wiring; no production APIs are added solely for tests.
- `castor check` is run before handoff. If llama.cpp/tmux prerequisites are unavailable, record exact blocker output and keep task IN-PROGRESS.

## Workflow metadata
Status: IN-PROGRESS
Branch: task/doctrine-orm-repositories-migrations
Worktree: /home/ineersa/projects/agent-core-worktrees/doctrine-orm-repositories-migrations
Fork run: u3b6rk6iho0c
PR URL:
PR Status:
Started: 2026-05-31T19:33:27.506Z
Completed:

## Work log
- Created: 2026-05-31T19:32:28.032Z

## Task workflow update - 2026-05-31T19:33:27.506Z
- Moved TODO → IN-PROGRESS.
- Created branch task/doctrine-orm-repositories-migrations.
- Created worktree /home/ineersa/projects/agent-core-worktrees/doctrine-orm-repositories-migrations.
- Copied vendor directory into /home/ineersa/projects/agent-core-worktrees/doctrine-orm-repositories-migrations.
- Copied .vera index into /home/ineersa/projects/agent-core-worktrees/doctrine-orm-repositories-migrations.

## Task workflow update - 2026-05-31T19:33:45.846Z
- Recorded fork run: u4yygi4ubdw4
- Moved task to IN-PROGRESS and launched implementation fork u4yygi4ubdw4 in /home/ineersa/projects/agent-core-worktrees/doctrine-orm-repositories-migrations. Fork scope: convert project-owned raw DBAL stores/custom DB-row normalizers to Doctrine ORM entities/repositories/migrations; add startup migration runner; remove background_process.object_normalizer from global serializer risk; update tests; use Castor validation. User allowed skipping/recording known broken E2E failures while focusing on persistence conversion.

## Task workflow update - 2026-05-31T19:45:43.411Z
- Recorded fork run: u4yygi4ubdw4
- Validation: castor test --filter="BackgroundProcessManager\|BgStatusTool\|DbalToolBatchStore": PASS (31 tests, 69 assertions); castor test: PASS (1542 tests, 11534 assertions); castor deptrac: PASS (0 violations); castor phpstan: PASS per fork; fork notes only pre-existing match.alwaysTrue in QuestionController / no new errors; castor cs-check: PASS; castor check/test:controller/test:tui/test:llm-real: not run by fork; fork cited llama.cpp/tmux prerequisites and user allowed skipping known broken E2E while implementing persistence conversion.
- Summary: Fork completed implementation and committed 60e70917 on task/doctrine-orm-repositories-migrations. It removed background_process.object_normalizer/name_converter, added Doctrine ORM configuration, BackgroundProcess and ToolBatchState entities, rewrote ProcessStore and DbalToolBatchStore to use EntityManager, added migration class Version20260531000000 and MigrationRunner, and updated tests. Note for parent review: fork reports migrations run lazily on first BackgroundProcessManager access, not literally once at agent startup; and BackgroundProcessRepository exists but is unused. These may need follow-up/revision against the task wording/user directive.

## Task workflow update - 2026-05-31T21:18:51.997Z
- Summary: User rejected two implementation gaps in commit 60e70917: (1) scope must cover ANY project-owned database interaction/usages discovered, not only the two obvious stores; (2) migrations must run on every agent startup once, not lazily on first BackgroundProcessManager call. Revise implementation before CODE-REVIEW.
- Continuation requirements: inventory all production/test database interactions after 60e70917. Project-owned runtime DB access must go through standard Doctrine Entity/Repository/EntityManager patterns. Stores should not hide DQL/raw querying directly when a Repository is expected; use repositories (or create missing repositories such as ToolBatchStateRepository). Remove unused repository classes or wire/use them. Keep Symfony Messenger transport internals as framework-owned, but document/avoid raw project-owned DB operations. Move MigrationRunner invocation from lazy BackgroundProcessManager access to agent startup (e.g. AgentCommand startup before controller/TUI/headless flow launches consumers/tools). MigrationRunner should still be per-process idempotent and safe across concurrent startup.

## Task workflow update - 2026-05-31T21:19:08.320Z
- Recorded fork run: qnl1oo16hf3h
- Launched continuation fork qnl1oo16hf3h to fix rejected gaps: inventory every DB interaction, move migrations to agent startup once (not lazy BackgroundProcessManager first-call), use actual repositories for project-owned DB queries (use/remove BackgroundProcessRepository, add ToolBatchStateRepository as needed), and re-run Castor validation.

## Task workflow update - 2026-05-31T21:24:08.859Z
- Recorded fork run: qnl1oo16hf3h
- Validation: castor test --filter="BackgroundProcessManager\|BgStatusTool\|DbalToolBatchStore": PASS (31 tests, 69 assertions); castor test: PASS (1542 tests, 11534 assertions); castor deptrac: PASS (0 violations); castor phpstan: PASS (0 errors; fork says baseline regenerated for pre-existing QuestionController match.alwaysTrue); castor cs-check: PASS; castor check/test:controller/test:llm-real/test:tui: not run by fork; E2E prerequisites llama.cpp on port 9052 and tmux may be required.
- Summary: Continuation fork completed and committed a63fc689. It moved MigrationRunner invocation into AgentCommand::__invoke() on agent startup before controller/headless/TUI dispatch; rewired BackgroundProcessRepository and added ToolBatchStateRepository; updated ProcessStore and DbalToolBatchStore to delegate project-owned DB queries to repositories; left Messenger Doctrine transport and read-only E2E PDO diagnostics unchanged. It reports full DB interaction inventory complete and focused validations passing. Parent review note: fork says phpstan baseline was regenerated for pre-existing QuestionController match.alwaysTrue; verify this did not introduce an unrelated baseline change before CODE-REVIEW. Defensive BackgroundProcessManager ensureMigrations remains as idempotent fallback after startup call.

## Task workflow update - 2026-05-31T21:24:51.252Z
- Recorded fork run: u3b6rk6iho0c
- Launched final review/fix fork u3b6rk6iho0c before CODE-REVIEW. Scope: verify no project-owned raw DB interactions remain; verify migrations run on agent startup before controller/headless/TUI flows; inspect and preferably remove unrelated phpstan-baseline.neon expansion from a63fc689 by fixing the underlying QuestionController match.alwaysTrue issue if safe; run Castor validation and commit if needed.

## Task workflow update - 2026-05-31T21:28:05.555Z
- Recorded fork run: u3b6rk6iho0c
- Validation: castor phpstan: PASS (0 errors; no extra baseline hunk); castor deptrac: PASS (0 violations, 0 errors); castor test --filter="BackgroundProcessManager\|BgStatusTool\|DbalToolBatchStore": PASS (31 tests, 69 assertions); castor test: PASS (1542 tests, 11534 assertions); castor cs-check: PASS (0 files to fix); castor check/test:controller/test:llm-real/test:tui: not run by fork; requires llama.cpp on port 9052 and tmux prerequisites.
- Summary: Final review/fix fork completed and committed b8b4866b. It removed the unrelated phpstan-baseline.neon hunk from a63fc689 by fixing QuestionController's unreachable default match arm; verified startup migration placement in AgentCommand::__invoke(); inventoried DB usage and reports no raw DBAL remains in production src except legitimate MigrationRunner DBAL connection usage through Doctrine Migrations; repositories are used for background_process and tool_batch_state queries; Messenger Doctrine transport and read-only E2E diagnostics remain unchanged.

## Task workflow update - 2026-05-31T21:29:36.281Z
- Validation: castor check: attempted; deptrac PASS and unit test PASS, then ControllerSmokeTest failed before runtime.ready due to duplicate services.yaml key for DbalToolBatchStore (real branch error, not known main blocker). Run was stopped after diagnosis.; castor cache:clear: PASS after fix; castor phpstan: PASS (0 errors) after fix; castor cs-check: PASS (0 files fixed) after fix; castor test --filter="BackgroundProcessManager|BgStatusTool|DbalToolBatchStore": PASS (31 tests, 69 assertions) after fix
- Summary: Parent ran castor check and found a real branch-introduced YAML/container error before known main E2E blockers: duplicate service key `Ineersa\CodingAgent\Tool\Store\DbalToolBatchStore` in config/services.yaml. Fixed in commit 0e25a97e by removing the duplicate earlier service definition and updating the remaining comment to ORM/repository wording.
