# EDITOR-01 Pure prompt editor state and operations

## Goal
Plan: .pi/plans/editor_rollout_plan.md

MVP: yes.

Scope:
- Add owned editor state/model under src/Tui/Editor/.
- Implement logical lines, cursor line/column, getText/setText, insertText, delete before/after cursor, clear, isEmpty, submit text extraction.
- Implement cursor left/right/home/end and newline insertion via method.
- Keep logic pure/unit-testable; no Symfony TUI widget integration yet.

Exclusions:
- No PromptEditorWidget integration; EDITOR-02 owns that.
- No command parser or slash commands; EDITOR-03/04 own that.
- No viewport wrapping/scrolling beyond minimal state fields.
- No prompt history, completion, paste, or configurable keybindings.

Dependencies: none.
Parallelizable with: EDITOR-03.

## Acceptance criteria
- Editor state stores text as logical lines split by newline.
- Cursor operations and insert/delete/newline behavior are covered by unit tests.
- submit/clear/isEmpty behavior is deterministic and independent of Symfony TUI.
- No AgentCore dependency is introduced into src/Tui/.
- castor test --filter Editor passes for the new tests.
- castor deptrac passes.

## Workflow metadata
Status: IN-PROGRESS
Branch: task/editor-01-pure-prompt-editor-state
Worktree: /home/ineersa/projects/agent-core-worktrees/editor-01-pure-prompt-editor-state
Fork run:
PR URL:
PR Status:
Started: 2026-05-18T01:40:46.601Z
Completed:

## Work log
- Created: 2026-05-18T00:15:06.351Z

## Task workflow update - 2026-05-18T01:40:46.601Z
- Moved TODO → IN-PROGRESS.
- Created branch task/editor-01-pure-prompt-editor-state.
- Created worktree /home/ineersa/projects/agent-core-worktrees/editor-01-pure-prompt-editor-state.
- Copied vendor directory into /home/ineersa/projects/agent-core-worktrees/editor-01-pure-prompt-editor-state.
- Copied .vera index into /home/ineersa/projects/agent-core-worktrees/editor-01-pure-prompt-editor-state.
