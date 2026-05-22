# Fix agent crash + upgrade testing infrastructure to catch real errors

## Goal

Fix the broken agent AND fix the testing infrastructure so it properly catches errors.
Testing infrastructure upgrade MUST come first — without it we're flying blind.

## Why this matters

We have 800+ unit tests that all pass while the agent is completely broken.
E2E tests (test:tui, test:llm-real, run:agent-test) fail with blank snapshots
and no diagnostics. This is unacceptable — tests must catch real failures.

## Phase 1: Testing Infrastructure Upgrade (MUST BE DONE FIRST)

### 1A. Test isolation with tmp directory
- Create `var/tmp/test-{uuid}/` for each E2E/smoke test run
- Configure `APP_ENV=test` to use this tmp directory for:
  - Session path (`.hatfield/sessions/`)
  - Messenger SQLite DB (`.hatfield/messenger.sqlite`)
  - Logs
- Clean up after test completes
- Tests must NOT use real `.hatfield/` or share state between runs

### 1B. Fix TuiAgentSmokeTest (tests/Tui/E2e/TuiAgentSmokeTest.php)
- Detect agent process crash (non-zero exit code, exception in logs)
- When snapshot is empty (pane destroyed = crash), check session artifacts for errors
- Dump stderr/stdout from the agent process on failure
- Report the ACTUAL error, not just "timed out" or "empty snapshot"
- Verify assistant response content, not just transcript block count

### 1C. Fix LlamaCppSmokeTest (tests/AgentCore/Integration/LlamaCppSmokeTest.php)
- Must capture and report exceptions from the agent process
- Must verify response content against the expected prompt
- Must fail with clear error message when agent crashes

### 1D. Fix castor run:agent-test
- Must detect and report agent crashes immediately (not wait for timeout)
- Must capture stderr from the tmux pane
- Must dump session artifacts on failure

### 1E. RuntimeEventPoller error propagation (src/Tui/Runtime/RuntimeEventPoller.php)
- Stop silently swallowing ALL errors with just a warning log
- Surface transport-dead errors to the TUI so user sees what went wrong
- Distinguish transient polling errors from fatal transport failures

## Phase 2: Fix the bugs (tests should now catch these)

### Bug 1: InputStream::write() after Process::start() silently drops data (CRITICAL)
**File:** `src/CodingAgent/Runtime/Process/JsonlProcessAgentSessionClient.php`
Symfony `Process` + `InputStream` doesn't work for writing after `start()`. `AbstractPipes::write()` checks `Generator::valid()` which returns false (generator not started yet), so stdin pipe gets closed on first `getIncrementalOutput()` call.
**Fix:** Replace `Process` + `InputStream` with `proc_open()` for direct stdin/stdout pipe control.

### Bug 2: ConsumerSupervisor::launch() crashes controller (CRITICAL)
**File:** `src/CodingAgent/Runtime/Controller/ConsumerSupervisor.php`
`Process` constructor receives no `cwd` argument → `ValueError: First element must contain a non-empty program name`. Controller crashes before entering event loop.
**Fix:** Add `cwd` parameter, wrap launch in try-catch.

### Bug 3: In-process transport broken since ASYNC-05
`StartRun` routes to `run_control` Doctrine transport unconditionally. No consumer in in-process mode.
**Fix:** Make messenger routing conditional — in-process mode uses sync transport.

## Phase 3: Validate fixes with actual tests

Run ALL of these and they MUST pass:
- `castor test` — all unit tests pass
- `castor deptrac` — 0 violations
- `castor phpstan` — 0 errors
- `castor cs-check` — clean
- `castor run:agent-test` — TUI launches, accepts prompt, gets response
- `castor test:llm-real` — passes with real llama.cpp
- `castor test:tui` — passes

## Acceptance criteria
- [ ] Tests use isolated tmp directory, not real .hatfield/
- [ ] E2E tests report actual errors, not blank snapshots
- [ ] Agent launches via castor run:agent-test without crash
- [ ] castor test:llm-real passes with real llama.cpp
- [ ] castor test:tui passes
- [ ] castor check (test + deptrac + phpstan + cs-check) clean
- [ ] In-process transport works for testing
- [ ] Process transport works with proc_open replacing Process+InputStream
- [ ] ConsumerSupervisor launches consumers without ValueError

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
- Created: 2026-05-22T23:17:09.029Z
