# SESSION-04 /rename command and session argument completions

## Goal
Add session rename UX and completion support for session-taking slash commands.

## Desired UX
- `/rename` opens a recent-session picker near the bottom/editor area.
- Selecting a session inserts a generated command into the editor, e.g. `/rename <session id> `, so the user can type the new name.
- `/rename <session id> <new name>` executes the rename.
- If no name is provided, show an error with a concrete command hint using the selected/provided real session id.
- For all completion flows, Tab inserts a completion into the editor while Enter executes the command/picker selection.

## Current code facts

### Key difference from `/resume` picker
`/resume` picker executes on selection. `/rename` picker must **insert** a partial command into the editor and return focus. This requires:
1. Picker `onSelect()` → construct string like `/rename <sessionId> ` → insert into editor → close picker
2. Editor access via `ChatScreen::getEditor()` → `editor->insertText(string)` or equivalent

### Editor API (from `src/Tui/Editor/PromptEditor.php` + `PromptEditorWidget.php`)
- `extract(): EditorState` — returns current text snapshot
- `clear(): void` — resets editor content
- Editor can be accessed via `ChatScreen::getEditor()` which is returned by `InteractiveMode::run()` but not currently exposed via `TuiRuntimeContext`
- **May need** to expose editor or add an `insertText()` method to `ChatScreen` or `TuiRuntimeContext` for use by command handlers.

### Tab completion (EDITOR-08, NOT YET implemented)
- Task `tasks/TODO/editor-08-completion-foundation-slash.md`:
  - `CompletionProvider` interface, `CompletionSuggestion`, `CompletionState`
  - Completion menu rendering near editor
  - `SlashCommandCompletionProvider` backed by registry metadata
  - Tab: accept selected or trigger slash completion
  - Escape: close completion without clearing text
- Bot commands like `/resume <sessionId>` and `/rename <sessionId>` will need a `SessionIdCompletionProvider` that calls `HatfieldSessionStore::listSessions()` for suggestions.

### Session metadata update path
- `HatfieldSessionStore::updateMetadata(string $sessionId, array $meta): void` — merges known keys
- After SESSION-01 adds `name` support, rename is simply:
  ```php
  $this->sessionStore->updateMetadata($sessionId, ['name' => $newName]);
  ```

## Implementation seams

### New file: `src/Tui/Command/RenameSessionCommand.php`
```php
class RenameSessionCommand implements SlashCommandHandler {
    public function __construct(
        private HatfieldSessionStore $sessionStore,
        private ChatScreen $screen,
    ) {}

    public function handle(SlashCommand $cmd): CommandResult {
        // Parse args: sessionId + newName
        $args = preg_split('/\s+/', trim($cmd->args), 2);
        $sessionId = $args[0] ?? '';
        $newName = $args[1] ?? '';

        if ('' === $sessionId) {
            // Open picker → onSelect inserts /rename <id>  into editor
            $this->openRenamePicker();
            return new NoOp();
        }

        if ('' === $newName) {
            return new TranscriptMessage(
                'Provide a name. Example: `/rename '.$sessionId.' My session`'
            );
        }

        if (!$this->sessionStore->exists($sessionId)) {
            return new TranscriptMessage('Session not found: '.$sessionId);
        }

        $this->sessionStore->updateMetadata($sessionId, ['name' => $newName]);
        return new TranscriptMessage('Session '.$sessionId.' renamed to "'.$newName.'"');
    }
}
```

### Picker insertion (picker `onSelect`)
```php
$listWidget->onSelect(function (SelectEvent $e) use ($editor, $overlay): void {
    $sessionId = $e->item->value;
    $editor->clear();
    $editor->insertText('/rename '.$sessionId.' ');
    $overlay->close();
    // Focus returns to editor naturally
});
```

### Completion provider (after EDITOR-08)
```php
class SessionIdCompletionProvider implements CompletionProvider {
    public function __construct(private HatfieldSessionStore $sessionStore) {}
    public function getSuggestions(string $prefix): array {
        return array_map(
            fn(array $s) => new CompletionSuggestion(
                value: $s['sessionId'],
                display: $s['displayTitle'],
                insert: $s['sessionId'].' ',
            ),
            $this->sessionStore->listSessions(limit: 30)
        );
    }
}
```

## Known pitfalls
- Picker insertion requires editor access from the command handler. If `TuiRuntimeContext` doesn't expose the editor, either:
  - Add `ChatScreen::insertText(string): void`
  - Or expose `PromptEditor` through `TuiRuntimeContext`
