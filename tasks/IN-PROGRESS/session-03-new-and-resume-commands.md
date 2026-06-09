# SESSION-03 /new and /resume session commands

## Goal
Add interactive TUI commands for starting a fresh session and resuming/switching to an existing session.

## Desired UX
- `/resume` with no arguments opens a picker near the bottom/editor area listing recent sessions as `session id + session name` (plus useful secondary metadata if space permits).
- Selecting a session resumes/switches to it and reloads the TUI transcript/state from where it ended.
- `/resume <session id>` executes directly.
- `/new` clears the TUI into a fresh session state. Prefer lazy creation: a new DB/session directory is created on first submitted message, not merely by opening an empty draft, unless implementation constraints force a documented alternative.

## Current code facts

### Existing reference pattern: ModelControlListener + ModelPickerController
- `src/Tui/Listener/ModelControlListener.php` — registers `/model` command + interactive picker + Ctrl+P/Shift+Tab
- `src/Tui/Picker/ModelPickerController.php` — uses `PickerOverlay` + `SelectListWidget` for list selection
- `src/Tui/Picker/PickerOverlay.php` — `mount()` appends container widget to `Tui` root; `close()` removes it
- This is the template for `/resume` picker and `/new` behavior

### Existing slash command infrastructure
- `src/Tui/Command/SlashCommandRegistry.php` — built-ins: `/help`, `/clear`, `/exit`; supports `register(CommandMetadata, SlashCommandHandler)` and `setHandler(name, handler)`
- `src/Tui/Command/CommandParser.php` — parses `/cmd args` → `SlashCommand(name, args, originalText)`
- `src/Tui/Command/SubmissionRouter.php` — routes slash commands to registry, normal prompts to runtime
- `src/Tui/Command/SlashCommandHandler.php` — interface: `handle(SlashCommand): CommandResult`
- `src/Tui/Listener/SubmitListener.php` — applies `CommandResult` variants: `TranscriptMessage`, `ClearTranscript`, `ExitApplication`, `StatusUpdate`; **ignores `DispatchRuntime`**

### Command result types
- `src/Tui/Command/TranscriptMessage.php` — append to transcript
- `src/Tui/Command/NoOp.php` — silently ignored
- `src/Tui/Command/ExitApplication.php` — stop TUI
- `src/Tui/Command/DispatchRuntime.php` — placeholder, NOT wired yet (may need wiring for `/new` to trigger runtime start)

### Pickers and overlays
- `SelectListWidget` — list with keybindings: `select_up`/`down`/`page_up`/`page_down`/`select_confirm`/`select_cancel`; `onSelect()`, `onCancel()`, `onInput()` callbacks
- `PickerOverlay` — `mount()` → `tui->add(container)`; `close()` → `tui->remove(container)`; appends at bottom of widget tree
- `QuestionController::insertOverlayBeforeEditor()` — alternative overlay placement (above editor, replaces editor position)
- **Recommendation**: for session picker, use `PickerOverlay` pattern (appended at bottom, session selector feels like a model picker)

### Session picker data source
- After SESSION-01: `HatfieldSessionStore::listSessions()` returns array with sessionId / name / displayTitle / prompt / model / timestamps
- Picker items display: `displayTitle` (name fallback logic applied)

## Implementation seams

### New files to create

#### `src/Tui/Command/NewSessionCommand.php`
```php
class NewSessionCommand implements SlashCommandHandler {
    public function __construct(private SessionSwitchService $switcher) {}
    public function handle(SlashCommand $cmd): CommandResult {
        $this->switcher->switchToNew();
        return new NoOp();  // or StatusUpdate
    }
}
```

#### `src/Tui/Command/ResumeSessionCommand.php`
```php
class ResumeSessionCommand implements SlashCommandHandler {
    public function __construct(
        private SessionSwitchService $switcher,
        private HatfieldSessionStore $sessionStore,
    ) {}
    public function handle(SlashCommand $cmd): CommandResult {
        if ('' === $cmd->args) {
            // Open picker overlay → onSelect calls switcher->switchToResume(id)
            $this->picker->open($cmd);
            return new NoOp();
        }
        $sessionId = trim($cmd->args);
        if (!$this->sessionStore->exists($sessionId)) {
            return new TranscriptMessage('Session not found: '.$sessionId);
        }
        $this->switcher->switchToResume($sessionId);
        return new NoOp();
    }
}
```

