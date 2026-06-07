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
Status: TODO
Branch:
Worktree:
Fork run:
PR URL:
PR Status:
Started:
Completed:

## Work log
- Created: 2026-06-07T20:45:22.373Z
