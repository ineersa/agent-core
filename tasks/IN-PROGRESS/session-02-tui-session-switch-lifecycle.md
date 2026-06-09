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
Fork run: wem261x41dp6
PR URL: https://github.com/ineersa/agent-core/pull/109
PR Status: open
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

## Task workflow update - 2026-06-08T22:58:36.044Z
- Validation: Reviewer verdict: REQUEST CHANGES for HEAD `00ccaa4f`.; Reviewed diff vs origin/main: 16 files, +740/-48.
- Summary: Reviewer subagent reviewed HEAD `00ccaa4f` and returned REQUEST CHANGES. Required findings: (1) `InteractiveMode` drops initial `StartRunRequest`/`--prompt` in the non-resume/non-draft branch by calling `initialize('', null)`, breaking prompt startup; (2) `TuiSessionSwitchService::cancelCurrentRun()` has an empty catch without diagnostic logging, violating AGENTS.md. Additional actionable findings: default no-arg startup now creates a lazy draft and should be explicitly documented/test-covered as intentional; draft-with-request path can drop first user message because `initializeDraft()` pre-populates `state->request`; stale duplicate `ChatScreen` docblock; TuiRuntime→TuiApplication dependency cycle from context importing concrete switch service; weak footer update tests; missing `initializeDraft()` test; class docblock references SESSION-03 too specifically. Launching fork to fix all actionable findings.
- task-to-pr: reviewer found prompt-start regression, empty catch logging violation, draft-with-request edge case, and cleanup/test/design issues; launching review-fix fork.

## Task workflow update - 2026-06-08T23:10:01.029Z
- Recorded fork run: o71nt0bj2cue
- Validation: Verified worktree clean at HEAD `7aa91215`.; Fork validation: `castor test --filter=SessionSwitchService` PASS — 10 tests, 35 assertions.; Fork validation: `castor test --filter=SessionInitializer` PASS — 14 tests, 64 assertions.; Fork validation: `castor test --filter=ChatScreen` PASS — 7 tests, 14 assertions.; Fork validation: `castor test --filter=QuestionCoordinator` PASS — 33 tests, 76 assertions.; Fork validation: `castor test` PASS — 2184 tests, 6371 assertions, 0 errors/failures/skipped.; Fork validation: `castor deptrac` PASS — 0 violations, 0 errors.; Fork validation: `castor phpstan` PASS — 0 errors, 0 file errors.; Fork validation: `castor cs-fix` + `castor cs-check` PASS — clean, 0 files fixed.
- Summary: Review-fix fork completed at commit `7aa91215` (`SESSION-02: Address switch lifecycle review findings`). It addressed all reviewer findings: preserves initial `StartRunRequest` in `InteractiveMode`; injects logger and emits structured warning for best-effort cancel failure; fixes draft-with-request first-message promotion by allowing promotion when `sessionId === ''` and merging request metadata; documents intentional lazy no-argument draft behavior; removes stale `ChatScreen` docblock; adds `TuiSessionSwitchServiceInterface` in `src/Tui/Runtime/Contract/` and types `TuiRuntimeContext` against it; strengthens `ChatScreen` footer assertions; adds `SessionInitializer::initializeDraft()` coverage; rephrases task-specific service docs. Verified worktree clean and cumulative branch diff is 20 files (+964/-61).
- task-to-pr: review-fix fork o71nt0bj2cue completed at 7aa91215; all initial reviewer findings addressed; proceeding to re-review current HEAD.

