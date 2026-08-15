# Test standards for agent-core

Directory-specific test infrastructure and conventions. Root `AGENTS.md` owns global Castor/safety/workflow rules. Operational runbooks (timeouts, check lock, llama-proxy cache guard, preflight, worker diagnostics, full command matrix): load the `testing` skill (`.agents/skills/testing/SKILL.md`).

## Shared infrastructure (do not duplicate)

### Directory isolation

Use `TestDirectoryIsolation` (`tests/CodingAgent/Support/TestDirectoryIsolation.php`) for all temporary directories:

- `TestDirectoryIsolation::createProjectTempDir()` — project `var/tmp/<prefix>-<random>`
- `TestDirectoryIsolation::createOsTempDir()` — OS `/tmp/<prefix>-<random>`
- `TestDirectoryIsolation::createHatfieldTree($root, withSessions)` — scaffold `.hatfield/`
- `TestDirectoryIsolation::removeDirectory($dir)` — recursive removal with permission normalization

Do **not**:

- Call `sys_get_temp_dir()` directly — use the helpers above
- Hand-roll `mkdir('.../.hatfield')` — use `createHatfieldTree()`
- Define per-file `removeDir()` / `rmdirRecursive()` — use `removeDirectory()`
- Leave temp dirs uncleaned — use `try/finally` or `tearDown`

E2E isolation uses `var/tmp/test-{uuid}` trees only — never real project `.hatfield/sessions/`.

### Test doubles

- `TestMessageBus` (`tests/AgentCore/Support/TestMessageBus.php`) — collecting `MessageBusInterface`
- `TestLogger` (`tests/AgentCore/Support/TestLogger.php`) — collecting PSR-3 logger

Namespace: `Ineersa\AgentCore\Tests\Support`. Do not invent per-file collecting bus/logger clones. Keep specialized fakes (e.g. conditional throw) local to the needing test.

### Config fixtures

When constructing `AppConfig` with AI model data, prefer the minimal shape. If the same shape recurs across files, use/share a builder rather than pasting large arrays.

## Controller E2E bases

### Replay (default, deterministic)

Extend `ControllerReplayE2eTestCase`. Run: `castor test:controller-replay`. No live LLM.

Replay seam is test-layer only:

- `ControllerReplayHttpClientFactory` (`tests/CodingAgent/Runtime/Controller/E2E/Replay/`) honors `HATFIELD_LLM_REPLAY_FIXTURE_PATH` and returns a MockHttpClient
- `config/services_test.yaml` wires `HttpClientInterface` through that factory
- Controller subprocess boots `APP_ENV=test` so test DI applies; **no** production `src/` code checks the replay env var

### Live (opt-in)

Extend `ControllerE2eTestCase`. Requires live LLM readiness. Run: `castor test:controller` / `castor test:llm-real` (group `llm-real`). See testing skill for preflight, proxy, and timeouts.

Inherited helpers (do not reimplement inline `byType`/ack loops):

- `indexByType`, `foundAck`, `assertStartRunAcked`
- `collectEvents`, `collectEventsUntil`, `collectEventsUntilToolCompleted`
- `collectDiagnostics`
- Live waits: `liveControllerReadyTimeout()`, `liveLlmToolWaitTimeout()`, `liveLlmRunWaitTimeout()` — prefer early-exit collectors over full-timeout drains

Live `llm-real` scenarios that share llama-proxy cache normalization must use a **unique first user prompt** per scenario (e.g. `[llm-real:write-file] …`) so stripped prologue keys do not collide. Live controller subprocess uses source `bin/console` with `APP_ENV=test` (and `APP_DEBUG=1` for diagnostics). Do **not** spawn the PHAR with `APP_ENV=test` — dev-only bundles are excluded from the PHAR.

For tool-focused LLM smoke: prefer `collectEventsUntilToolCompleted()`; assert intended `tool_name`, matching `tool_call_id`, and presence/absence of `tool_execution.failed` as appropriate. Do not hard-require `run.completed` when the contract is tool execution.

## TUI tests (lowest correct layer)

