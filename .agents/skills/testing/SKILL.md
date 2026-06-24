---
name: testing
description: "E2E and validation testing strategy. Load this skill when: writing or running tests, debugging test failures, touching TUI/runtime/Messenger code, running castor check, needing full command reference, or setting up DB-touching tests. Covers test groups, isolation, controller E2E, TUI E2E, real LLM smoke tests, failure diagnostics, and DB test setup."
---

# Testing Strategy

## Castor command reference

All PHPUnit invocations include `--stop-on-error --stop-on-failure --fail-on-all-issues --display-all-issues`.

```bash
castor check                # Full QA gate: deptrac, unit/integration (ParaTest), controller replay E2E, TUI replay E2E, live llm-real smoke (ParaTest, port 9052 / llama-proxy), phpstan, cs-check; lanes parallel; logs under per-run `var/reports/qa-<id>/check-*.log`. Deterministic mode: Symfony Lock across sibling worktrees (60s acquire timeout, `HATFIELD_CASTOR_CHECK_LOCK_TIMEOUT`), cache-growth guard, post-run `HATFIELD_QA_RUN_ID` leak assertion (no auto-kill), lane log integrity. Stress overrides (`HATFIELD_CASTOR_CHECK_LOCK=0`, `HATFIELD_LLM_CACHE_GUARD=0`, concurrency envs) are investigation-only — not CODE-REVIEW evidence. Worker budgets: unit=4 (max 8, `HATFIELD_CHECK_UNIT_PARATEST_PROCESSES`), TUI=2 (max 4, `HATFIELD_CHECK_TUI_PARATEST_PROCESSES` / legacy `HATFIELD_TUI_PARATEST_PROCESSES`), llm-real=2 (max 4, `HATFIELD_CHECK_LLM_REAL_PARATEST_PROCESSES`); controller-replay sequential. Warm proxy before gate: `castor test:llm-real`.
castor test                 # unit/integration tests (ParaTest parallel by default); excludes tui-e2e-replay, llm-real, recording, and controller-replay groups
castor test --filter=X      # filter tests by name
castor test --suite=X       # target a specific phpunit.xml test suite (ParaTest parallel)
castor test:tui [--filter=X]    # TUI E2E journey tests (replay-backed, no live LLM); full group uses ParaTest (default 2 workers; under `castor check` uses `HATFIELD_CHECK_TUI_PARATEST_PROCESSES`, legacy `HATFIELD_TUI_PARATEST_PROCESSES` still honored, max 4); --filter stays sequential PHPUnit
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

Live LLM smoke tests (opt-in) use `llama_cpp_test/test` (port 9052). This is a fast local model for deterministic provider compatibility testing. Never use production LLM providers in E2E tests. Default E2E tests (controller replay, TUI replay) use deterministic pre-recorded fixtures and do NOT require llama.cpp.

Run the test llama.cpp server deterministically for smoke tests: temperature 0, fixed seed, and the `test` alias on port 9052. The smoke model is expected to answer/tool-call within a few seconds; long 30-60s waits usually hide a bad prompt, stale worker, or stuck process rather than real model latency.

### LLM generation readiness preflight

Before `castor test:llm-real` and `castor test:controller` run live-LLM tests,
Castor runs `check_llm_generation_ready()` — a ~4s curl-based preflight that
sends a small chat completion (`max_tokens=512` in `.castor/helpers.php` to avoid truncating reasoning models on the test server) to `llama_cpp_test/test`. If the
server responds to `/health` and `/v1/models` but generation hangs (corrupted
model load, stuck slots), this preflight fails immediately with a clear
diagnostic instead of burning 30-90s Castor step timeouts.

Back-to-back `test:llm-real` invocations skip the expensive curl when `var/tmp/llm-generation-ready.cache` is fresh (default TTL 120s; override with `HATFIELD_LLM_READY_TTL`). Force a recheck by deleting that file.

`castor check` runs this preflight once before parallel lanes, then includes the live `test:llm-real` lane (requires llama.cpp/llama-proxy on port 9052; warm proxy cache keeps the lane ~22–25s).

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


## Llama-proxy runbook (port 9052)

Repository: `/home/ineersa/projects/llama-proxy` (separate from agent-core). Tests keep using the normal OpenAI-compatible base URL on **9052**; when the proxy is installed, that port is the proxy, not a bare llama.cpp listener.

### What it does

- **Cache miss:** POST is forwarded to `LLAMA_PROXY_UPSTREAM` (e.g. `:8052`), response recorded under `LLAMA_PROXY_CACHE_DIR`.
- **Cache hit:** Response replayed from disk (streaming chunks preserved); header `x-llama-proxy-cache: hit` on replayed responses.
- **Key normalization:** `LLAMA_PROXY_CACHE_NORMALIZE_MESSAGES=true` (default) strips leading system/developer and `[user-context]` user messages from the **cache key only**. Tail messages (including the real first user prompt per test) must differ per scenario — live tests use tags like `[llm-real:write-file]`.

Do **not** document or enable app-side deterministic prompt stripping; proxy-side normalization is the supported approach.

### Admin endpoints (evidence: `llama_proxy/app.py`, llama-proxy README)

```bash
curl http://127.0.0.1:9052/__llama_proxy/health
curl http://127.0.0.1:9052/__llama_proxy/cache/stats
curl -X POST http://127.0.0.1:9052/__llama_proxy/cache/clear
curl -X DELETE http://127.0.0.1:9052/__llama_proxy/cache
```

If `LLAMA_PROXY_ADMIN_TOKEN` is set, pass `-H 'X-Llama-Proxy-Token: <token>'` on **stats** and **clear** (health is unauthenticated).

### `castor check` llama-proxy cache guard

- Before lanes (and before generation preflight), `castor check` snapshots proxy cache `entries` via `/__llama_proxy/cache/stats`.
- After all lanes pass, it fails if `entries` increased — meaning uncached live LLM traffic entered the deterministic gate.
- **Warmup workflow:** run `castor test:llm-real` intentionally, verify stats stabilize, then `castor check`. Clearing proxy cache requires warmup again.
- Stress-only: `HATFIELD_LLM_CACHE_GUARD=0`. Not applied to focused `castor test:llm-real`.

### `castor check` live lane

- Runs `check_llm_generation_ready()` once (curl to `…/v1/chat/completions`, model `test`; see `.castor/helpers.php`).
- Parallel lane **`test:llm-real`**: same shell command as `castor test:llm-real` — `build_test_llm_real_phpunit_command(null)` → ParaTest `--group=llm-real --processes=4`, 180s step timeout. Log: `var/reports/check-test-llm-real.log`.
- Unit/integration ParaTest lane in check **excludes** `llm-real` (see `build_check_paratest_command()`).

Filtered `castor test:llm-real --filter=…` uses sequential PHPUnit (no `--processes=4`).

### Reset vs warm vs regenerate

| Goal | Action |
| --- | --- |
| See proxy config | `curl …/__llama_proxy/health` (`cache_normalize_messages`, `cache_dir`, `upstream`) |
| See cassette count/size | `curl …/__llama_proxy/cache/stats` |
| Drop all proxy cassettes | `POST …/cache/clear` or `DELETE …/cache` |
| Re-record cassettes | Run live tests after clear (`castor test:llm-real` or check lane) |
| Speed up repeat runs | Leave cache warm; second full `test:llm-real` ~20–30s typical |
| Force Castor preflight | `rm -f var/tmp/llm-generation-ready.cache` or `HATFIELD_LLM_READY_TTL=0` |
| Skip expensive preflight briefly | Default TTL 120s on `var/tmp/llm-generation-ready.cache` |

### Proxy cache vs committed LLM replay fixtures

| | llama-proxy | `HATFIELD_LLM_REPLAY_FIXTURE_PATH` |
| --- | --- | --- |
| Layer | HTTP on 9052 | Test `MockHttpClient` / `FixtureReplayModelClient` |
| Commands | `test:llm-real`, check live lane, `test:controller`, `llm:fixtures:record` | `castor test`, `test:controller-replay`, `test:tui` |
| Offline CI | Needs 9052 + model upstream for live lane | Replay lanes need no model |

Replay infrastructure is **not** removed when using the proxy; both coexist.

### Leaked workers after Castor / E2E runs

`castor check` does **not** auto-kill workers. If `messenger:consume`, `agent --controller`, PHPUnit, or Castor children remain after a gate finishes, treat that as a **lifecycle/teardown bug** — investigate and fix the root cause (cancel/teardown path, subprocess shutdown, test harness cleanup) instead of killing processes as routine workflow before retrying.

- **Diagnostics only:** `castor clean:cleanup:workers:list` (dry-run candidates in this checkout).
- **Last resort only:** `castor clean:cleanup:workers` after you have recorded the leak (PIDs, command lines, which test/lane) and started root-cause work. Never signal root-owned workers or processes with `HATFIELD_SESSION_ID` in `/proc/<pid>/environ` (active Hatfield session workers).
- Prefer validating task branches in an isolated task worktree when possible.


## LLM Replay (deterministic, no live LLM)

Most tests that would otherwise hit a live LLM endpoint use instead
pre-recorded fixture files under `tests/AgentCore/Fixtures/traces/`.

- **Replay mode** is the default for `castor test`. No live LLM calls.
- **Live mode** is opt-in: `castor test:llm-real`,
  `castor test:controller`, and `castor llm:fixtures:record`.
- **Re-record fixtures** when provider behavior, prompts, or tool schemas
  change: `castor llm:fixtures:record`.
- Fixture format and recording/replay architecture described in
  `docs/llm-replay.md`.  Replay test helpers live in
  `tests/AgentCore/Infrastructure/SymfonyAi/Replay/`.

## Test groups

- `#[Group('llm-real')]` — all tests that hit a real LLM endpoint
- `#[Group('tui-e2e-replay')]` — TUI journey tests (replay-backed, default and only TUI group)
- `#[Group('phar')]` — PHAR smoke tests (PharSmokeTest)