## Task workflow update - 2026-06-08T23:18:40.897Z
- Validation: Reviewer verdict for HEAD `7aa91215`: APPROVE WITH SUGGESTIONS, no critical/issues.; All previous REQUEST CHANGES findings confirmed resolved.
- Summary: Second reviewer pass on HEAD `7aa91215` returned APPROVE WITH SUGGESTIONS: all prior blocking findings resolved, no critical/issues. Remaining actionable cleanup: tests should stub `TuiSessionSwitchServiceInterface` rather than concrete `TuiSessionSwitchService`; remove vestigial `TuiRuntime -> TuiApplication` deptrac allowance; fix orphaned `rmDir()` docblock in `SessionInitializerTest`; add low-cost empty-session footer test for draft footer behavior. Skipping only non-actionable future suggestion to add `consumePendingSwitch()` to the interface because current design intentionally keeps it same-layer/internal until SESSION-03 proves need.
- task-to-pr: launching cleanup fork for reviewer suggestions: interface stubs, tighter deptrac rule, test docblock placement, empty draft footer test.

## Task workflow update - 2026-06-08T23:23:30.182Z
- Recorded fork run: 39ygty1r7rws
- Validation: Verified worktree clean at HEAD `2b250adf`.; Fork validation: `castor test --filter=ChatScreen` PASS — 14 tests, 64 assertions.; Fork validation: `castor test --filter=SessionInitializer` PASS — 14 tests, 64 assertions.; Fork validation: `castor test --filter=SessionSwitchService` PASS — 14 tests, 64 assertions.; Fork validation: `castor test` PASS — 2185 tests, 6372 assertions, 0 errors/failures/skipped.; Fork validation: `castor deptrac` PASS — 0 violations, 0 errors.; Fork validation: `castor phpstan` PASS — 0 errors, 0 file errors.; Fork validation: `castor cs-check` PASS — 0 files fixed.
- Summary: Cleanup fork completed at commit `2b250adf` (`SESSION-02: Tighten switch lifecycle review cleanup`). It addressed final reviewer suggestions: listener tests now stub `TuiSessionSwitchServiceInterface`; vestigial `TuiRuntime -> TuiApplication` deptrac allowance removed; `SessionInitializerTest` orphaned `rmDir()` docblock fixed; draft empty-session footer test added. Verified worktree clean at HEAD `2b250adf`; cumulative diff vs `origin/main` is 20 files (+1009/-61).
- task-to-pr: cleanup fork 39ygty1r7rws completed at 2b250adf; final reviewer pass starting.

## Task workflow update - 2026-06-08T23:32:39.738Z
- Validation: Final reviewer verdict: APPROVED for HEAD `2b250adf`; no required/actionable changes remain.; Local validation: `castor test` PASS — tests=2185, assertions=6372, errors=0, failures=0, skipped=0.; Local validation: `castor deptrac` PASS — violations=0, errors=0, uncovered=744, allowed=969.; Local validation: `castor phpstan` PASS — errors=0, file_errors=0.; Local validation: `castor cs-check` PASS — files_fixed=0.
- Summary: Final reviewer returned APPROVED for SESSION-02 at HEAD `2b250adf`. All prior REQUEST CHANGES and APPROVE WITH SUGGESTIONS findings are resolved. Local Castor validation passed on the worktree before CODE-REVIEW transition. Current HEAD: `2b250adfcfc0ee56e2cc1576bdf4a15bc41ca636`.
- task-to-pr: final reviewer approved HEAD 2b250adf; local Castor validation passed; moving task to CODE-REVIEW to run full quality gate and create/update PR.

## Task workflow update - 2026-06-08T23:36:19.418Z
- Validation: move_task(to=CODE-REVIEW) Castor gate FAILED: `test:tui` failed — tests=5, assertions=9, errors=1, failures=1.; Failure: `TuiAgentSmokeTest::testTypePromptAndVerifyTranscriptBlocks` — user block did not appear because runtime error displayed.; Failure: `TuiAgentSmokeTest::testMultiTurnConversationOrder` — timeout waiting for `❯`; pane showed runtime error `StartRunRequest::__construct(): Argument #3 ($cwd) must be of type string, null given`, called from `SubmitListener.php:121`.
- Summary: First move to CODE-REVIEW failed during full Castor quality gate at `test:tui`. TUI E2E exposed a runtime error in draft promotion: `StartRunRequest::__construct(): Argument #3 ($cwd) must be of type string, null given` from `src/Tui/Listener/SubmitListener.php:121`. Root cause: promotion creates `StartRunRequest` using `$state->request?->cwd` and `$state->request?->options` without defaults; for plain lazy draft startup, `$state->request` is null, so `cwd` and `options` are null. Task remains IN-PROGRESS. Launching a narrow fix fork.
- task-to-pr: full gate caught draft promotion StartRunRequest default bug; launching fix fork before retrying CODE-REVIEW.