#### `src/Tui/Picker/SessionPickerController.php` (optional, or inline in ResumeSessionCommand)
- Uses `PickerOverlay` + `SelectListWidget`
- Items from `HatfieldSessionStore::listSessions()`
- `onSelect()` calls `switcher->switchToResume($selectedSessionId)`
- `onCancel()` closes picker, returns `NoOp`

### Registration in InteractiveMode
Like `ModelControlListener`, add registration in `InteractiveMode::run()` or in a new listener:
```php
$registry->register(
    new CommandMetadata(name: 'new', description: 'Start a new session'),
    new NewSessionCommand($switcher),
);
$registry->register(
    new CommandMetadata(name: 'resume', aliases: ['r'], description: 'Resume a session (/resume <id> or picker)'),
    new ResumeSessionCommand($switcher, $sessionStore, ...),
);
```

## Known pitfalls
- `/new` must not create an orphan DB session row if the user never types a message. Lazy creation means deferring `HatfieldSessionStore::createSession()` until first `SubmitListener` submits a normal prompt.
- `/resume` picker must refresh session list from DB each time it opens (sessions may be renamed between opens).
- The active `AgentSessionClient` run (`state->handle`) must be cancelled before switching. If cancellation is in progress, either wait and retry or force-close.
- Session switch must also cancel/close any open `QuestionCoordinator` overlay — the user may be answering a HITL question when they type `/resume`.
- `TuiSessionState::$resuming` must be set correctly so `buildInitialTranscript()` replays events (after RTVS-08A).
- No backward-compatibility to old `transcript.jsonl` after RTVS-08A removes it.
- Runtime/TUI changes require full `castor check` before CODE-REVIEW.

## Dependencies
- SESSION-01 for session list/name metadata.
- SESSION-02 for safe in-process TUI session switching/reset.
- RTVS-08 final resume integration for reliable canonical-event replay and state recovery.

## Out of scope
- Tab completion insertion semantics (covered after EDITOR-08 by a later task).
- `/rename`.
- `/tree` and branch navigation.

## Acceptance criteria
- `/resume` is registered in the slash command registry with help/usage metadata and supports both picker mode and direct `/resume <session id>` execution.
- The resume picker uses the existing TUI list/picker patterns (`SelectListWidget`/overlay) and displays at least session id and session name/display fallback.
- Picker Enter executes the resume; Escape cancels without changing the active session.
- Direct `/resume <session id>` validates session existence and shows a clear transcript/status error for invalid IDs.
- A successful resume switches the running TUI to the target session, replays transcript/history without duplicate blocks/deltas, updates footer/session display, and continues polling from the correct dedup cursor.
- `/new` resets the running TUI to a fresh draft/session state and clears old transcript/activity without leaking old runtime events or questions.
- First message in a fresh `/new` state starts a new run/session correctly; empty `/new` does not create useless orphan session records if lazy creation is feasible.
- Tests cover direct resume, picker selection/cancel, invalid resume id, and `/new` reset/start behavior.
- Docs/help text document `/new`, `/resume`, and examples.
- Validation uses Castor per project rules; runtime/TUI changes require full `castor check` before CODE-REVIEW.

## Workflow metadata
Status: IN-PROGRESS
Branch: task/session-03-new-and-resume-commands
Worktree: /home/ineersa/projects/agent-core-worktrees/session-03-new-and-resume-commands
Fork run: r86j2r41s76w
PR URL: https://github.com/ineersa/agent-core/pull/111
PR Status: open
Started: 2026-06-09T01:24:48.923Z
Completed:

## Work log
- Created: 2026-06-07T20:45:37.774Z

## Task workflow update - 2026-06-09T01:24:48.923Z
- Moved TODO → IN-PROGRESS.
- Created branch task/session-03-new-and-resume-commands.
- Created worktree /home/ineersa/projects/agent-core-worktrees/session-03-new-and-resume-commands.
- Copied vendor directory into /home/ineersa/projects/agent-core-worktrees/session-03-new-and-resume-commands.
- Copied .vera index into /home/ineersa/projects/agent-core-worktrees/session-03-new-and-resume-commands.
- Summary: Starting SESSION-03 task-start phase for `/new` and `/resume` TUI session commands. Dependencies SESSION-01, SESSION-02, and RTVS-08 are complete. Next steps: run focused scouts for slash command/picker/session-switch context, then launch one implementation fork in the task worktree. Per workflow, no PR/reviewer/gate step in this phase.

