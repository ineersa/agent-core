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

Extend `ControllerE2eTestCase` for headless controller E2E.

@see `tests/CodingAgent/Runtime/Controller/E2E/ControllerE2eTestCase.php`

Inherited helpers:
- `indexByType(array $events): array` — index events by type
- `foundAck(array $events, string $cmdId): bool` — check command.ack presence
- `assertStartRunAcked(array $events, string $cmdId): void` — assert start_run acknowledged
- `collectEvents(float $timeout): array` — read JSONL events from controller stdout until terminal state or timeout
- `collectEventsUntil(?string $targetType, float $timeout): array` — return as soon as the target runtime event appears
- `collectEventsUntilToolCompleted(string $toolName, float $timeout): array` — wait for the named tool's matching `tool_execution.completed` using `tool_call_id`
- `collectDiagnostics(array $events): string` — format diagnostic dump

Do not write inline `byType` loops or ack searches in test methods. For tool-focused LLM smoke tests, prefer `collectEventsUntilToolCompleted()` over waiting for `run.completed`; assert the intended `tool_name`, matching `tool_call_id`, and absence/presence of `tool_execution.failed` as appropriate.

### TUI E2E tests

Use `TmuxHarness` with `#[Group('tui-e2e')]`. Follow the pattern in `TuiAgentSmokeTest`:
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

Key commands:
- `castor test` — full unit/integration suite (runs 7 PHPUnit workers in parallel: `agent-core`, `coding-agent-1..4`, `tui`, `platform`; each has its own isolated SQLite DB/cache/JUnit/log files; sequential fallback when proc_open unavailable)
- `castor test --filter=XxxTest` — filter to specific tests (sequential; single DB)
- `castor test:tui` — TUI E2E tests (`#[Group('tui-e2e')]`)
- `castor test:llm-real` — real-LLM controller E2E tests (`#[Group('llm-real')]`)
- `castor test:controller` — controller smoke test
- `castor deptrac` — layer dependency validation
- `castor phpstan` — static analysis
- `castor cs-check` / `castor cs-fix` — code style
- `LLM_MODE=true castor check` — full quality gate. It runs PHAR ensure, then 13 first-class parallel steps: deptrac, 7 unit shards, controller E2E, llm-real E2E, TUI E2E, phpstan, and cs-check. Unit shards are direct check steps, not a nested `castor test` subprocess.
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

## TUI behavior proof

TUI implementation is NOT complete without an automated test using the real test LLM and `TmuxHarness`, exercising the actual interactive TUI flow. Mocked service-only tests are insufficient for TUI feature gate acceptance.

## DB-touching tests

DB-touching tests must boot the Symfony kernel via `IsolatedKernelTestCase` (or equivalent) and use the test container. Each test method gets a transaction rollback via DAMA DoctrineTestBundle, so no manual cleanup is needed.

### Parallel suite DB isolation

`castor test` and the unit-test shard steps inside `castor check` run PHPUnit workers in parallel, each with its own SQLite DB file to prevent contention:
- DB path is driven by `HATFIELD_TEST_DATABASE_PATH` env var (defaults to `app_test.sqlite`).
- Castor sets `HATFIELD_TEST_DATABASE_PATH=app_test-<worker>.sqlite` per worker.
- Castor sets unique `HATFIELD_CACHE_DIR`, PHPUnit cache directory, JUnit XML, and log file paths per worker.
- Castor runs `doctrine:migrations:migrate` once per worker on its own DB before PHPUnit.
- For standalone `vendor/bin/phpunit` runs without Castor, export `HATFIELD_TEST_DATABASE_PATH=app_test.sqlite` to pick up the default DB.
- Filtered runs (`castor test --filter=...`) use a single sequential PHPUnit invocation with the default DB.

## One test class per production class

Group test methods for a single production class in one test file. Avoid "many small test files for one class" patterns. Helper/test-double classes that serve multiple test files should live in shared `tests/*/Support/` directories.
