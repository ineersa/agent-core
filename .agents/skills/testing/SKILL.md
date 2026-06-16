---
name: testing
description: "E2E and validation testing strategy. Load this skill when: writing or running tests, debugging test failures, touching TUI/runtime/Messenger code, running castor check, needing full command reference, or setting up DB-touching tests. Covers test groups, isolation, controller E2E, TUI E2E, real LLM smoke tests, failure diagnostics, and DB test setup."
---

# Testing Strategy

## Castor command reference

All PHPUnit invocations include `--stop-on-error --stop-on-failure --fail-on-all-issues --display-all-issues`.

```bash
castor check                # Full validation: PHAR ensure + parallel steps (deptrac, unit/integration sequential, controller E2E, llm-real E2E, TUI E2E, phpstan, cs-check); per-step timeouts + logs at var/reports/check-*.log
castor test                 # unit/integration tests (ParaTest parallel by default); excludes tui-e2e and llm-real
castor test --filter=X      # filter tests by name (sequential, single DB)
castor test:tui [--filter=X]    # tmux TUI e2e snapshots (filter optional)
castor test:tui-update [--filter=X]  # update TUI snapshot baselines (filter optional)
castor test:llm-real [--filter=X]   # real llama.cpp smoke (filter optional)
castor test:controller [--filter=X] # controller E2E smoke test (live LLM, opt-in)
castor test:controller-replay      # controller E2E smoke tests with replay fixtures (no live LLM, default controller validation)
castor llm:fixtures:record         # Re-record LLM replay fixtures from live LLM
castor llm:fixtures:info           # List available LLM replay fixtures
castor deptrac              # architecture boundary validation
castor phpstan [path]       # static analysis (optionally scoped to a path)
castor phpstan:baseline     # regenerate phpstan baseline
castor cs-fix [path]        # auto-fix coding style
castor cs-check             # check coding style (dry-run)
castor phar:build           # Build hatfield.phar (worktree-local by default)
castor phar:ensure           # Ensure PHAR exists (build if missing or stale)
castor phar:clean            # Remove worktree-local hatfield.phar
```

## Test LLM

All E2E tests use `llama_cpp_test/test` (port 9052). This is a fast local model for deterministic smoke testing. Never use production LLM providers in E2E tests.

Run the test llama.cpp server deterministically for smoke tests: temperature 0, fixed seed, and the `test` alias on port 9052. The smoke model is expected to answer/tool-call within a few seconds; long 30-60s waits usually hide a bad prompt, stale worker, or stuck process rather than real model latency.

### LLM generation readiness preflight

Before `castor check`, `test:tui`, `test:llm-real`, `test:controller`, and `test:tui-update` run any E2E tests, Castor runs `check_llm_generation_ready()` — a ~4s curl-based preflight that sends a tiny `max_tokens=1` chat completion to `llama_cpp_test/test`. If the server responds to `/health` and `/v1/models` but generation hangs (corrupted model load, stuck slots), this preflight fails immediately with a clear diagnostic instead of burning 30-90s Castor step timeouts.

If you see:
```
llama.cpp generation readiness check FAILED
  Endpoint: http://192.168.2.38:9052/v1/chat/completions
  Model: test
  HTTP status: 0 (curl exit: 28)
```
Restart or fix the llama.cpp server. Health-only checks are insufficient.

### HTTP timeout fallback

`SymfonyAiProviderFactory` injects a default 30s `HttpClient` timeout for all LLM requests when no explicit timeout is configured, preventing infinite hangs. The test environment (`config/services_test.yaml`) overrides this to 5s. The `HATFIELD_LLM_HTTP_TIMEOUT` env var allows per-environment override.

## LLM Replay (deterministic, no live LLM)

Most tests that would otherwise hit a live LLM endpoint use instead
pre-recorded fixture files under `tests/AgentCore/Fixtures/traces/`.

- **Replay mode** is the default for `castor test`. No live LLM calls.
- **Live mode** is opt-in: `castor test:llm-real`, `castor test:tui`,
  `castor test:controller`, and `castor check` still use live LLM.
  MAINT-05D/E will port controller/TUI E2E to replay-backed journeys.
- **Re-record fixtures** when provider behavior, prompts, or tool schemas
  change: `castor llm:fixtures:record`.
- Fixture format and recording/replay architecture described in
  `docs/llm-replay.md`.  Replay test helpers live in
  `tests/AgentCore/Infrastructure/SymfonyAi/Replay/`.

## Test groups

- `#[Group('llm-real')]` — all tests that hit a real LLM endpoint
- `#[Group('tui-e2e')]` — TUI tmux snapshot tests
- `#[Group('phar')]` — PHAR smoke tests (PharSmokeTest)

## PHAR-based testing

