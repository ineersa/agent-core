# EDITOR-08 Completion foundation and slash command completion

## Goal
Plan: .pi/plans/editor_rollout_plan.md

MVP: no.

Scope:
- Add CompletionProvider, CompletionSuggestion, and CompletionState.
- Add completion menu rendering in/near the editor.
- Add SlashCommandCompletionProvider backed by the slash command registry metadata.
- Implement Tab behavior: accept selected item when menu is open; trigger slash completion when slash context is detected.
- Escape closes completion without clearing editor text.

Exclusions:
- No file mention completion; EDITOR-09 owns @ provider.
- No configurable keybindings.
- No command execution changes beyond existing registry metadata.

Dependencies: EDITOR-03, EDITOR-04.
Parallelizable with: EDITOR-06, EDITOR-07.

## Acceptance criteria
- Completion provider interface and state are unit-tested.
- Slash command suggestions appear for slash context at start of editor text or after newline at column 0.
- Tab accepts selected slash command suggestion.
- Escape closes completion state.
- Completion rendering stays separate from command execution.
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
- Created: 2026-05-18T00:15:55.603Z
