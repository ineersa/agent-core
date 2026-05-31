# EDITOR-02 Wire PromptEditor facade into ChatScreen/Listeners via DI

## Goal
Plan: .pi/plans/editor_rollout_plan.md

MVP: yes.

Scope:
- Register `PromptEditor` as a Symfony DI service in `config/services.yaml`.
- Inject `PromptEditor` into `ChatScreen` instead of creating `EditorWidget` inline.
- Wire `ChatScreen::editorWidget()` to return `$this->promptEditor->getWidget()`.
- Wire `ChatScreen::clearEditor()` and `ChatScreen::editorText()` through `PromptEditor`.
- Update `SubmitListener` to use `PromptEditor::extract()` instead of `$event->getValue()` + `$screen->clearEditor()`.
- Update `CancelListener` / `CtrlCInputInterceptor` to use `PromptEditor` instead of `$screen->editorText()` / `$screen->clearEditor()`.
- Configure viewport defaults: `setMinVisibleLines(1)`, `setMaxVisibleLines(10)` during wiring.
- Preserve all current key behavior: Enter submit, Ctrl+J newline, Ctrl+C clear, Ctrl+D exit.

Rationale: `PromptEditor` (EDITOR-01) already wraps `EditorWidget` as a thin facade. This task wires it into the application's DI and listener infrastructure. No new editor functionality is built — just integration.

Exclusions:
- No command execution/routing; EDITOR-05 owns submission routing.
- No prompt history, completion, paste, or configurable keybindings.
- No viewport logic (Symfony TUI handles this via EditorViewport/EditorRenderer).

Dependencies: EDITOR-01.
Parallelizable with: EDITOR-04.

## Acceptance criteria
- `PromptEditor` is registered in `services.yaml` and autowired into `ChatScreen`/`InteractiveMode`.
- `ChatScreen` no longer creates `EditorWidget` directly — receives `PromptEditor` via DI.
- All editor interactions go through `PromptEditor` facade, not raw `EditorWidget`.
- Existing visible key behavior is preserved.
- Startup rendering unchanged or covered by updated snapshots.
- `castor test` and `castor deptrac` pass.

## Workflow metadata
Status: DONE
Branch: task/editor-02-widget-interactive-mode-integration
Worktree: /home/ineersa/projects/agent-core-worktrees/editor-02-widget-interactive-mode-integration
Fork run: 8vr7qov6xcz9
PR URL: https://github.com/ineersa/agent-core/pull/24
PR Status: merged
Started: 2026-05-18T17:10:13.499Z
Completed: 2026-05-18T17:28:17.388Z

## Work log
- Created: 2026-05-18T00:15:13.842Z
- Updated: 2026-05-18 — Scope simplified: "build widget adapter" → "wire facade into DI". Viewport config folded in from eliminated EDITOR-06.

## Task workflow update - 2026-05-18T17:10:13.499Z
- Moved TODO → IN-PROGRESS.
- Created branch task/editor-02-widget-interactive-mode-integration.
- Created worktree /home/ineersa/projects/agent-core-worktrees/editor-02-widget-interactive-mode-integration.
- Copied vendor directory into /home/ineersa/projects/agent-core-worktrees/editor-02-widget-interactive-mode-integration.
- Copied .vera index into /home/ineersa/projects/agent-core-worktrees/editor-02-widget-interactive-mode-integration.

## Task workflow update - 2026-05-18T17:11:25.718Z
- Recorded fork run: 8vr7qov6xcz9
- Summary: Launched background fork to implement EDITOR-02 in worktree /home/ineersa/projects/agent-core-worktrees/editor-02-widget-interactive-mode-integration.

## Task workflow update - 2026-05-18T17:23:07.468Z
- Recorded fork run: 8vr7qov6xcz9
- Validation: castor test — 419/419 pass, 8246 assertions, 1 pre-existing notice; castor test --filter="PromptEditor" — 23/23 pass, 41 assertions; castor deptrac — 0 violations; castor phpstan — 0 errors; castor cs-check — fails due pre-existing src/Tui/Command/* style violations from EDITOR-03, none in changed files
- Summary: EDITOR-02 fork completed. Implemented PromptEditor DI wiring into InteractiveMode/ChatScreen, delegated editor access/clear/extract through PromptEditor, configured viewport defaults, and updated SubmitListener to use ChatScreen::extract(). Commit 10cc198f. Fork noted castor test/deptrac/phpstan passed; cs-check has pre-existing violations in src/Tui/Command/* unrelated to changed files.

## Task workflow update - 2026-05-18T17:23:37.108Z
- Validation: ~/.local/bin/castor test:tui in EDITOR-02 worktree — fails 2/2 due startup footer snapshot expecting old 'hatfield · session...' while actual footer shows model/status/cwd; reproduced the same failure on main, so not introduced by EDITOR-02
- Summary: Parent ran TUI e2e after fork completion. The snapshot failure is reproducible on main and appears pre-existing/unrelated to EDITOR-02.

## Task workflow update - 2026-05-18T17:23:53.445Z
- Moved IN-PROGRESS → CODE-REVIEW.
- Pushed task/editor-02-widget-interactive-mode-integration to origin.
- branch 'task/editor-02-widget-interactive-mode-integration' set up to track 'origin/task/editor-02-widget-interactive-mode-integration'.
- Created PR: https://github.com/ineersa/agent-core/pull/24
- Validation: castor test — 419/419 pass, 8246 assertions, 1 pre-existing notice; castor test --filter="PromptEditor" — 23/23 pass, 41 assertions; castor deptrac — 0 violations; castor phpstan — 0 errors; castor cs-check — fails due pre-existing src/Tui/Command/* style violations unrelated to changed files; ~/.local/bin/castor test:tui — fails 2/2 due pre-existing startup footer snapshot mismatch reproduced on main
- Summary: EDITOR-02 completed in fork 8vr7qov6xcz9. PromptEditor is now injected into InteractiveMode and passed into ChatScreen, ChatScreen delegates editorWidget/editorText/clear/extract through PromptEditor, viewport defaults are configured via PromptEditor, and SubmitListener uses ChatScreen::extract(). No new editor behavior or command routing added.

## Task workflow update - 2026-05-18T17:28:17.388Z
- Moved CODE-REVIEW → DONE.
- Merged task/editor-02-widget-interactive-mode-integration into integration checkout.
- Merge made by the 'ort' strategy.
 src/Tui/Application/InteractiveMode.php |  4 +++-
 src/Tui/Listener/SubmitListener.php     |  5 +----
 src/Tui/Screen/ChatScreen.php           | 38 +++++++++++++++++++++++----------
 3 files changed, 31 insertions(+), 16 deletions(-)
- Removed worktree /home/ineersa/projects/agent-core-worktrees/editor-02-widget-interactive-mode-integration.
- Pulled integration checkout: Merge made by the 'ort' strategy..
- Validation: PR #24 merged: https://github.com/ineersa/agent-core/pull/24
- Summary: PR #24 merged by user. EDITOR-02 complete: PromptEditor facade wired through DI into InteractiveMode/ChatScreen and SubmitListener uses ChatScreen::extract().
