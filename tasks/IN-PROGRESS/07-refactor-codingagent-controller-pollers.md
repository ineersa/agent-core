# 07-refactor-codingagent-controller-pollers: extract headless controller pollers

## Goal
Plan: .pi/plans/architecture-refactor-plan.md
Reports: .pi/reports/coding-agent-architecture.md, .pi/reports/tests-architecture.md

Shrink HeadlessController by extracting self-contained event-emitter and stdout-polling services. Preserve controller-mode event ordering, partial-line JSONL buffering, transcript persistence, and process supervision behavior.

## Design decisions
- **RuntimeEventEmitter** (not "drainer" or "poller") — owns stdout resource, cursor tracking, transcript persistence feed, and emit pipeline. The drain loop is its internal concern (drains from InProcessAgentSessionClient → emit). All event writes go through this class.
- **LlmStdoutPoller** — polls LLM child process stdout pipe, parses partial-line JSONL buffers, delegates to RuntimeEventEmitter->emit(). Direct dependency (no callable indirection).
- **Stdout ownership**: RuntimeEventEmitter owns stdout. HeadlessController uses $emitter->emit() for ALL event writes including command ACKs and RuntimeReady.
- **Process supervision stays in HeadlessController** — killOrphanedConsumers() and shutdown() are lifecycle concerns, not extraction targets.

## Scope

### New files
1. **`src/CodingAgent/Runtime/Controller/RuntimeEventEmitter.php`** (~180 lines)
   - Constructor: `InProcessAgentSessionClient $eventClient, TranscriptPersistenceService $transcriptPersistence, RuntimeExceptionBoundary $boundary, LoggerInterface $logger`
   - Owns: `$stdout` resource, `$runEventCursors` array
   - Public API:
     - `openStdout(): void` — opens php://stdout
     - `emit(RuntimeEvent): void` — auto cursor register/release on lifecycle events + emitInternal
     - `startDrainLoop(float $interval = 0.05): void` — registers EventLoop::repeat, drains eventClient per run, cursor-gated, error recovery
     - `closeStdout(): void` — cleanup
   - Private: `emitInternal()`, `feedPersister()`, `persistTranscripts()`
   - Cursor auto-registration on: RunStarted, RunResumed, RunResuming
   - Cursor release on: RunCompleted, RunFailed, RunCancelled
   - Error handling: boundary catch → ProtocolError emit → cursor release → retry next tick

2. **`src/CodingAgent/Runtime/Controller/LlmStdoutPoller.php`** (~120 lines)
   - Constructor: `ConsumerSupervisor $consumerSupervisor, RuntimeEventEmitter $emitter, RuntimeExceptionBoundary $boundary, LoggerInterface $logger, int $maxBadLines = 10`
   - Owns: `$llmStdoutBuffer` string, `$consecutiveBadLlmLines` int
   - Public API:
     - `startPollLoop(float $interval = 0.01): void` — registers EventLoop::repeat
   - Private: `pollLlmStdout()` — partial-line accumulation, RuntimeEvent::fromArray parsing, noise filtering, bad-line threshold
   - Direct dependency on RuntimeEventEmitter (calls $emitter->emit())

3. **`tests/CodingAgent/Runtime/Controller/RuntimeEventEmitterTest.php`** (~150 lines)
   - Test cursor auto-register/release on lifecycle events
   - Test drain loop iterates eventClient with cursor gating
   - Test seq=0 transient skip
   - Test error recovery (boundary catch, ProtocolError emit, cursor release, retry)
   - Test transcript persistence after drain
   - Test emit writes to stdout

4. **`tests/CodingAgent/Runtime/Controller/LlmStdoutPollerTest.php`** (~120 lines)
   - Test partial-line buffer accumulation and line completion
   - Test valid RuntimeEvent JSONL parsing and emit delegation
   - Test non-JSONL noise filtering
   - Test consecutive bad line counting and ProtocolError threshold
   - Test empty output short-circuit

