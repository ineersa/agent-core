# Test standards for agent-core

## Shared infrastructure (do not duplicate)

### Directory isolation

Use `TestDirectoryIsolation` for all temporary directories:
- `TestDirectoryIsolation::createProjectTempDir()` — project `var/tmp/<prefix>-<random>`
- `TestDirectoryIsolation::createOsTempDir()` — OS `/tmp/<prefix>-<random>`
- `TestDirectoryIsolation::createHatfieldTree($root, withSessions)` — scaffold `.hatfield/`
- `TestDirectoryIsolation::removeDirectory($dir)` — recursive removal with permission normalization

Do NOT:
- Call `sys_get_temp_dir()` directly — use `createProjectTempDir()` or `createOsTempDir()`
- Write ad-hoc `mkdir('.../.hatfield')` — use `createHatfieldTree()`
- Define per-file `removeDir()` or `rmdirRecursive()` — use `removeDirectory()`
- Leave temp dirs uncleaned. Use `try/finally`, `tearDown`, or `castor cleanup`.

### Test doubles

- `TestMessageBus` (`tests/AgentCore/Support/TestMessageBus.php`) — collecting `MessageBusInterface` for asserting dispatched messages. Do not define per-file collecting bus classes.
- `TestLogger` (`tests/AgentCore/Support/TestLogger.php`) — collecting PSR-3 logger for tracing/log assertions. Do not define per-file `WorkerTraceLogger` variants.

Both are available under the `Ineersa\AgentCore\Tests\Support` namespace. See:
- `tests/AgentCore/Support/TestMessageBus.php` for `@see TestMessageBus`
- `tests/AgentCore/Support/TestLogger.php` for `@see TestLogger`
- Keep specialized fakes (e.g. `FailingOnceMessageBus` with conditional throw behavior) local to the test that needs them.

### E2E controller tests

#### Controller replay E2E (default, deterministic)

Extend `ControllerReplayE2eTestCase` for replay-backed controller E2E tests.
These do NOT require live LLM.  Run with `castor test:controller-replay`.

The replay seam is entirely in the test layer:
- `ControllerReplayHttpClientFactory` (`tests/CodingAgent/Runtime/Controller/E2E/Replay/`)
  checks `HATFIELD_LLM_REPLAY_FIXTURE_PATH` and returns a MockHttpClient.
- `config/services_test.yaml` wires `HttpClientInterface` through the factory.
- The controller subprocess boots with `APP_ENV=test` so `services_test.yaml`
  is loaded and `SymfonyAiProviderFactory` receives the injected replay client
  through its existing constructor DI path.
- No production code in `src/` checks the replay env var.

#### Controller live E2E (opt-in)

Extend `ControllerE2eTestCase` for headless controller E2E against live LLM.
These require `LLAMA_CPP_SMOKE_TEST=1`.  Run with `castor test:controller`.

@see `tests/CodingAgent/Runtime/Controller/E2E/ControllerE2eTestCase.php`

Inherited helpers:
- `indexByType(array $events): array` — index events by type
- `foundAck(array $events, string $cmdId): bool` — check command.ack presence
- `assertStartRunAcked(array $events, string $cmdId): void` — assert start_run acknowledged
- `collectEvents(float $timeout): array` — read JSONL events from controller stdout until terminal state or timeout
- `collectEventsUntil(?string $targetType, float $timeout): array` — return as soon as the target runtime event appears
- `collectEventsUntilToolCompleted(string $toolName, float $timeout): array` — wait for the named tool's matching `tool_execution.completed` using `tool_call_id`
- `collectDiagnostics(array $events): string` — format diagnostic dump

Live `llm-real` controller tests that share llama-proxy cache normalization must use a **unique first user prompt** per scenario (e.g. `[llm-real:write-file] ...`) so stripped prologue keys do not collide and replay the wrong tool response. Use `liveLlmToolWaitTimeout()` on `ControllerE2eTestCase` for tool-completion waits; 5s is too short for live read/write on real workers.