## PHAR-based testing

Live controller E2E (`test:llm-real`, `test:controller`) spawn **source**
`bin/console` with `APP_ENV=test` via `AgentTestExecutable::sourceConsoleCommand()`
so `config/services_test.yaml` applies. They do **not** use the PHAR (dev-only
bundles such as DAMA are excluded from the PHAR). `castor test:controller` may
still call `phar:ensure` for other paths; `test:llm-real` skips PHAR ensure.

Controller replay tests (`test:controller-replay`) and all TUI E2E tests
(`test:tui`, `test:tui-update`) use source `bin/console` and do not
require PHAR.

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

`castor check` uses ParaTest for the unit/integration lane (excludes
E2E, live-LLM, recording, and PHAR groups).

- DB path: `HATFIELD_TEST_DATABASE_PATH` (defaults to `app_test.sqlite`).
- ParaTest cache dir: `HATFIELD_CACHE_DIR=.hatfield/cache-paraT{token}` (per-worker).
- `doctrine:migrations:migrate` runs once before the suite.
- Standalone `vendor/bin/phpunit` runs without Castor must export `HATFIELD_TEST_DATABASE_PATH=app_test.sqlite`.
- Filtered runs (`castor test --filter=...`) use sequential PHPUnit (shared single DB).

## What each command tests

