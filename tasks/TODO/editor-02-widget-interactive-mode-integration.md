# EDITOR-02 PromptEditorWidget adapter and InteractiveMode integration

## Goal
Plan: .pi/plans/editor_rollout_plan.md

MVP: yes.

Scope:
- Add PromptEditorWidget as the Symfony TUI rendering adapter for PromptEditor.
- Replace direct Symfony EditorWidget usage in InteractiveMode/layout wiring.
- Preserve current key behavior: Enter submit, Ctrl+J newline, Ctrl+C clear/cancel input, Ctrl+D exit.
- Render placeholder and cursor reasonably, matching current startup output as closely as practical.

Exclusions:
- No command execution/routing; EDITOR-05 owns submission routing.
- No viewport/growth/scrolling; EDITOR-06 owns that.
- No prompt history, completion, paste, or configurable keybindings.

Dependencies: EDITOR-01.
Parallelizable with: EDITOR-04 after EDITOR-03 is available.

## Acceptance criteria
- InteractiveMode uses the owned PromptEditor/PromptEditorWidget instead of relying directly on Symfony EditorWidget for prompt state.
- Existing visible key behavior is preserved.
- Startup rendering changes are either avoided or covered by updated TUI snapshot fixtures.
- Focused unit tests cover widget/editor interaction where possible.
- If visible rendering changes, castor test:tui or documented snapshot update flow is used.
- castor deptrac passes.

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
