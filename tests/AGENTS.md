# Test standards for agent-core

Directory-specific test infrastructure and conventions. Root `AGENTS.md` owns global Castor/safety/workflow rules. Operational runbooks (timeouts, check lock, llama-proxy cache guard, preflight, worker diagnostics, full command matrix): load the `testing` skill (`.agents/skills/testing/SKILL.md`).

## Hard quality standards (MUST / MUST NOT)

Bad tests are worse than missing tests. Prefer deletion/demotion over keeping a non-deterministic, soft, duplicate, or timing-window case.

### Duration and contention

- **Default hard ceiling:** each individual PHPUnit case MUST finish in **≤10s** under normal Castor load.
- Contention counts: a case that is ≤10s solo but **>10s under relevant concurrent lanes** (e.g. `castor check` + standalone `castor test:tui` / `castor test:llm-real`) is still unacceptable.
- Exceeding 10s → rewrite, demote to a lower layer, or delete. Rare exceptions MUST document in the test (comment or docblock): the unique external/process/provider contract, why lower layers cannot prove it, and that the timeout is a safety cap—not a sync strategy.
- MUST NOT “fix” flakes by raising Castor/PHPUnit/HTTP timeouts, retrying until green, or masking hangs with broad 20–60s waits.

### Determinism and synchronization

- MUST assert **positive** readiness: visible pane state, event type/id, artifact file contents, log line, socket accept, or process status. MUST NOT prove correctness by absence against stale tmux scrollback, “wait a few seconds and maybe”, or elapsed-time race windows.
- Timeouts are **safety caps**, not synchronization. Prefer existing harness predicates (`waitForCallback`, `waitForCaptureContains`, `waitForTuiReady`, `collectEventsUntil*`) with short bounds and early exit.
- MUST NOT use arbitrary `sleep` / `usleep` / `response_delay_ms` / multi-second shell `sleep N` solely to create interaction windows. If a product threshold forces a real sleep (document it), keep the shortest valid value; if the only thesis is a timing window and no deterministic barrier exists without a test-only production API, **delete the test**.
- Contention / locking proofs need deterministic barriers (locks, pipes, markers coupled to child liveness). Timing lotteries are unacceptable—delete them.
- MUST NOT busy-spin readiness loops. Yield (`usleep` of a few ms only inside a bounded predicate poll) or block on a real event.

### Isolation, ownership, teardown

- Controller/TUI E2E that uses Messenger MUST pair app DB + transport DB env (`TuiE2eDatabaseEnv` / equivalent). Omitting the transport DB is unacceptable.
- MUST NOT share tmux sessions, fixture FIFO cursors, or mutate process-global env without full restore of `putenv` + `$_ENV` + `$_SERVER`.
- Process readiness markers/files/sockets MUST be coupled to **child liveness** and fail with diagnostics if the child dies; blind `is_file` polls alone are unacceptable.
- Spawned controller/consumer trees MUST have explicit process-group/session ownership and deterministic teardown of the **owned tree**. Multiple roots (e.g. second controller) MUST track independent PID lists—never overwrite the first.
- Never signal root-owned or `HATFIELD_SESSION_ID` processes (root `AGENTS.md`).

### Fixtures, assertions, live proofs

- Replay fixture exhaustion MUST fail loudly (no synthetic successful `done`). Every legitimate LLM turn the test expects MUST have an explicit fixture.
- Soft/conditional assertions that allow the target behavior not to occur are unacceptable—delete or harden.
- Generated model prose is **not** a contract. Live/`llm-real` tests MUST assert provider/tool/event/schema/stream/artifact contracts (tool name, `tool_call_id`, stop/finality, non-empty structured output)—not chat wording.
- Live LLM and tmux exist for contracts unavailable at unit/virtual/controller-replay. Prefer **one** minimal terminal or live smoke per unique contract—not repeated journeys that re-prove picker/chrome/hotkeys/cards already covered virtually.
- MUST NOT add production APIs, settings, or paths solely for tests.

### Demotion / deletion evidence

When deleting or demoting an E2E/live case, record a short proof mapping (test or PR/task note): what lower-layer test(s) retain the contract. Reviewers MUST reject demotions with no mapping.

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

Fixture queues MUST cover every expected model turn. Exhaustion MUST fail loudly (`X-Replay-Exhausted` / HTTP error)—never fabricate a successful assistant `done`.

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

Use `TuiE2eDatabaseEnv` for paired app + Messenger transport DB isolation. Prefer `waitForTuiReady()` over duplicated logo/footer polls. Assert positive visible-pane / event / artifact proof—not scrollback absence.

TUI work is incomplete without automated proof at the lowest correct layer. Service-only DTO tests, custom smoke scripts, or picker/footer-only checks are not sole proof. Root `AGENTS.md` + testing skill own `castor check` triggers and live-vs-replay policy.

## What NOT to test

Do not write tests that only:

- Verify PHP intrinsics (enum `from()`/`value` round-trip)
- Verify trivial getter/setter pairs
- Verify class/method existence
- Exhaustively enumerate enum cases in dedicated cases

One representative behavior test is enough. Also do **not** keep: soft/conditional live proofs, timing-window races, duplicate tmux journeys of virtual coverage, or prose-only LLM assertions (see Hard quality standards).

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