Controller/TUI subprocess tests run against the built PHAR, not the source
tree. Castor test tasks (`test:tui`, `test:llm-real`, `test:controller`)
automatically call `phar:ensure` first and set `HATFIELD_BINARY_PATH` so
`AgentTestExecutable` resolves the PHAR path. If PHAR build fails, these
test tasks skip gracefully (PHAR ensure failure is non-fatal).

Pure unit/integration tests (`castor test`) remain source-based and do not
require PHAR. PHAR smoke tests (`#[Group('phar')]`) validate the built
artifact boots and responds to basic commands.

Run PHAR smoke tests manually:
```bash
castor phar:build
HATFIELD_BINARY_PATH=var/tmp/phar/hatfield.phar vendor/bin/phpunit --group phar
```

## Isolation

All E2E tests must use `var/tmp/test-{uuid}` isolation. They must NOT read or write to the real `.hatfield/sessions/` directory. On failure, tests dump session artifacts to stderr.

### Per-suite DB isolation

`castor test` runs unit/integration tests with ParaTest by default (parallel
workers share the SQLite test DB safely via DAMA/DoctrineTestBundle
transaction isolation in WAL mode).  Each ParaTest worker gets its own
compiled Symfony cache directory (via `TEST_TOKEN` in
`tests/paratest-bootstrap.php`).  Filtered runs and non-ParaTest fallback
use a single shared DB sequentially.

`castor check` uses the deterministic sequential PHPUnit helper for the
unit/integration lane.

- DB path: `HATFIELD_TEST_DATABASE_PATH` (defaults to `app_test.sqlite`).
- ParaTest cache dir: `HATFIELD_CACHE_DIR=.hatfield/cache-paraT{token}` (per-worker).
- `doctrine:migrations:migrate` runs once before the suite.
- Standalone `vendor/bin/phpunit` runs without Castor must export `HATFIELD_TEST_DATABASE_PATH=app_test.sqlite`.
- Filtered runs (`castor test --filter=...`) use a single shared DB sequentially.

## What each command tests

| Command | What it tests | Requires |
|---|---|---|
| `castor check` | Full validation: PHAR ensure plus parallel steps: deptrac, unit/integration (sequential), controller E2E, llm-real E2E, TUI E2E, phpstan, cs-check. The unit/integration step is a single deterministic sequential PHPUnit run. | tmux, llama.cpp on port 9052 |
| `castor test` | Unit/integration tests (ParaTest parallel by default, sequential fallback for --filter) | Nothing (pure PHP) |
| `castor test:llm-real` | Real LLM smoke: `ControllerSmokeTest`, `LlamaCppSmokeTest` | llama.cpp on port 9052 |
| `castor test:controller-replay` | Controller replay E2E: spawns `--controller`, JSONL protocol, replay fixtures (no live LLM) | Nothing (pure PHP) |
| `castor test:controller` | Controller E2E: spawns `--controller`, JSONL protocol (live LLM, opt-in) | llama.cpp on port 9052 |
| `castor test:tui` | Tmux TUI E2E snapshot tests | tmux, llama.cpp on port 9052 |
| `castor run:agent-test` | Interactive tmux session for manual inspection | tmux, llama.cpp on port 9052 |
| `castor run:agent` | Launch agent in tmux | tmux, LLM provider |
| `castor llm:fixtures:record` | Re-record replay fixtures from live LLM | llama.cpp on port 9052 |
| `castor llm:fixtures:info` | List available replay fixtures and metadata | Nothing (pure PHP) |

## Controller E2E testing

### Controller replay E2E (default, deterministic)

`ControllerReplaySmokeTest` (`tests/CodingAgent/Runtime/Controller/E2E/`):

Run with `castor test:controller-replay`. Does NOT require live LLM.

Extends `ControllerReplayE2eTestCase`, which:
- Spawns `bin/console agent --controller` with `APP_ENV=test` + `HATFIELD_LLM_REPLAY_FIXTURE_PATH`
- `config/services_test.yaml` wires `HttpClientInterface` through
  `ControllerReplayHttpClientFactory` (tests/).  When the env var is
  set, the factory returns a MockHttpClient with fixture-driven SSE.
  No production code in `src/` checks the replay env var.
- Uses pre-recorded fixture files (committed to repo) for deterministic responses
- Tracks process group PIDs and terminates the entire tree on teardown
- Does NOT require `LLAMA_CPP_SMOKE_TEST`, `HATFIELD_BINARY_PATH`, or any live AI provider
- Always uses the source `bin/console` (not PHAR) so test-DI autoload works

Fixture format: same as `docs/llm-replay.md`.
Fixtures live in `tests/CodingAgent/Runtime/Controller/E2E/fixtures/`.

Process ownership:
- Controller + Messenger consumers tracked via /proc PID scanning
- Teardown: SIGTERM → 3s grace → SIGKILL for all tracked PIDs
- Diagnostics on failure: tracked PIDs, fixture count, process state

### Controller live E2E (opt-in)

`ControllerSmokeTest` (`tests/CodingAgent/Runtime/Controller/E2E/`):