Do not write inline `byType` loops or ack searches in test methods. For tool-focused LLM smoke tests, prefer `collectEventsUntilToolCompleted()` over waiting for `run.completed`; assert the intended `tool_name`, matching `tool_call_id`, and absence/presence of `tool_execution.failed` as appropriate.

### TUI E2E tests

Default TUI E2E uses the replay-backed journey pattern (`#[Group('tui-e2e-replay')]`).
Follow the pattern in `TuiJourneyE2eTest`: one long-lived tmux session exercising
multiple behaviours with replay fixtures for model interaction.  No live LLM
required — the test infrastructure is entirely deterministic.

Use `TmuxHarness`. Follow the pattern in `TuiJourneyE2eTest`:
- Detached tmux pane via `startDetached()`
- Isolated project dir with model/provider overrides
- `--prompt` for auto-submit, `sendLiteral`/`sendKey` for keyboard input
- `waitForCaptureContains` / `waitForCallback` for exact expectations
- Short waits for the local smoke model: typically 2-5s for startup/status/UI proof; avoid generic 30-60s waits
- Prefer explicit waits over fixed `usleep()`; only sleep when timing itself is the behavior under test
- `saveAnsiSnapshot()` for inspection artifacts

### Config fixtures

When constructing `AppConfig` with AI model data, prefer the minimal shape. If the exact config shape recurs across test files, consider a shared builder rather than copy-pasting 50-line arrays.

## What NOT to test

Do NOT write tests that only:
- Verify PHP intrinsics (enum `from()`/`value` round-trip, backed enum behavior)
- Verify getter/setter pairs trivially
- Verify class existence or method presence
- Exhaustively enumerate all enum cases in dedicated test cases

One representative test covering behavior is sufficient.

## Castor

All QA commands MUST go through Castor. Never run raw `vendor/bin/*` directly, except when explicitly isolating a Castor failure.

All PHPUnit invocations include `--stop-on-error --stop-on-failure --fail-on-all-issues --display-all-issues`.

Key commands:
- `castor test` — unit/integration tests (ParaTest parallel by default)
- `castor test --filter=XxxTest` — filter to specific tests
- `castor test --suite=coding-agent` — targeted ParaTest run on a suite
- `castor test:tui` — TUI E2E journey tests (`#[Group('tui-e2e-replay')]`, replay-backed, no live LLM)
- `castor test:llm-real` — real-LLM controller E2E tests (`#[Group('llm-real')]`). Run as focused opt-in validation when changes touch provider/LLM-visible code — NOT required for every normal task.
- `castor test:controller-replay` — controller replay E2E (default, no live LLM)
- `castor test:controller` — controller smoke test (live LLM, opt-in)
- `castor llm:fixtures:record` — re-record LLM replay fixtures from live LLM
- `castor llm:fixtures:info` — list available LLM replay fixtures
- `castor deptrac` — layer dependency validation
- `castor phpstan` — static analysis
- `castor cs-check` / `castor cs-fix` — code style
- `LLM_MODE=true castor check` — full quality gate (deterministic — no live LLM). Runs deptrac, unit/integration (ParaTest), controller replay E2E, TUI replay E2E, phpstan, and cs-check in parallel. No PHAR, no llama.cpp requirement.
- `castor cleanup` — remove all temp/test artifacts

## Snapshots and cleanup

- TUI E2E success snapshots are kept under `var/tmp/tui-e2e-*/` for inspection.
- Failure diagnostics go to `var/tmp/tui-failures/`.
- Run `castor cleanup` to remove all generated artifacts: TUI dirs, PHAR builds, caches, logs, test DB, OS temp test dirs.
- Do NOT add snapshot cleanup to `tearDown()`. Keep artifacts for inspection.

## Real LLM smoke-test prompts

The `llama_cpp_test/test` server should run deterministically (temperature 0, fixed seed) on port 9052. Tests should still be robust:
- Prompt with the exact tool name and exact relative path (`./file.txt`), not vague natural language.
- Keep model instructions short and schema-like: `Call the tool named read exactly once with path ./file.txt. After the tool succeeds, answer exactly done.`
- Assert runtime/tool events, not prose. The small model can phrase final text differently even when the tool path is correct.
- Use fast targeted waits. If a 5s target-tool wait fails on the local test model, debug the prompt/tool route or stale workers rather than increasing to 60s.

