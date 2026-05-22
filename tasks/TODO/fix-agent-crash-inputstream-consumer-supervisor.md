# Fix agent crash: InputStream, ConsumerSupervisor, and test diagnostics

## Goal
## Root Cause

Agent is completely broken — neither `--transport=process` nor `--transport=in-process` works.

### Bug 1: InputStream::write() after Process::start() silently drops data (CRITICAL)
**File:** `src/CodingAgent/Runtime/Process/JsonlProcessAgentSessionClient.php`
Symfony `Process` + `InputStream` doesn't work for writing after `start()`. `AbstractPipes::write()` checks `Generator::valid()` which returns false (generator not started yet), so stdin pipe gets closed on first `getIncrementalOutput()` call. Data written via `InputStream::write()` is buffered but pipe is already gone.
**Fix:** Replace `Process` + `InputStream` with `proc_open()` for direct stdin/stdout pipe control.

### Bug 2: ConsumerSupervisor::launch() crashes controller (CRITICAL)
**File:** `src/CodingAgent/Runtime/Controller/ConsumerSupervisor.php`
`Process` constructor receives no `cwd` argument → `ValueError: First element must contain a non-empty program name` when `proc_open()` is called. Controller crashes before entering event loop.
**Fix:** Add `cwd` parameter, wrap launch in try-catch.

### Bug 3: In-process transport broken since ASYNC-05
`StartRun` routes to `run_control` Doctrine transport unconditionally. No consumer in in-process mode, messages pile up forever.
**Fix:** Make messenger routing conditional — in-process mode uses sync transport.

### Bug 4: RuntimeEventPoller silently swallows all errors
**File:** `src/Tui/Runtime/RuntimeEventPoller.php`
All polling errors caught and logged at warning, never surfaced to user or test.
**Fix:** At minimum, propagate errors that indicate the transport is dead (not just transient).

### Bug 5: TUI E2E tests report blank snapshots with no diagnostics
**File:** `tests/Tui/E2E/TuiAgentSmokeTest.php`
When TUI process crashes, tmux pane is destroyed, snapshot is empty string. Session artifacts on disk have the evidence but tests don't check them.
**Fix:** Check session artifacts when snapshot is empty; report the actual error.

## Phase 1: Testing Infrastructure (MUST BE DONE FIRST)

### 1A. Test isolation with tmp directory
- Create `var/tmp/test-{uuid}/` directory for each test run (or use PHPUnit beforeEach/afterEach)
- Configure `APP_ENV=test` to use this tmp directory for:
  - Session path (`.hatfield/sessions/`)
  - Messenger SQLite DB (`.hatfield/messenger.sqlite`)
  - Logs
- Clean up tmp directory after test completes
- This ensures tests don't pollute each other and don't use real `.hatfield/`

### 1B. Fix E2E test (TuiAgentSmokeTest)
- Must detect agent crashes (non-zero exit code, exception in logs)
- Must check session artifacts when snapshot is empty (pane destroyed = crash)
- Must dump stderr/stdout from the agent process on failure
- Must report the ACTUAL error, not just "timed out" or "empty snapshot"
- Must verify assistant response content, not just transcript block count

### 1C. Fix LlamaCppSmokeTest
- Must run through the actual TUI/transport, not just AgentCore directly
- Must capture and report exceptions from the agent process
- Must verify response content against the expected prompt

### 1D. Fix castor run:agent-test
- Must detect and report agent crashes immediately (not wait for timeout)
- Must capture stderr from the tmux pane
- Must dump session artifacts on failure

### 1E. RuntimeEventPoller error propagation
- Stop silently swallowing all errors
- Surface transport-dead errors to the TUI so user sees what went wrong

## Phase 2: Fix the bugs (after tests can catch them)

### Bug fixes as listed above (InputStream, ConsumerSupervisor, in-process routing)

## Phase 3: Validate fixes with actual tests

## Validation (all must pass AFTER fixes)
- `castor test` must pass
- `castor deptrac` clean
- `castor phpstan` clean
- `castor cs-check` clean
- `castor run:agent-test` TUI launches, accepts prompt, gets response
- `castor test:llm-real` passes with real llama.cpp
- `castor test:tui` passes

## Acceptance criteria
- Agent launches via castor run:agent-test without crash
- castor test:llm-real passes with real llama.cpp
- castor test:tui passes
- castor check (test + deptrac + phpstan + cs-check) clean
- In-process transport works for testing
- Process transport works with proc_open replacing Process+InputStream
- ConsumerSupervisor launches consumers without ValueError

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