## Task workflow update - 2026-06-09T01:29:10.446Z
- Summary: SESSION-03 scout context gathered. Scout A found slash command/picker implementation pattern: add a `TuiListenerRegistrar` similar to `ModelControlListener`, register `/new` and `/resume`, use `NoOp` because `TuiSessionSwitchService::request*()` calls `Tui::stop()` and `DispatchRuntime` is not wired. Picker should follow `ModelPickerController` + `PickerOverlay` + `SelectListWidget`, with runtime refs set during registrar `register()`. Scout B confirmed SESSION-01/02 APIs: `HatfieldSessionStore::listSessions()/exists()`, `TuiSessionSwitchServiceInterface::requestResume()/requestNewDraft()`, `SessionInitializer::initializeDraft()`, SubmitListener lazy draft promotion, replay/dedup on resume, footer update on draft promotion. Scout C mapped tests/docs: new command handler tests, session picker tests, command registration/help/completion tests as practical, docs updates in `docs/tui-architecture.md` and `docs/session-storage.md`; no settings changes. Key boundary: handlers that need `HatfieldSessionStore` should live under `src/Tui/Listener/` or similar, not `src/Tui/Command/`, to satisfy Deptrac.
- task-start: launched 3 scout subagents for SESSION-03 command/picker patterns, session switch/lazy draft APIs, and tests/docs impact. Full scout output saved at `/home/ineersa/.pi/agent/tmp/2026-06--050c84a3.txt`. Preparing implementation fork.

## Task workflow update - 2026-06-09T01:30:22.944Z
- Recorded fork run: ru72g2pgqzp6
- Summary: Launched SESSION-03 implementation fork `ru72g2pgqzp6` in worktree `/home/ineersa/projects/agent-core-worktrees/session-03-new-and-resume-commands`. Scope: add `/new` and `/resume` slash commands via a `TuiListenerRegistrar`, implement command handlers under `src/Tui/Listener/`, implement `SessionPickerController` using `PickerOverlay`/`SelectListWidget`, wire lazy `/new` through `TuiSessionSwitchServiceInterface::requestNewDraft()`, direct/picker resume through `requestResume()`, update docs/help/tests, and run Castor validation subset. Fork instructed not to push/create PR/move task.
- task-start: implementation fork ru72g2pgqzp6 launched with exact command/picker/session-switch instructions and validation requirements. Awaiting fork report; main agent will not proceed to review/PR in this phase.

## Task workflow update - 2026-06-09T01:48:22.974Z
- Recorded fork run: ru72g2pgqzp6
- Validation: Verified worktree `/home/ineersa/projects/agent-core-worktrees/session-03-new-and-resume-commands` clean at HEAD `428f29b6`.; Verified diff vs origin/main: `depfile.yaml`, `docs/session-storage.md`, `docs/tui-architecture.md`, 4 new source files, 3 new test files; 10 files changed, +807 insertions.; Fork validation report: `castor test --filter=NewSessionCommandHandler` PASS — 2 tests, 3 assertions.; Fork validation report: `castor test --filter=ResumeSessionCommandHandler` PASS — 3 tests, 6 assertions.; Fork validation report: `castor test --filter=SessionPickerController` PASS — 7 tests, 14 assertions.; Fork validation report: `castor test` PASS — 2207 tests, 6436 assertions, 0 errors/failures/skipped.; Fork validation report: `castor deptrac` PASS — 0 violations, 0 errors, uncovered=758, allowed=1013.; Fork validation report: `castor phpstan` PASS — 0 errors, 0 file errors.; Verified current HEAD with rerun: `castor cs-check` PASS — files_fixed=0.; Fork validation report: `castor test:tui` PASS — 5 tests, 18 assertions, 0 errors/failures.; Integration checkout `/home/ineersa/projects/agent-core` verified clean/synced with origin/main after fork report.
- Summary: SESSION-03 implementation fork completed at commit `428f29b6` (`SESSION-03: Add new and resume commands`) on branch `task/session-03-new-and-resume-commands`. Verified worktree clean and expected diff: 10 files changed, +807 lines. Added `/new` and `/resume` command support via new `SessionCommandRegistrar`, `NewSessionCommandHandler`, `ResumeSessionCommandHandler`, and `SessionPickerController`; updated docs and Deptrac; added focused handler/picker tests. `/new` calls `TuiSessionSwitchServiceInterface::requestNewDraft()` and keeps lazy session creation. `/resume <id>` validates `HatfieldSessionStore::exists()` before switching; `/resume` opens a fresh session picker from `listSessions()`. Main checkout was verified clean after fork's reported accidental initial work on main; no uncommitted integration changes remain. Per task-start workflow, no review/PR/push/gate step was run by the main agent.
- task-start: fork ru72g2pgqzp6 implementation verified and recorded. STOPPING here per workflow; task remains IN-PROGRESS until user runs task-to-pr.