| Command | What it tests | Requires |
|---|---|---|
| `castor check` | Full QA gate: deptrac, unit/integration (ParaTest), controller replay E2E, TUI replay E2E, live llm-real (ParaTest, port 9052), phpstan, cs-check. No PHAR. | tmux, llama.cpp/proxy on 9052 |
| `castor test` | Unit/integration tests (ParaTest parallel by default) | Nothing (pure PHP) |
| `castor test:llm-real` | Real LLM smoke: `ControllerSmokeTest`, `LlamaCppSmokeTest` (excludes `recording` group). Run as focused opt-in validation when changes touch provider/LLM-visible code — NOT required for every normal task. | llama.cpp on port 9052 |
| `castor test:controller-replay` | Controller replay E2E: spawns `--controller`, JSONL protocol, replay fixtures (no live LLM) | Nothing (pure PHP) |
| `castor test:controller` | Controller E2E: spawns `--controller`, JSONL protocol (live LLM, opt-in) | llama.cpp on port 9052 |
| `castor test:tui` | TUI E2E journey tests (replay-backed, no live LLM) | tmux |
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

You MUST run `castor check`. It includes controller replay E2E, TUI replay E2E, and the live `llm-real` lane, so runtime/TUI/error-propagation and provider smoke are exercised before handoff. Additional live controller validation remains opt-in via `castor test:controller`.

For especially risky visual or interaction changes, also run `castor run:agent-test` to drive the agent in tmux and capture snapshots.

Validation must exercise the real user flow: start agent, type prompt, submit, wait for visible assistant response or visible error block, and capture TUI snapshot plus session artifacts on failure. Do not claim runtime/TUI work is done based only on DTO tests, mocked pollers, container compilation, or isolated service tests.

