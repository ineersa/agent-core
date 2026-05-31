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
Status: TODO
Branch:
Worktree:
Fork run:
PR URL:
PR Status:
Started:
Completed:

## Work log
- Created: 2026-05-31T19:32:28.032Z