### Modified files
5. **`src/CodingAgent/Runtime/Controller/HeadlessController.php`** (650→~370 lines, ~-280)
   - Constructor simplified: removes eventClient, transcriptPersistence, adds RuntimeEventEmitter
   - `run()` reduced to: openStdout via emitter, killOrphanedConsumers, RuntimeReady emit via emitter, launch consumers, register stdin watcher, create LlmStdoutPoller + start loops, register supervision + signal watchers
   - Command ACK/rejected events use `$emitter->emit()` instead of local emit
   - Remove: emit(), emitInternal(), feedPersister(), persistTranscripts(), pollLlmStdout(), $runEventCursors, $llmStdoutBuffer, $consecutiveBadLlmLines
   - Keep: handleCommandLine(), decodeCommand(), ackCommand(), emitCommandRejected(), killOrphanedConsumers(), shutdown()

6. **`config/services.yaml`** (~+5 lines)
   - Wire RuntimeEventEmitter with InProcessAgentSessionClient, TranscriptPersistenceService
   - Wire LlmStdoutPoller with ConsumerSupervisor, RuntimeEventEmitter

7. **`tests/CodingAgent/Runtime/Controller/E2E/ControllerSmokeTest.php`** — no changes (E2E smoke coverage unchanged)

### Implementation order
1. Create RuntimeEventEmitter with emit(), emitInternal(), cursor tracking, stdout open/close
2. Create RuntimeEventEmitterTest — unit tests for cursor, drain, emit, error recovery, transcript persistence
3. Create LlmStdoutPoller with pollLlmStdout(), partial-line buffer, bad-line tracking
4. Create LlmStdoutPollerTest — unit tests for buffer, parsing, noise, threshold
5. Rewrite HeadlessController to delegate to emitter + poller
6. Update config/services.yaml DI wiring
7. Validate: castor test, castor deptrac, castor phpstan, castor cs-check

## Acceptance criteria
- HeadlessController reduced from ~650 to ~370 lines; event emit and stdout poll logic in focused services.
- RuntimeEventEmitter has focused unit tests covering cursor lifecycle, drain loop, error recovery, transcript persistence.
- LlmStdoutPoller has focused unit tests covering partial-line buffer, JSONL parsing, noise filtering, bad-line threshold.
- ControllerSmokeTest and related controller E2E behavior remain unchanged.
- Validation: castor test (all), castor deptrac, castor phpstan, castor cs-check.

## Workflow metadata
Status: IN-PROGRESS
Branch: task/07-refactor-codingagent-controller-pollers
Worktree: /home/ineersa/projects/agent-core-worktrees/07-refactor-codingagent-controller-pollers
Fork run: vs33eermxfqy
PR URL:
PR Status:
Started: 2026-06-03T18:50:30.763Z
Completed:

## Work log
- Created: 2026-06-03T00:32:14.231Z
- Updated: 2026-06-03 — Full implementation plan with RuntimeEventEmitter + LlmStdoutPoller extraction, naming decisions, DI wiring, test strategy

## Task workflow update - 2026-06-03T18:50:30.763Z
- Moved TODO → IN-PROGRESS.
- Created branch task/07-refactor-codingagent-controller-pollers.
- Created worktree /home/ineersa/projects/agent-core-worktrees/07-refactor-codingagent-controller-pollers.
- Copied vendor directory into /home/ineersa/projects/agent-core-worktrees/07-refactor-codingagent-controller-pollers.
- Copied .vera index into /home/ineersa/projects/agent-core-worktrees/07-refactor-codingagent-controller-pollers.

## Task workflow update - 2026-06-03T18:53:53.796Z
- Claimed task, moved to IN-PROGRESS, branch task/07-refactor-codingagent-controller-pollers, worktree at /home/ineersa/projects/agent-core-worktrees/07-refactor-codingagent-controller-pollers
- Launched fork vs33eermxfqy — extracting RuntimeEventEmitter + LlmStdoutPoller from HeadlessController (650→~370 lines), creating 2 new services + 2 test files, updating services.yaml DI wiring

## Task workflow update - 2026-06-03T18:59:00.957Z
- Recorded fork run: vs33eermxfqy
- Validation: castor test — 1594 tests, 4745 assertions, 0 failures; castor deptrac — 0 violations; castor phpstan — 0 errors; castor cs-check — clean
- Summary: Fork vs33eermxfqy completed. Commit 8e802af4 on task/07-refactor-codingagent-controller-pollers. 6 files changed (+568/-324). HeadlessController shrunk from 650→353 lines (-45%). Created RuntimeEventEmitter (237 lines) and LlmStdoutPoller (146 lines). 6 new tests (1594 total, up from 1588). castor test PASS, deptrac 0 violations, phpstan 0 errors, cs-check clean.
