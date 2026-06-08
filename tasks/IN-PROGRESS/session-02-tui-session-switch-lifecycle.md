# SESSION-02 TUI session switch and reset lifecycle foundation

## Goal
Create the TUI/runtime foundation needed to switch the running TUI between sessions without restarting the whole terminal process.

## Context
Current architecture assumes one TUI process owns one session for its lifetime. Scouts found the hard reset points:
- `ChatScreen` captures session id in the footer provider at construction time.
- `TranscriptProjector` is a stateful DI singleton and must be reset between sessions.
- `QuestionCoordinator` is stateful and has no public reset/clear API.
- `TuiSessionState` contains many mutable per-session fields (`handle`, transcript, `lastSeq`, usage, footer state, poll errors, activity, etc.).
- `JsonlProcessAgentSessionClient` is session/run scoped and process transport must not leak old-session queues/events.

This task should introduce a reusable session switch/reset service or equivalent orchestration seam. Later commands (`/resume`, `/new`) should use this instead of duplicating reset logic.

## Dependencies
- Best after RTVS-08 final resume integration, because switching to an existing session relies on canonical `events.jsonl` transcript replay and rebuild/checkpoint behavior.
- May be implemented before command UI if covered by tests through direct service calls.

## Current code facts

### Primary files to change

#### `src/Tui/Application/InteractiveMode.php`
- Entry point: `run(AgentSessionClient $client, ?StartRunRequest $request, string $sessionId): int`
- Contains `startOrResumeRun()` — calls `client->start()` or `client->resume()`
- Builds `ChatScreen`, mounts widgets, creates `TuiRuntimeContext`, registers all listers
- **Key seam**: add a `switchSession(string $targetSessionId, bool $isResume): void` method or extract to a new `SessionSwitchService`

#### `src/Tui/Application/SessionInitializer.php`
- `initialize(?string $sessionId, ?StartRunRequest $request): TuiSessionState` — creates or validates session
- `buildInitialTranscript(TuiSessionState $state): void` — loads persisted transcript (currently stale until RTVS-08A)
- After RTVS-08A/B, this replays from canonical events.jsonl; the switch service should reuse this same replay path

#### `src/Tui/Runtime/TuiSessionState.php`
Fields that must reset on switch:
```php
public string $sessionId;
public bool $resuming;
public ?RunHandle $handle = null;
public ?StartRunRequest $request = null;
public RunActivityStateEnum $activity = RunActivityStateEnum::Idle;
public array $transcript = [];          // list<TranscriptBlock>
public int $lastSeq = 0;
public float $lastPoll = 0.0;
public int $runtimePollErrorCount = 0;
public string $lastRuntimePollError = '';
public string $footerModel = '';
public string $footerReasoning = '';
public int $contextWindow = 0;
public UsageProjection $usage;
public float $sessionStartTime = 0.0;
public string $cwd = '';
public string $branch = '';
```
Consider adding a `TuiSessionState::resetAllButSessionId(string $sessionId): void` or a standalone reset helper.

#### `src/Tui/Screen/ChatScreen.php`
- `$sessionId` captured in `createDefaultFooterProvider()` closure at construction time.
- **Hardest dependency**: the default footer provider is an anonymous class capturing `$sessionId`. Options:
  - A: Store session ID in a mutable reference (e.g., `TuiSessionState` field wrapped in a closure that reads at render time).
  - B: Add `ChatScreen::updateSessionId(string $id): void` that rebuilds/replaces the footer segment provider.
  - C: Switch to `FooterDataProvider` / slot-based footer that reads from `TuiSessionState` at render time.
  - **Recommendation**: Option A or B — make footer segment provider read from a mutable source or make `ChatScreen` accept a dynamic session-ID segment.

#### `src/Tui/Question/QuestionCoordinator.php`
- No public `reset()` or `clear()` method.
- Internally holds: `?QuestionRequest $active`, `?QuestionStatus $activeStatus`, `\SplQueue $queue`, `array $callbacks`, `array $cancelCallbacks`, `array $requestIds`.
- `\SplQueue` has no `clear()` method — need `while(!$q->isEmpty()) $q->dequeue();` or refactor to `SplDoublyLinkedList::setIteratorMode`.
- **Action**: Add a public `reset(): void` method that closes the active question (if any), drains the queue, and clears all callback registrations.

