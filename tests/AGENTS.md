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
- `collectEvents(float $timeout): array` — read JSONL events from controller stdout
- `collectDiagnostics(array $events): string` — format diagnostic dump

Do not write inline `byType` loops or ack searches in test methods.

### TUI E2E tests

Use `TmuxHarness` with `#[Group('tui-e2e')]`. Follow the pattern in `TuiAgentSmokeTest`:
- Detached tmux pane via `startDetached()`
- Isolated project dir with model/provider overrides
- `--prompt` for auto-submit, `sendLiteral`/`sendKey` for keyboard input
- `waitForCaptureContains` / `waitForCallback` for expectations
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

All QA commands MUST go through Castor. Never run raw `vendor/bin/*` directly.

Key commands:
- `castor test` — full test suite (runs `agent-core`, `coding-agent`, `tui`, `platform` PHPUnit suites in parallel via pcntl_fork, each with its own isolated SQLite DB; sequential fallback when pcntl_fork unavailable; per-suite reports logged to `var/reports/phpunit-<suite>.*`)
- `castor test --filter=XxxTest` — filter to specific tests (sequential; single DB)
- `castor test:tui` — TUI E2E tests (`#[Group('tui-e2e')]`)
- `castor test:llm-real` — real-LLM controller E2E tests (`#[Group('llm-real')]`)
- `castor test:controller` — controller smoke test
- `castor deptrac` — layer dependency validation
- `castor phpstan` — static analysis
- `castor cs-check` / `castor cs-fix` — code style
- `LLM_MODE=true castor check` — full quality gate (runs all 7 steps in parallel via pcntl_fork with per-step timing; each `castor test` suite runs its own parallel PHPUnit suites internally; sequential fallback when pcntl_fork unavailable)
- `castor cleanup` — remove all temp/test artifacts

## Snapshots and cleanup

- TUI E2E success snapshots are kept under `var/tmp/tui-e2e-*/` for inspection.
- Failure diagnostics go to `var/tmp/tui-failures/`.
- Run `castor cleanup` to remove all generated artifacts: TUI dirs, PHAR builds, caches, logs, test DB, OS temp test dirs.
- Do NOT add snapshot cleanup to `tearDown()`. Keep artifacts for inspection.

## TUI behavior proof

TUI implementation is NOT complete without an automated test using the real test LLM and `TmuxHarness`, exercising the actual interactive TUI flow. Mocked service-only tests are insufficient for TUI feature gate acceptance.

## DB-touching tests

DB-touching tests must boot the Symfony kernel via `IsolatedKernelTestCase` (or equivalent) and use the test container. Each test method gets a transaction rollback via DAMA DoctrineTestBundle, so no manual cleanup is needed.

### Parallel suite DB isolation

`castor test` runs PHPUnit suites in parallel, each with its own SQLite DB file to prevent contention:
- DB path is driven by `HATFIELD_TEST_DATABASE_PATH` env var (defaults to `app_test.sqlite`).
- Castor sets `HATFIELD_TEST_DATABASE_PATH=app_test-<suite>.sqlite` per suite worker.
- Castor runs `doctrine:migrations:migrate` once per suite on its own DB before PHPUnit.
- For standalone `vendor/bin/phpunit` runs without Castor, export `HATFIELD_TEST_DATABASE_PATH=app_test.sqlite` to pick up the default DB.
- Filtered runs (`castor test --filter=...`) use a single sequential PHPUnit invocation with the default DB.

## One test class per production class

Group test methods for a single production class in one test file. Avoid "many small test files for one class" patterns. Helper/test-double classes that serve multiple test files should live in shared `tests/*/Support/` directories.