## Task workflow update - 2026-06-08T23:44:10.738Z
- Recorded fork run: wem261x41dp6
- Validation: Verified worktree clean at HEAD `c43badb4`.; Fork validation: `castor test --filter=testDraftPromotion` PASS — 2 tests, 14 assertions.; Fork validation: `castor test` PASS — 2187 tests, 6386 assertions, 0 errors/failures/skipped.; Fork validation: `castor deptrac` PASS — 0 violations, 0 errors.; Fork validation: `castor phpstan` PASS — 0 errors, 0 file errors.; Fork validation: `castor cs-check` PASS — clean, 0 files fixed.; Fork validation: `castor test:tui` PASS — 5 tests, 18 assertions, 0 errors/failures/skipped; confirms original gate failure resolved.
- Summary: Gate-fix fork completed at commit `c43badb4` (`SESSION-02: Fix draft promotion request defaults`). It fixes the TUI E2E gate failure by defaulting `StartRunRequest` draft-promotion fields in `SubmitListener`: `cwd` now defaults to `''` and `options` defaults to `[]` when `state->request` is null. Added two regression tests covering plain draft null-request defaults and configured draft request value preservation. Verified worktree clean at HEAD `c43badb4`; cumulative branch diff is 20 files (+1083/-61).
- task-to-pr: gate-fix fork wem261x41dp6 completed at c43badb4; original TUI E2E gate subset now passes; running focused reviewer before retrying CODE-REVIEW.

## Task workflow update - 2026-06-08T23:51:20.300Z
- Validation: Focused reviewer verdict: APPROVED for delta `2b250adf..c43badb4`; no actionable issues.; Fork validation included `castor test:tui` PASS — 5 tests, 18 assertions.; Local validation: `castor test` PASS — tests=2187, assertions=6386, errors=0, failures=0, skipped=0.; Local validation: `castor deptrac` PASS — violations=0, errors=0, uncovered=744, allowed=969.; Local validation: `castor phpstan` PASS — errors=0, file_errors=0.; Local validation: `castor cs-check` PASS — files_fixed=0.
- Summary: Focused reviewer approved gate-fix HEAD `c43badb4`. The prior CODE-REVIEW gate failure is resolved by defaulting draft-promotion `StartRunRequest` `cwd`/`options` values and adding regression tests. Local Castor validation passed again at current HEAD `c43badb4abff74faafa773368e6320f067d7aed3`; retrying move to CODE-REVIEW.
- task-to-pr: focused reviewer approved gate-fix HEAD c43badb4; required local Castor validation passed; retrying CODE-REVIEW transition/full gate.

## Task workflow update - 2026-06-08T23:55:02.855Z
- Validation: move_task(to=CODE-REVIEW) second attempt: full Castor gate FAILED at `test:llm-real` / `ViewImageToolE2eTest::testViewImageToolProducesMetadata` assertion.; Diagnostic rerun: `castor test:llm-real` PASS — tests=5, assertions=37, errors=0, failures=0, skipped=0.
- Summary: Second move to CODE-REVIEW failed during full Castor gate at `test:llm-real`, specifically `ViewImageToolE2eTest::testViewImageToolProducesMetadata`. This matches prior known real-LLM flakiness and is unrelated to SESSION-02 changes; immediate rerun of `castor test:llm-real` passed. No implementation fork launched. Retrying CODE-REVIEW transition.
- task-to-pr: full gate encountered transient ViewImageToolE2eTest llm-real flake; subset rerun passed, retrying CODE-REVIEW without code changes.

