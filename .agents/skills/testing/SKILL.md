---
name: testing
description: "E2E and validation testing strategy. Load this skill when: writing or running tests, debugging test failures, touching TUI/runtime/Messenger code, running castor check, needing full command reference, or setting up DB-touching tests. Covers test groups, isolation, controller E2E, TUI E2E, real LLM smoke tests, failure diagnostics, and DB test setup."
---

# Testing Strategy

## Castor command reference

```bash
castor check                # Full validation: all 7 steps in parallel via pcntl_fork (sequential fallback); per-step timing + log files at var/reports/check-*.log
castor test                 # unit/integration only; excludes tui-e2e and llm-real; runs agent-core/coding-agent/tui/platform suites in parallel (each with isolated DB)
castor test --filter=X      # filter tests by name (sequential, single DB)
castor test:tui [--filter=X]    # tmux TUI e2e snapshots (filter optional)
castor test:tui-update [--filter=X]  # update TUI snapshot baselines (filter optional)
castor test:llm-real [--filter=X]   # real llama.cpp smoke (filter optional)
castor test:controller [--filter=X] # controller E2E smoke test (filter optional, defaults to ControllerSmokeTest)
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

`castor test` runs PHPUnit suites in parallel, each with its own SQLite DB:
- `HATFIELD_TEST_DATABASE_PATH` env var controls the DB filename (defaults to `app_test.sqlite`).
- Parallel workers get `HATFIELD_TEST_DATABASE_PATH=app_test-<suite>.sqlite`.
- `doctrine:migrations:migrate` runs once per worker on its isolated DB.
- `--cache-directory var/cache/.phpunit-<suite>` prevents PHPUnit cache collisions.
- Standalone `vendor/bin/phpunit` runs without Castor must export `HATFIELD_TEST_DATABASE_PATH=app_test.sqlite`.
- Filtered runs (`castor test --filter=...`) use a single shared DB sequentially.

## What each command tests

| Command | What it tests | Requires |
|---|---|---|
| `castor check` | Full validation: all 7 steps in parallel via pcntl_fork (sequential fallback); per-step timing + log files | tmux, llama.cpp on port 9052 |
| `castor test` | Unit/integration tests (runs 4 PHPUnit suites in parallel, each with isolated DB; sequential fallback) | Nothing (pure PHP) |
| `castor test:llm-real` | Real LLM smoke: `ControllerSmokeTest`, `LlamaCppSmokeTest` | llama.cpp on port 9052 |
| `castor test:controller` | Controller E2E: spawns `--controller`, JSONL protocol | llama.cpp on port 9052 |
| `castor test:tui` | Tmux TUI E2E snapshot tests | tmux, llama.cpp on port 9052 |
| `castor run:agent-test` | Interactive tmux session for manual inspection | tmux, llama.cpp on port 9052 |
| `castor run:agent` | Launch agent in tmux | tmux, LLM provider |

## Controller E2E testing

`ControllerSmokeTest` (`tests/CodingAgent/Runtime/Controller/E2E/`):

1. Creates isolated `var/tmp/test-{uuid}` with `.hatfield/settings.yaml`
2. Spawns `bin/console agent --controller` via proc_open
3. Waits for `runtime.ready` event on stdout
4. Sends `start_run` JSONL command on stdin with a deterministic prompt
5. Reads JSONL events from stdout, collecting them until terminal state
6. Asserts event sequence:
   - `runtime.ready` received
   - `command.ack` received for start_run
   - `run.started` received
   - `assistant.text_started` or `assistant.message_completed` received
   - `run.completed` or `run.failed` received (within 60s timeout)
7. Verifies session artifacts (`state.json`, `events.jsonl`)
8. On failure, dumps all collected events, session artifacts, and messenger DB

This exercises the full async runtime pipeline:
- Controller event loop (Revolt `EventLoop::onReadable`/`repeat`/`onSignal`)
- Messenger consumer processes (run_control, llm, tool)
- LLM consumer stdout streaming of transient deltas
- Event drain and publish transport polling

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

## TUI E2E snapshot artifacts

After `castor test:tui`, passing test snapshots are kept at `var/tmp/tui-e2e-*/` for inspection. Each isolated test directory contains:
- `.hatfield/tmp/tui/smoke/*.ansi` — ANSI terminal snapshots captured by `saveAnsiSnapshot()`
- `.hatfield/sessions/<id>/events.jsonl` — canonical event log for resumed sessions

After failures, diagnostics go to `var/tmp/tui-failures/` (ANSI snapshots + plain text dumps).

Run `castor cleanup` to remove all temp/test artifacts. See `tests/AGENTS.md` for full test standards: shared helpers, directory isolation, what not to test, one-class-per-file rules.

## DB-touching tests

If a test touches the database, it is an integration test, not a unit test. Use `KernelTestCase` + `static::getContainer()` for EntityManager/repository/services. Do not use standalone `ORMSetup`/`DriverManager`/`SchemaTool`/`EntityManager` factories in tests. Test DB is configured via `config/packages/test/doctrine.yaml`; DAMA/DoctrineTestBundle wraps each test in a transaction for rollback isolation. Schema is created once before the suite runs, not per test. Load test data via container EntityManager or fixtures, not manual in-memory SQLite factories.