## Task workflow update - 2026-06-09T02:07:34.465Z
- Validation: Reviewer verdict: REQUEST CHANGES at HEAD `428f29b6`.; Reviewer confirmed core logic otherwise correct: lazy draft semantics, picker lifecycle, session validation/switch service integration, docs, and Deptrac boundaries are sound.
- Summary: Reviewer returned REQUEST CHANGES for HEAD `428f29b6`. Required/actionable findings: (1) `ResumeSessionCommandHandler` returns `TranscriptMessage(..., 'system', 'error')` for invalid sessions, but transcript rendering ignores style meta and uses role/kind, so it must use role `error` for real error styling. (2) `SessionPickerController::findItemIndex()` is dead code copied from model picker; remove method and tests. Sensible additional fixes: validate `/resume` args as numeric-only so `/resume 42 extra` is a clear malformed-session error instead of relying on store casts; document why `SessionPickerController::setRuntimeRefs()` accepts `TuiSessionState` even though currently unused; add lightweight `SessionCommandRegistrarTest` for `/new` and `/resume` metadata/alias/idempotent registration.
- task-to-pr: reviewer found error-role rendering bug and dead code, plus reasonable coverage/validation improvements; launching fix fork.

## Task workflow update - 2026-06-09T02:24:29.597Z
- Validation: Reviewer verdict: APPROVE WITH SUGGESTIONS at HEAD `0d49c4b0`.; Reviewer verified prior fixes: error role, dead findItemIndex removal, malformed id validation, state ref docblock, registrar test coverage.
- Summary: Re-review at HEAD `0d49c4b0` returned APPROVE WITH SUGGESTIONS. Previous REQUEST CHANGES are confirmed fixed. Remaining sensible actionable cleanups: docs/tui-architecture.md incorrectly says invalid `/resume` returns a muted error message though code now returns an error block; `TranscriptMessage` docblock omits supported `error` role (pre-existing but now relevant to this PR); `SessionCommandRegistrarTest::buildContextAndPicker()` returns unused values; optional edge test for `/resume 0` would clarify non-obvious behavior. Launching narrow fork for these cleanup items before final approval.
- task-to-pr: re-review confirmed required changes resolved; launching small cleanup fork for remaining actionable suggestions before final approval.

## Task workflow update - 2026-06-09T15:27:00.013Z
- Recorded fork run: ft7c25fezpyf
- Validation: Final reviewer verdict at HEAD `5ea4f71c`: APPROVED — no actionable issues remain.; Local validation: `castor test` PASS — tests=2212, assertions=6462, errors=0, failures=0, skipped=0.; Local validation: `castor deptrac` PASS — violations=0, errors=0, uncovered=758, allowed=1014.; Local validation: `castor phpstan` PASS — errors=0, file_errors=0.; Local validation: `castor cs-check` PASS — files_fixed=0.
- Summary: SESSION-03 ready for CODE-REVIEW at HEAD `5ea4f71c` on branch `task/session-03-new-and-resume-commands`. Final reviewer returned APPROVED with no actionable issues after polish commit. Cumulative diff verified: 12 files changed (+1022/-1) vs origin/main, adding `/new`, direct `/resume <session id>`, `/resume` picker, docs, tests, and Deptrac allowance. Review-fix commits: `0d49c4b0` fixed invalid-session error rendering, malformed-ID validation, removed dead picker method/tests, documented state runtime ref, and added registrar tests; `5ea4f71c` fixed final docs/docblock/test cleanup and added `/resume 0` edge test.
- task-to-pr: final reviewer approved HEAD 5ea4f71c; local Castor validation passed; proceeding to move_task CODE-REVIEW for full Castor quality gate and PR creation.
Castor Check Status: passed
Castor Check Commit: 5ea4f71c3c8d14a244d15347cf3d2bf90ec7d301
Castor Check Command: LLM_MODE=true castor check
Castor Check Timeout: 900s
Castor Check Completed: 2026-06-09T15:29:42.650Z
Castor Check Output SHA256: 507c25ed333065ce5e4d285e4a3ed0e961e812feefd1fd2aa0ccc81d5b3e3aa2