- Command parsing: `/rename` args must split on first space to separate sessionId (which never contains spaces, it's numeric) from the rest (which may contain spaces). Use `explode(' ', ..., 2)` or `preg_split('/\s+/', ..., 2)`.
- `/rename <sessionId> <new name>` where sessionId is quoted/special: not needed since IDs are numeric strings.
- Completion for session IDs depends on EDITOR-08. If that task is delayed, `/rename` direct command and picker-insertion can still ship without tab completion.
- Display fallback for unnamed sessions must not be persisted (keep `name` null in DB).
- No backward-compatibility shims for old sessions without name field.

## Dependencies
- SESSION-01 for name metadata/listing.
- SESSION-03 for session picker command patterns.
- EDITOR-08 completion foundation and slash command completion before implementing Tab insertion/completion semantics.

## Out of scope
- Switching sessions (`/resume`, `/new`).
- Tree navigation.
- Renaming old compatibility session IDs not present in the DB.

## Acceptance criteria
- `/rename` is registered with help/usage metadata and supports `/rename <session id> <new name>` direct execution.
- `/rename` with no args opens a session picker that displays session id and current name/display fallback.
- Selecting from the picker inserts a concrete editable command into the prompt editor rather than executing immediately, leaving the cursor ready for the new name.
- Executing with no new name reports a clear error and includes an actionable example using the real session id, e.g. `/rename 42 My session name`.
- Successful rename updates session metadata and refreshes any visible picker/footer/session display that references that session.
- Session-id completions are available for `/resume` and `/rename` after EDITOR-08; Tab inserts selected completions into the editor and Enter executes.
- Tests cover direct rename, missing-name error hint, picker insertion, completion insertion, and metadata persistence.
- Docs/help text document `/rename`, `/resume <id>`, and completion behavior.
- Validation uses Castor per project rules; runtime/TUI changes require full `castor check` before CODE-REVIEW.

## Workflow metadata
Status: DONE
Branch: task/session-04-rename-command-and-session-completions
Worktree: /home/ineersa/projects/agent-core-worktrees/session-04-rename-command-and-session-completions
Fork run: 00ekz1wt1o7u
PR URL: https://github.com/ineersa/agent-core/pull/116
PR Status: merged
Started: 2026-06-10T22:26:17.564Z
Completed: 2026-06-11T17:15:10.131Z

## Work log
- Created: 2026-06-07T20:45:50.100Z

## Task workflow update - 2026-06-10T22:26:17.564Z
- Moved TODO → IN-PROGRESS.
- Created branch task/session-04-rename-command-and-session-completions.
- Created worktree /home/ineersa/projects/agent-core-worktrees/session-04-rename-command-and-session-completions.
- Copied vendor directory into /home/ineersa/projects/agent-core-worktrees/session-04-rename-command-and-session-completions.
- Copied .vera index into /home/ineersa/projects/agent-core-worktrees/session-04-rename-command-and-session-completions.

## Task workflow update - 2026-06-10T22:31:45.148Z
- Orchestrator claimed task and created worktree: /home/ineersa/projects/agent-core-worktrees/session-04-rename-command-and-session-completions
- Loaded task-workflow/testing/subagents skills. Scout context gathered for slash command architecture, picker/completion APIs, and TUI E2E patterns. Key implementation seams: SessionCommandRegistrar, ResumeSessionCommandHandler, SessionPickerController, PromptEditor::replaceText(), CompletionProviderRegistry/service tags, TuiAgentSmokeTest.

## Task workflow update - 2026-06-10T22:33:58.533Z
- Recorded fork run: n8mcks4b1o1w
- Launched implementation fork n8mcks4b1o1w in worktree /home/ineersa/projects/agent-core-worktrees/session-04-rename-command-and-session-completions with explicit instructions for /rename command, session-id completion provider, docs, unit/integration tests, and mandatory TmuxHarness E2E proof.

## Task workflow update - 2026-06-10T22:58:08.812Z
- Recorded fork run: 6m30iie3bqbi
- Validation: castor test --filter='RenameSessionCommandHandlerTest|SessionIdCompletionProviderTest|SessionCommandRegistrarTest|SessionPickerControllerTest' — PASS (tests=40, assertions=107); castor test:tui --filter=SessionRenameE2ETest — PASS (tests=3, assertions=3); castor phpstan — PASS; castor cs-check — PASS; castor deptrac — FAIL: 1 violation, TuiCompletion SessionIdCompletionProvider depends on AppSession HatfieldSessionStore
- Fork n8mcks4b1o1w completed commit de51ed3e; orchestrator verified focused unit tests and TUI E2E pass, plus phpstan/cs-check pass. Deptrac found a TuiCompletion→AppSession boundary violation in SessionIdCompletionProvider, so branch remains IN-PROGRESS.
- Launched follow-up implementation fork 6m30iie3bqbi to fix the deptrac boundary by introducing a TuiCompletion-owned source interface and an adapter in an allowed layer, then rerun deptrac/phpstan/cs/tests/E2E.

## Task workflow update - 2026-06-10T23:01:41.145Z
- Recorded fork run: 6m30iie3bqbi
- Validation: Fork reported: castor deptrac — PASS (violations=0); Fork reported: castor phpstan — PASS; Fork reported: castor cs-check — PASS after cs-fix; Fork reported: castor test --filter='RenameSessionCommandHandlerTest|SessionIdCompletionProviderTest|SessionCommandRegistrarTest|SessionPickerControllerTest' — PASS (40 tests, 107 assertions); Fork reported: castor test:tui --filter=SessionRenameE2ETest — PASS (3 tests, 3 assertions); Orchestrator verified commit/state: HEAD 0e471031, worktree clean, aggregate diff 15 files changed, 1306 insertions, 94 deletions.
- Summary: Implementation complete in worktree. Commits: de51ed3e adds /rename command, session-id completions, docs, focused tests, and TmuxHarness E2E; 0e471031 fixes deptrac boundary by introducing TuiCompletion-owned SessionCompletionSourceInterface/SessionCompletionRow and a TuiListener adapter wrapping HatfieldSessionStore. Working tree verified clean.

## Task workflow update - 2026-06-11T17:03:34.416Z
- Recorded fork run: 00ekz1wt1o7u
- Launched validation/merge fork 00ekz1wt1o7u per user request: merge latest main, read AGENTS/testing skill/docs, rewrite tests if needed, run focused + full Castor validation gates, and ensure Castor files are not touched (revert if changed).

## Task workflow update - 2026-06-11T17:07:06.281Z
- Recorded fork run: 00ekz1wt1o7u
- Validation: Fork reported: castor test --filter='RenameSessionCommandHandlerTest|SessionIdCompletionProviderTest|SessionCommandRegistrarTest|SessionPickerControllerTest' — PASS (40 tests, 107 assertions); Fork reported: castor test:tui --filter=SessionRenameE2ETest — PASS (3 tests, 3 assertions); real TmuxHarness + llama_cpp_test/test E2E proof covers direct rename, completion insertion, and picker insertion; Fork reported: castor deptrac — PASS (0 violations); Fork reported: castor phpstan — PASS (0 errors); Fork reported: castor cs-check — PASS (0 files fixed); Fork reported: castor check — PASS; 13/13 steps green, including deptrac, seven unit-test shards, controller E2E, llm-real, TUI E2E, phpstan, cs-check; total about 2.5 minutes; Orchestrator verified branch state after fork: HEAD 70034bd2, worktree clean, aggregate task diff 15 files changed, 1306 insertions, 94 deletions.
- Summary: Validation/merge fork completed. Latest main merged cleanly into task branch with merge commit 70034bd2; no conflicts and no SESSION-04 test rewrites needed. Working tree clean. Task commits do not touch Castor; Castor-related changes present only as upstream main baseline from merge.
Castor Check Status: passed
Castor Check Commit: 70034bd2089f8fbe65c5c22529265c14862199c6
Castor Check Command: LLM_MODE=true castor check
Castor Check Timeout: 900s
Castor Check Completed: 2026-06-11T17:08:31.382Z
Castor Check Output SHA256: 7cce3a7510c752e1bc7df9fbd0610682b77727ac3340bfbdf89b7667fcb3cb02

## Task workflow update - 2026-06-11T17:08:35.507Z
- Moved IN-PROGRESS → CODE-REVIEW.
- Castor quality gate passed (900s timeout). Commit: 70034bd2089f.
- Pushed task/session-04-rename-command-and-session-completions to origin.
- branch 'task/session-04-rename-command-and-session-completions' set up to track 'origin/task/session-04-rename-command-and-session-completions'.
- Created PR: https://github.com/ineersa/agent-core/pull/116

## Task workflow update - 2026-06-11T17:15:10.131Z
- Moved CODE-REVIEW → DONE.
- Merged task/session-04-rename-command-and-session-completions into integration checkout.
- Already up to date.
- Removed worktree /home/ineersa/projects/agent-core-worktrees/session-04-rename-command-and-session-completions.
- Pulled integration checkout: Already up to date..