1. Creates isolated `var/tmp/test-{uuid}` with `.hatfield/settings.yaml`
2. Spawns `bin/console agent --controller` via proc_open
3. Waits for `runtime.ready` event on stdout
4. Sends `start_run` JSONL command on stdin with a deterministic prompt
5. Reads JSONL events from stdout, collecting until the event that proves the behavior under test.
6. Asserts event sequence/proof:
   - `runtime.ready` received
   - `command.ack` received for start_run
   - `run.started` received
   - for conversational smoke, assistant text/message events and terminal run state
   - for tool smoke, the intended `tool_execution.started` + matching `tool_execution.completed` by `tool_call_id`
7. Verifies session artifacts (`state.json`, `events.jsonl`) when relevant
8. On failure, dumps all collected events, session artifacts, and messenger DB

This exercises the full async runtime pipeline:
- Controller event loop (Revolt `EventLoop::onReadable`/`repeat`/`onSignal`)
- Messenger consumer processes (run_control, llm, tool)
- LLM consumer stdout streaming of transient deltas
- Event drain and publish transport polling

### Controller E2E wait strategy

Use the narrowest event proof instead of waiting for the whole run when the feature does not require it:
- `collectEventsUntil($eventType, 5.0)` for a specific runtime event.
- `collectEventsUntilToolCompleted($toolName, 5.0)` for tool tests; it tracks `tool_call_id` from `tool_execution.started` to the matching `tool_execution.completed`.
- Do not hard-require `run.completed` for tests whose real assertion is tool execution. The post-tool assistant turn can be slower or more variable than the tool path itself.
- Prompts in `llm-real` tests must name the exact tool and exact relative path, e.g. `Call the tool named read exactly once with path ./file.txt`. Avoid vague natural-language prompts that let the small model pick a different tool or shorten paths.

## Failure diagnostics

On E2E test failure, the test dumps:
- All collected JSONL events (with types and count)
- Session artifacts: `state.json`, `events.jsonl`
- Messenger DB (`messenger.sqlite`) with pending message counts per queue
- Controller stderr output

## Required runtime/TUI validation

For changes touching TUI runtime behavior, `AgentSessionClient`, model routing, Messenger wiring, `TranscriptProjector`, `RuntimeEventPoller`, transcript rendering, or LLM-visible execution flow, unit/container/mocked tests are not enough.

You MUST run `castor check`. It includes controller E2E, real LLM E2E, and TUI E2E, so runtime/TUI/error-propagation changes exercise the controller process, real model path, and interactive user-visible TUI path before handoff.

For especially risky visual or interaction changes, also run `castor run:agent-test` to drive the agent in tmux and capture snapshots.

Validation must exercise the real user flow: start agent, type prompt, submit, wait for visible assistant response or visible error block, and capture TUI snapshot plus session artifacts on failure. Do not claim runtime/TUI work is done based only on DTO tests, mocked pollers, container compilation, or isolated service tests.

If prerequisites are unavailable (tmux not installed, llama.cpp not reachable on port 9052), the task MUST remain IN-PROGRESS with exact environmental blocker output — never mark CODE-REVIEW or DONE without it.

Before re-running failed controller/TUI E2E checks, kill stale worker processes from the failed worktree (`messenger:consume`, `agent --controller`, PHPUnit/Castor children). Orphaned consumers can keep queues busy and make a fixed test appear hung.

## TUI E2E snapshot artifacts

After `castor test:tui`, passing test snapshots are kept at `var/tmp/tui-e2e-*/` for inspection. Each isolated test directory contains:
- `.hatfield/tmp/tui/smoke/*.ansi` — ANSI terminal snapshots captured by `saveAnsiSnapshot()`
- `.hatfield/sessions/<id>/events.jsonl` — canonical event log for resumed sessions

After failures, diagnostics go to `var/tmp/tui-failures/` (ANSI snapshots + plain text dumps).

Run `castor cleanup` to remove all temp/test artifacts. See `tests/AGENTS.md` for full test standards: shared helpers, directory isolation, fast E2E waits, what not to test, one-class-per-file rules.

TUI E2E waits should target exact visible proof with short caps (typically 2-5s for startup/status/UI assertions on the local test model). Avoid broad 30-60s waits and fixed `usleep()` calls unless the delay itself is the behavior under test.

## DB-touching tests

If a test touches the database, it is an integration test, not a unit test. Use `KernelTestCase` + `static::getContainer()` for EntityManager/repository/services. Do not use standalone `ORMSetup`/`DriverManager`/`SchemaTool`/`EntityManager` factories in tests. Test DB is configured via `config/packages/test/doctrine.yaml`; DAMA/DoctrineTestBundle wraps each test in a transaction for rollback isolation. Schema is created once before the suite runs, not per test. Load test data via container EntityManager or fixtures, not manual in-memory SQLite factories.