## Task workflow update - 2026-06-09T15:29:46.700Z
- Moved IN-PROGRESS → CODE-REVIEW.
- Castor quality gate passed (900s timeout). Commit: 5ea4f71c3c8d.
- Pushed task/session-03-new-and-resume-commands to origin.
- branch 'task/session-03-new-and-resume-commands' set up to track 'origin/task/session-03-new-and-resume-commands'.
- Created PR: https://github.com/ineersa/agent-core/pull/111

## Task workflow update - 2026-06-09T15:34:31.179Z
- Moved CODE-REVIEW → IN-PROGRESS.
- Summary: Review iteration requested by user after PR #111/manual TUI inspection. Issues to address: session picker rows use SelectListWidget descriptions causing label column clamp to 30 chars; combine `#id - name` into a single label with no description key for full-width rendering. TUI switch via `/new` and `/resume` leaves old TUI rendering above new TUI in snapshot, so session switches must properly clear/re-render the whole screen. Also align session picker selected-row styling with common widgets/completions theme colors such as accent selection.

## Task workflow update - 2026-06-09T15:35:20.948Z
- Recorded fork run: yh7poaiaod4u
- Summary: Launched review-iteration fork `yh7poaiaod4u` to address user/manual TUI feedback after PR #111: (1) change session picker rows to full-width single-column labels like `#id - name` with no `description` key because Symfony `SelectListWidget` clamps label column when description is present, (2) fix `/new` and `/resume` session switch rendering so old TUI content is cleared/re-rendered instead of leaving stacked screens, (3) align session picker selected-row styling with common completion/picker/theme accent behavior, with focused tests and `castor test:tui` validation required.
- task-review-iterate: implementation fork yh7poaiaod4u launched in worktree `/home/ineersa/projects/agent-core-worktrees/session-03-new-and-resume-commands` for SelectListWidget single-column rows, full-screen switch rerender, and picker styling polish.

## Task workflow update - 2026-06-09T15:48:15.927Z
- Recorded fork run: z7poew0kw610
- Validation: Partial fork yh7poaiaod4u validation before abort: `castor test` PASS — tests=2213, assertions=6466, errors=0, failures=0, skipped=0.; Partial fork yh7poaiaod4u validation before abort: `castor deptrac` PASS — violations=0, errors=0, uncovered=759, allowed=1016.; Partial fork yh7poaiaod4u validation before abort: `castor phpstan` PASS — errors=0, file_errors=0.; Partial fork yh7poaiaod4u validation before abort: `castor cs-check` FAIL — files_fixed=1; `castor test:tui` not reached.
- Summary: Prior review-iteration fork `yh7poaiaod4u` died mid-run and left uncommitted changes in `InteractiveMode.php`, `SessionPickerController.php`, and `SessionPickerControllerTest.php`. Main agent inspected the dirty diff and began validation: `castor test` passed (2213 tests, 6466 assertions), `castor deptrac` passed (0 violations), `castor phpstan` passed, but `castor cs-check` failed with files_fixed=1 before `castor test:tui` could run. Per user direction, launched replacement fork `z7poew0kw610` with explicit first step to restore/discard the partial uncommitted changes back to clean HEAD `5ea4f71c`, then re-implement the SelectListWidget single-column rows, full-screen switch clear/re-render, and picker styling polish cleanly, with full validation including required `castor test:tui`.
- task-review-iterate: partial fork yh7poaiaod4u was abandoned; replacement fork z7poew0kw610 launched to restore worktree and re-implement cleanly.

## Task workflow update - 2026-06-09T16:09:08.277Z
- Summary: User smoke-tested latest SESSION-03 review iteration and found a runtime startup regression after `/new`: TUI shows fresh draft welcome text, then on first run reports `Runtime error: Controller did not emit runtime.ready within 15s` with empty controller stderr/stdout buffer. Need inspect logs/artifacts, reproduce if feasible, fix root cause via fork, and include sensible reviewer suggestions from the c9d3f5de review: deduplicate SessionPickerController selection label rebuild through buildItemsStatic(), add a comment explaining `$needsTerminalClear` intentionally remains true for later switch iterations, and optionally add selectedIndex=1 coverage.
- task-review-iterate: user reported post-/new runtime.ready timeout during smoke testing; launching fork to investigate logs and fix.