### LLM generation preflight

`test:llm-real` and `test:controller` run a ~4s curl-based preflight (`check_llm_generation_ready`) before any live-LLM E2E test starts. It verifies the test LLM can complete a tiny generation request. If the server responds to `/health` and `/v1/models` but generation is stuck (corrupted model load, stuck slots), Castor fails immediately with a diagnostic instead of burning step timeouts. Fix or restart the llama.cpp server before retrying. The default `castor check` is fully deterministic (replay-backed) and does NOT run this preflight.

## TUI behavior proof

TUI implementation is NOT complete without an automated test using the real interactive TUI (`TmuxHarness`), exercising the actual TUI flow. Default TUI E2E uses replay-backed fixtures for model interaction; live llama.cpp is not required for TUI feature proof. Mocked service-only tests are insufficient for TUI feature gate acceptance.

## Kernel-test base classes

- **`IsolatedKernelTestCase`** (`tests/CodingAgent/TestCase/`) — preferred for most DB/integration tests. Boots the kernel ONCE per class; DAMA provides per-method transaction rollback. Dramatically faster than per-method boot.
- **`PerMethodIsolatedKernelTestCase`** (`tests/CodingAgent/TestCase/`) — per-method kernel boot. Use ONLY when tests mutate the live container via `Container::set()` or when per-method filesystem artifacts must be visible to a freshly-booted kernel (e.g. template caching services). Most tests should use `IsolatedKernelTestCase` instead.

Both handle CWD isolation, env vars, exception handler balance, and directory cleanup so concrete tests don't duplicate lifecycle code.

## DB-touching tests

DB-touching tests must boot the Symfony kernel via `IsolatedKernelTestCase` (or `PerMethodIsolatedKernelTestCase` when necessary) and use the test container. Each test method gets a transaction rollback via DAMA DoctrineTestBundle, so no manual cleanup is needed.

### ParaTest DB isolation

`castor test` uses ParaTest by default, spawning worker processes that share the same SQLite test DB — DAMA/DoctrineTestBundle wraps each test in a transaction that is rolled back, so there is no cross-test data contamination. WAL journal mode handles concurrent read/write access safely. When ParaTest is unavailable or `--filter` is used, the suite falls back to deterministic sequential PHPUnit.

Each ParaTest worker gets a unique compiled Symfony cache directory (via `TEST_TOKEN` in `tests/paratest-bootstrap.php`) because the compiled container bakes env vars like `HATFIELD_TEST_DATABASE_PATH` into cached files.

- DB path is driven by `HATFIELD_TEST_DATABASE_PATH` env var (defaults to `app_test.sqlite`).
- For standalone `vendor/bin/phpunit` runs without Castor, export `HATFIELD_TEST_DATABASE_PATH=app_test.sqlite` to pick up the default DB.
- ParaTest paths: `HATFIELD_TEST_DATABASE_PATH=app_test.sqlite` (shared), `HATFIELD_CACHE_DIR=.hatfield/cache-paraT{token}` (per-worker).

## One test class per production class

## LLM Replay (deterministic, no live LLM)

Most tests that would otherwise hit a live LLM endpoint use pre-recorded
fixture files under `tests/AgentCore/Fixtures/traces/`.  Replay tests
exercise the full `LlmPlatformAdapter` path with fixture-driven deltas.

The replay infrastructure lives in `tests/AgentCore/Infrastructure/SymfonyAi/Replay/`:
- `FixtureReplayModelClient` — replaces HTTP transport with fixture data
- `FixtureReplayResultConverter` — converts fixture deltas to Symfony AI objects
- `StreamRecorderObserver` — recording path capturing live deltas to fixtures
- `ReplayTest` — automated replay tests (no live LLM)
- `ReplayRecordingTest` — recording from live LLM (opt-in)

Fixture format: `docs/llm-replay.md`.  Re-record with `castor llm:fixtures:record`.

Group test methods for a single production class in one test file. Avoid "many small test files for one class" patterns. Helper/test-double classes that serve multiple test files should live in shared `tests/*/Support/` directories.