#### `src/CodingAgent/Runtime/Process/JsonlProcessAgentSessionClient.php`
- Session-scoped: maintains `$sessionId`, `$activeRunId`, and session-scoped queue DSNs.
- When switching sessions, the queue DSN suffix changes. Options:
  - A: Restart the controller subprocess with new queue DSNs (heavy but clean).
  - B: Update `$activeRunId` and let the process transport handle it (may cause event leakage from old run).
  - **Recommendation**: Cancel old run (`$this->cancel($oldRunId)`), update `$activeRunId` to the new run, reset `$lastEventCursor` if any.

#### `src/CodingAgent/Runtime/Contract/AgentSessionClient.php`
- Interface: `start()`, `resume()`, `send()`, `events()`, `cancel()`
- A switch must: `cancel()` old run (if running), then `start()` or `resume()` the target.

### DI singletons to reset
| Service | Method | Location |
|---------|--------|----------|
| `TranscriptProjectorInterface` | `reset()` | `src/CodingAgent/Runtime/ProjectionPipeline/TranscriptProjector.php` |
| `QuestionCoordinator` | `reset()` (NEW) | `src/Tui/Question/QuestionCoordinator.php` |
| `UsageProjection` (in state) | new instance | constructed in `TuiSessionState` constructor |

## Implementation approach options

A) **Inline in `InteractiveMode`** — add a `switchSession()` method that does all the reset work. Simple but makes `InteractiveMode` even larger.

B) **New `SessionSwitchService`** class — injected into `InteractiveMode` and future slash command handlers. Holds the orchestration logic. **Recommended.**

```php
class SessionSwitchService {
    public function __construct(
        private AgentSessionClient $client,
        private HatfieldSessionStore $sessionStore,
        private SessionInitializer $sessionInit,
        private TranscriptProjectorInterface $projector,
        private QuestionCoordinator $questionCoord,
        private ChatScreen $screen,
    ) {}

    public function switchToNew(?StartRunRequest $request): TuiSessionState { ... }
    public function switchToResume(string $sessionId): TuiSessionState { ... }
    private function resetState(): void { ... }
}
```

## Known pitfalls
- `ChatScreen` footer is the hardest part — verify rendering path before deciding approach.
- `QuestionCoordinator` may have active callbacks referencing the old session; `reset()` must clear them all.
- If switching while a run is actively streaming, `cancel()` may need to wait for graceful stop or accept a forced reset.
- Process transport (`JsonlProcessAgentSessionClient`) spawns a subprocess; ensure old subprocess is properly terminated.
- The TUI event loop runs on `tui->run()`, which is blocking. Session switch happens within a Tick or Submit callback — must not block the event loop.
- After switch, `RuntimeEventPoller` cursor (`lastSeq`) must be correct: for resumed sessions, set from replayed events; for new sessions, start from 0.
- No compatibility fallback to old `transcript.jsonl`/`runtime-events.jsonl` unless explicitly requested.
- Runtime/TUI changes require full `castor check` before CODE-REVIEW.
- TUI must talk to runtime via `AgentSessionClient`, not AgentCore internals per deptrac boundaries.

## Out of scope
- Slash command registration and picker UI.
- Session name/list API (SESSION-01).
- Tree/branch navigation.

## Acceptance criteria
- A single TUI-facing lifecycle abstraction can switch to an existing session id or reset to a fresh pending session without rebuilding the whole CLI process.
- Switching sessions resets all per-session mutable state: transcript, `lastSeq`, poll timing/errors, activity, usage, handle/request, footer model/reasoning/context, and session start timing.
- Switching sessions resets `TranscriptProjector` and prevents projected blocks from the old session leaking into the new session.
- Open/pending question overlays and `QuestionCoordinator` state are closed/reset or switching is rejected with a clear diagnostic; no stale HITL question remains bound to the old session.
- The footer/header/session display updates to the new session id/name; `ChatScreen` no longer bakes stale session id text for the lifetime of the process.
- Process and in-process transports do not leak events from the previous run after a switch; dedup cursor is initialized from replayed history for resumed sessions.
- Switching behavior is tested for at least fresh -> resumed and resumed -> fresh transitions with no duplicate transcript blocks.
- Validation uses Castor per project rules; runtime/TUI changes require full `castor check` before CODE-REVIEW.

## Workflow metadata
Status: IN-PROGRESS
Branch: task/session-02-tui-session-switch-lifecycle
Worktree: /home/ineersa/projects/agent-core-worktrees/session-02-tui-session-switch-lifecycle
Fork run: bxv5ii084s6a
PR URL:
PR Status:
Started: 2026-06-08T21:50:11.525Z
Completed:

## Work log
- Created: 2026-06-07T20:45:22.373Z