## Task workflow update - 2026-06-09T16:09:47.714Z
- Recorded fork run: p8kx4mfhqmug
- Summary: Launched fork `p8kx4mfhqmug` to investigate and fix the user-reported `/new` runtime startup regression (`Controller did not emit runtime.ready within 15s`, empty stderr/stdout_buffer). Fork instructed to inspect worktree `.hatfield` logs/tmp/snapshots/sessions, validate/falsify hypotheses around `InteractiveMode` ANSI clear, draft promotion request cwd/options/session IDs, and `JsonlProcessAgentSessionClient` process restart state after switch. Also instructed to incorporate sensible reviewer suggestions from `c9d3f5de`: deduplicate session picker selection rebuild through `buildItemsStatic()`, add comment for intentional `$needsTerminalClear` behavior, and add selectedIndex=1 coverage.
- task-review-iterate: fork p8kx4mfhqmug launched for `/new` runtime.ready startup failure and reviewer polish follow-ups.

## Task workflow update - 2026-06-09T16:39:28.361Z
- Validation: Reviewer verdict at HEAD `2b488a84`: APPROVE WITH SUGGESTIONS.; Reviewer confirmed no critical issues and root-cause fix is sound; suggestions are polish/edge-case hardening.
- Summary: Reviewer returned APPROVE WITH SUGGESTIONS for HEAD `2b488a84`: confirmed `/new` runtime.ready root causes are fixed (`JsonlProcessAgentSessionClient` session-scoped queue process restart and `SubmitListener` nullsafe draft promotion). Sensible actionable follow-ups: make `resume()` wait for `runtime.ready` when `ensureProcessRunning()` spawned/restarted a process (avoid fire-and-forget command to unready controller), avoid first-call no-op `stopProcess()` by only stopping when a process was actually spawned for a previous session (`processSessionId !== null`), and reword `$needsTerminalClear` comment in `InteractiveMode` from 'Emitted once' to clearer flag wording. Launching narrow fork for these items.
- task-review-iterate: reviewer approved runtime fix with suggestions; launching narrow hardening fork before final gate.

## Task workflow update - 2026-06-09T16:55:46.435Z
- Validation: Reviewer verdict at HEAD `67c12e21`: REQUEST CHANGES.; Reviewer found no critical/security issues; root commands and lifecycle mostly sound except stale autoResumed bug.
- Summary: Final reviewer returned REQUEST CHANGES at HEAD `67c12e21`. Remaining bug: `JsonlProcessAgentSessionClient::resume()` does not reset stale `$autoResumed`; if `events()`/`send()` crash-recovers and sets `autoResumed=true` for one session, then user `/resume`s another session, the session-change restart path spawns a fresh process but `resume()` returns early because the stale flag is still true, skipping the resume command for the new session. Required fix: set `$this->autoResumed = false` at the top of `resume()` before `ensureProcessRunning()`, mirroring `start()`, with explanatory comment. Sensible doc cleanup: docs/tui-architecture.md session picker text still says muted `#sessionId` suffix, but implementation now uses full-width `#id — displayTitle` labels with no description key and accent selected row.
- task-review-iterate: launching narrow fork to fix stale autoResumed flag in resume() and update stale session picker docs.

## Task workflow update - 2026-06-09T17:09:46.470Z
- Recorded fork run: r86j2r41s76w
- Validation: Final reviewer verdict at HEAD `c0ece70b`: APPROVED — no actionable issues remain.; Local validation: `castor test` PASS — tests=2214, assertions=6469, errors=0, failures=0, skipped=0.; Local validation: `castor deptrac` PASS — violations=0, errors=0, uncovered=759, allowed=1015.; Local validation: `castor phpstan` PASS — errors=0, file_errors=0.; Local validation: `castor cs-check` PASS — files_fixed=0.; Local validation: `castor test:tui` PASS — tests=5, assertions=18, errors=0, failures=0, skipped=0.
- Summary: SESSION-03 review iteration ready for CODE-REVIEW again at HEAD `c0ece70b`. Final reviewer returned APPROVED with no actionable issues after stale `$autoResumed` reset and docs update. Review-iteration commits since PR #111 initial approval: `c9d3f5de` fixed session picker full-width rendering/no description key, selected-row accent styling, and terminal clear on session switch; `2b488a84` fixed `/new` runtime startup by restarting process transport on session changes and nullsafe draft promotion; `67c12e21` hardened process resume by waiting for `runtime.ready` after session-switch process restarts and avoiding first-call no-op stop; `c0ece70b` reset stale `autoResumed` at top of `resume()` and updated picker docs. Cumulative diff vs origin/main verified: 15 files changed, +1173/-3.
- task-review-iterate: final reviewer approved HEAD c0ece70b; local validation including TUI E2E passed; proceeding to move_task CODE-REVIEW for full Castor gate and PR update.