1. **Virtual / in-process** (`tests/Tui/Screen/`, `VirtualTuiHarness`): layout, editor input, local slash commands, render on `ScreenBuffer`. Run: `castor test`.
2. **Controller replay** (`ControllerReplayE2eTestCase`): runtime protocol, events, shell/tool ordering. Run: `castor test:controller-replay`.
3. **Minimal tmux smoke** (`#[Group('tui-e2e-replay')]`, `castor test:tui`): real TTY/tmux/process boot only. `TuiJourneyE2eTest` is narrow integration smoke — not a template for every feature.

When tmux is required: `startDetached()`, isolated project dir, `sendLiteral`/`sendKey`, short targeted waits (`waitForCaptureContains` / `waitForCallback`), `saveAnsiSnapshot()` for artifacts. Avoid broad 30–60s caps and fixed `usleep()` unless delay is the behavior under test.

TUI work is incomplete without automated proof at the lowest correct layer. Service-only DTO tests, custom smoke scripts, or picker/footer-only checks are not sole proof. Root `AGENTS.md` + testing skill own `castor check` triggers and live-vs-replay policy.

## What NOT to test

Do not write tests that only:

- Verify PHP intrinsics (enum `from()`/`value` round-trip)
- Verify trivial getter/setter pairs
- Verify class/method existence
- Exhaustively enumerate enum cases in dedicated cases

One representative behavior test is enough.

## One test class per production class

Group methods for a single production class in one test file. Avoid many tiny files per class. Shared helpers/doubles used by multiple files live under `tests/*/Support/`.

## Kernel-test base classes

- **`IsolatedKernelTestCase`** (`tests/CodingAgent/TestCase/`) — preferred for most DB/integration tests. Boots kernel once per class; DAMA provides per-method transaction rollback.
- **`PerMethodIsolatedKernelTestCase`** — per-method boot. Use only when tests mutate the live container via `Container::set()` or need a freshly-booted kernel to see per-method filesystem artifacts.

Both handle CWD isolation, env vars, exception-handler balance, and directory cleanup.

## DB-touching tests

Must boot the Symfony kernel via the bases above and use the test container. Do not hand-roll `ORMSetup`/`DriverManager`/`SchemaTool` factories. DAMA rolls back each method — no manual DB cleanup.

ParaTest (`castor test` default) shares SQLite with DAMA transaction isolation; each worker gets its own compiled cache via `TEST_TOKEN` in `tests/paratest-bootstrap.php`. DB path: `HATFIELD_TEST_DATABASE_PATH` (default `app_test.sqlite`). Filtered/`--filter` runs are sequential. Detail: testing skill.

## Castor (pointer only)

All QA goes through Castor — never raw `vendor/bin/*` except isolating a Castor failure. PHPUnit flags and the full matrix live in the testing skill.

Common entries: `castor test`, `castor test:tui`, `castor test:controller-replay`, `castor test:llm-real`, `castor test:controller`, `castor llm:fixtures:record`, `castor llm:fixtures:info`, `castor deptrac`, `castor phpstan`, `castor cs-check` / `castor cs-fix`, `castor check`, `castor clean:cleanup`.

## Snapshots and artifacts

- Passing TUI E2E snapshots: `var/tmp/tui-e2e-*/` (do **not** delete in `tearDown()`)
- Failures: `var/tmp/tui-failures/`
- `castor clean:cleanup` removes generated temp/test artifacts when intentional cleanup is needed — not routine pre-retry worker killing (see root safety rules)

## Real LLM prompts (smoke robustness)

Even with temperature 0 / fixed seed on the test model:

- Name exact tool and exact relative path (`./file.txt`), not vague NL
- Keep instructions short and schema-like
- Assert runtime/tool events, not prose wording
- Prefer fast targeted waits; if a short tool wait fails, debug prompt/route/stale lifecycle — do not blindly raise to 60s

## LLM fixture replay (committed)

Deterministic fixtures under `tests/AgentCore/Fixtures/traces/` and controller/TUI fixture dirs. Helpers: `tests/AgentCore/Infrastructure/SymfonyAi/Replay/` (`FixtureReplayModelClient`, `FixtureReplayResultConverter`, `StreamRecorderObserver`, `ReplayTest`, `ReplayRecordingTest`). Format: `docs/llm-replay.md`. Re-record: `castor llm:fixtures:record`.

llama-proxy cassettes (live HTTP on :9052) are a **different** mechanism from committed fixtures — see testing skill; do not conflate them.