## Task workflow update - 2026-06-08T21:50:05.792Z
- Summary: Task-explain decisions confirmed by user before task-start: (1) Accept controlled rebuild of TUI objects inside the same CLI process as the session switch lifecycle; true in-place listener/context mutation is not required for this task. (2) Switching with active HITL/questions should cancel/reset local question state rather than reject the switch. (3) SESSION-02 should include enough lazy fresh-draft support so SESSION-03 `/new` can call the lifecycle seam without creating orphan DB/session rows before the first submitted prompt. (4) Prefer the simpler runtime boundary first: do not add `AgentSessionClient` API unless necessary; fix concrete process-client/session respawn behavior if needed.
- task-explain: user approved controlled TUI loop rebuild, cancel/reset active questions on switch, lazy draft support for `/new`, and no AgentSessionClient interface expansion unless proven necessary. Starting implementation phase next.

## Task workflow update - 2026-06-08T21:50:11.525Z
- Moved TODO → IN-PROGRESS.
- Created branch task/session-02-tui-session-switch-lifecycle.
- Created worktree /home/ineersa/projects/agent-core-worktrees/session-02-tui-session-switch-lifecycle.
- Copied vendor directory into /home/ineersa/projects/agent-core-worktrees/session-02-tui-session-switch-lifecycle.
- Copied .vera index into /home/ineersa/projects/agent-core-worktrees/session-02-tui-session-switch-lifecycle.
- Summary: Starting SESSION-02 implementation after task-explain. User-approved architecture: controlled rebuild of TUI objects inside the same CLI process, cancel/reset active HITL/questions on switch, include lazy fresh-draft support for future `/new`, and avoid `AgentSessionClient` API expansion unless implementation proves necessary.

## Task workflow update - 2026-06-08T21:51:01.587Z
- Recorded fork run: bxv5ii084s6a
- Summary: Implementation fork launched for SESSION-02. Scope: add reusable TUI session switch lifecycle seam using controlled TUI object rebuild inside the same CLI process; reset/cancel active question/HITL state; add lazy fresh-draft support for future `/new`; keep `AgentSessionClient` interface unchanged unless impossible; fix concrete process transport session respawn behavior if needed; add tests/docs; run Castor validation subset.
- task-start: launched implementation fork bxv5ii084s6a in worktree `/home/ineersa/projects/agent-core-worktrees/session-02-tui-session-switch-lifecycle` with user-approved architecture decisions and scout findings.

## Task workflow update - 2026-06-08T22:40:07.033Z
- Recorded fork run: bxv5ii084s6a
- Validation: Verified worktree clean on branch `task/session-02-tui-session-switch-lifecycle` at HEAD `00ccaa4f`.; Verified commit stat: 16 files changed, 740 insertions, 48 deletions.; Verified canonical diff vs `origin/main`: expected SESSION-02 lifecycle files/tests only.; Fork validation: `castor test --filter=QuestionCoordinator` PASS — 26 tests pass.; Fork validation: `castor test --filter=SessionSwitchService` PASS — 10 tests pass.; Fork validation: `castor test --filter=ChatScreen` PASS including new session-id update coverage.; Fork validation: `castor test` PASS — 2180 tests, 6349 assertions, 0 errors/failures/skipped.; Fork validation: `castor deptrac` PASS — 0 violations, 0 errors.; Fork validation: `castor phpstan` PASS — 0 errors, 0 file errors.; Fork validation: `castor cs-check` PASS — 0 files fixed.; Full `LLM_MODE=true castor check` not run in task-start; reserved for task-to-pr/CODE-REVIEW gate per workflow.
- Summary: Implementation fork completed SESSION-02 at commit `00ccaa4f` (`SESSION-02: Add TUI session switch lifecycle seam`). Verified worktree clean and branch HEAD is the implementation commit. The implementation adds `TuiSessionSwitchService` and `TuiSessionSwitchTargetDTO`, refactors `InteractiveMode::run()` into a controlled loop that rebuilds fresh TUI/session objects inside the same CLI process after a switch request, adds lazy draft initialization/promotion for future `/new`, adds `QuestionCoordinator::reset()`, makes `ChatScreen` session footer updatable for draft promotion, extends `TuiRuntimeContext` with the switch service, and updates tests/deptrac wiring. No `AgentSessionClient` interface change was added.
- task-start: implementation fork bxv5ii084s6a completed at 00ccaa4f; verified clean worktree/diff and recorded validation. Stop here until user requests task-to-pr.