If tmux is unavailable, TUI tasks MUST remain IN-PROGRESS with exact environmental blocker output — never mark CODE-REVIEW or DONE without it. `castor check` also requires llama.cpp/llama-proxy on port 9052 for the `llm-real` lane.

### Focused live LLM provider validation

`castor check` already runs the full `llm-real` group. Run `castor test:llm-real` alone for focused/filtered live validation when changes touch:
- Symfony AI provider/factory/platform integration
- LLM provider config, model catalog/resolution/routing/selection
- Tool schemas, tool-call conversion, or tool argument prompts
- LLM-visible system/developer prompts or prompt templates
- Live provider compatibility, streaming conversion, stop_reason/usage/tool-call deltas
- Controller live-provider path behavior where replay cannot prove provider compatibility

`castor test:controller` remains opt-in for live controller E2E when appropriate. Do NOT require live LLM validation for every normal task — only for provider/LLM-visible changes.

Before re-running failed controller/TUI E2E checks, use `castor clean:cleanup:workers:list` to diagnose leaked current-user orphans from the failed worktree. Fix lifecycle/teardown at the source; do not treat kills as routine pre-retry workflow. Active session workers (`HATFIELD_SESSION_ID`) and root-owned processes must not be signaled.

## TUI test pyramid

| Layer | Command | Examples |
| --- | --- | --- |
| Virtual / in-process | `castor test` | `TuiStartupVirtualRenderTest`, `TuiVirtualInputTest` (`VirtualTuiHarness`): layout, input, `/hotkeys`, `!!` rejection |
| Controller replay | `castor test:controller-replay` | JSONL runtime, session/events, shell/tool ordering |
| Minimal tmux smoke | `castor test:tui` | `#[Group('tui-e2e-replay')]`: `TuiJourneyE2eTest` (integration smoke), `TuiStartupSnapshotTest` (golden snapshot) |

Do **not** add broad journey phases for features already proven virtually. `castor test:tui` remains the gate’s tmux replay lane; virtual TUI tests are **not** in that group — they run under `castor test`.

**Tmux journey smoke (`TuiJourneyE2eTest`):** one long-lived session for terminal integration (startup, reasoning, shell, completion, replay model step, export, inline shell). Local-only behaviors moved to virtual tests are documented in the Journey class docblock (e.g. `/hotkeys`, `!!` → `TuiVirtualInputTest`).

- Tmux/replay tests use `APP_ENV=test` + source `bin/console` (not PHAR); `config/services_test.yaml` wires `ControllerReplayHttpClientFactory` for deterministic model responses.
- No live LLM, no `LLAMA_CPP_SMOKE_TEST`, no PHAR for replay lanes.

## Virtual TUI harness

`tests/Tui/Support/VirtualTuiHarness.php` drives `ChatScreen` + Symfony `VirtualTerminal` / `ScreenBuffer` without tmux. Use for deterministic widget, input, and local command/render proofs. See `tests/Tui/Screen/TuiStartupVirtualRenderTest.php` and `TuiVirtualInputTest.php`.

## TUI E2E snapshot artifacts

After `castor test:tui`, passing test snapshots are kept at `var/tmp/tui-e2e-*/` for inspection. Each isolated test directory contains:
- `.hatfield/tmp/tui/smoke/*.ansi` — ANSI terminal snapshots captured by `saveAnsiSnapshot()`
- `.hatfield/sessions/<id>/events.jsonl` — canonical event log for resumed sessions

After failures, diagnostics go to `var/tmp/tui-failures/` (ANSI snapshots + plain text dumps).

Run `castor cleanup` to remove all temp/test artifacts. See `tests/AGENTS.md` for full test standards: shared helpers, directory isolation, fast E2E waits, what not to test, one-class-per-file rules.

TUI E2E waits should target exact visible proof with short caps (typically 2-5s for startup/status/UI assertions on the local test model). Avoid broad 30-60s waits and fixed `usleep()` calls unless the delay itself is the behavior under test.

## DB-touching tests

If a test touches the database, it is an integration test, not a unit test. Use `KernelTestCase` + `static::getContainer()` for EntityManager/repository/services. Do not use standalone `ORMSetup`/`DriverManager`/`SchemaTool`/`EntityManager` factories in tests. Test DB is configured via `config/packages/test/doctrine.yaml`; DAMA/DoctrineTestBundle wraps each test in a transaction for rollback isolation. Schema is created once before the suite runs, not per test. Load test data via container EntityManager or fixtures, not manual in-memory SQLite factories.
