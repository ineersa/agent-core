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
Fork run: ru72g2pgqzp6
PR URL:
PR Status:
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
