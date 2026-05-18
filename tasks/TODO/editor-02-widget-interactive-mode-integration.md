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
Status: TODO
Branch:
Worktree:
Fork run:
PR URL:
PR Status:
Started:
Completed:

## Work log
- Created: 2026-05-18T00:15:13.842Z
- Updated: 2026-05-18 — Scope simplified: "build widget adapter" → "wire facade into DI". Viewport config folded in from eliminated EDITOR-06.