## Task workflow update - 2026-06-08T23:59:40.101Z
- Validation: move_task(to=CODE-REVIEW) third attempt: full Castor gate FAILED at `test:llm-real` / `ViewImageToolE2eTest::testViewImageToolProducesMetadata`, line 93 `Failed asserting that false is true`.; Diagnostic rerun: `castor test:llm-real` PASS — tests=5, assertions=37, errors=0, failures=0, skipped=0.
- Summary: Third move to CODE-REVIEW also failed during full Castor gate at the same unrelated `ViewImageToolE2eTest` llm-real test (line 93 `Failed asserting that false is true`). Immediate standalone rerun of `castor test:llm-real` passed again. This continues to indicate transient real-LLM E2E flakiness, not a SESSION-02 code failure. Retrying CODE-REVIEW gate once more without code changes.
- task-to-pr: repeated unrelated ViewImageToolE2eTest llm-real flake in full gate; standalone llm-real rerun passed; retrying move_task again without code changes.
Castor Check Status: passed
Castor Check Commit: c43badb4abff74faafa773368e6320f067d7aed3
Castor Check Command: LLM_MODE=true castor check
Castor Check Timeout: 900s
Castor Check Completed: 2026-06-09T00:02:51.106Z
Castor Check Output SHA256: f8c752cb15b10d9961afd4a1f617f94ed8ca964bf1c85cdd409784bb9379d241

## Task workflow update - 2026-06-09T00:02:54.803Z
- Moved IN-PROGRESS → CODE-REVIEW.
- Castor quality gate passed (900s timeout). Commit: c43badb4abff.
- Pushed task/session-02-tui-session-switch-lifecycle to origin.
- branch 'task/session-02-tui-session-switch-lifecycle' set up to track 'origin/task/session-02-tui-session-switch-lifecycle'.
- Created PR: https://github.com/ineersa/agent-core/pull/109
- Validation: Reviewer: APPROVED at HEAD `2b250adf`.; Focused reviewer after gate-fix: APPROVED for HEAD `c43badb4`.; Pre-gate local validation: `castor test` PASS — 2187 tests, 6386 assertions, 0 errors/failures/skipped.; Pre-gate local validation: `castor deptrac` PASS — 0 violations, 0 errors.; Pre-gate local validation: `castor phpstan` PASS — 0 errors.; Pre-gate local validation: `castor cs-check` PASS — clean.; Gate-fix fork validation: `castor test:tui` PASS — 5 tests, 18 assertions.; Diagnostic reruns after transient gate flakes: `castor test:llm-real` PASS — 5 tests, 37 assertions (twice).
- Summary: SESSION-02 implementation reviewed and approved. Branch HEAD `c43badb4abff74faafa773368e6320f067d7aed3` adds a reusable TUI session switch lifecycle seam (`TuiSessionSwitchService` + runtime contract), refactors `InteractiveMode` into a same-process rebuild loop, adds lazy draft session support and draft promotion on first prompt, resets question/HITL/projector state on switch, updates `ChatScreen` session footer handling, and updates docs/tests/deptrac. Reviewer iterations fixed initial prompt startup, cancel-failure logging, draft-with-request promotion, interface layering, test/doc cleanup, and the TUI E2E draft-promotion `StartRunRequest` default bug. Prior gate attempts hit transient unrelated `ViewImageToolE2eTest` llm-real flakes; standalone `castor test:llm-real` reruns passed.

## Task workflow update - 2026-06-09T00:26:54.813Z
- Moved CODE-REVIEW → IN-PROGRESS.
- Summary: Moving SESSION-02 back to IN-PROGRESS to address PR #109 inline review feedback. Comments: (1) clarify that the `InteractiveMode` session switch `while (true)` loop does not spin CPU because `Tui::run()` blocks on Revolt suspension until `Tui::stop()` is called; (2) add or expose proper TUI lifecycle hooks/events for session start/end/resume rather than only hard-coded switch internals. Implementation will be done by fork per orchestrator workflow.
