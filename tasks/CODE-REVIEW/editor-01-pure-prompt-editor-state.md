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
Status: CODE-REVIEW
Branch: task/editor-01-pure-prompt-editor-state
Worktree: /home/ineersa/projects/agent-core-worktrees/editor-01-pure-prompt-editor-state
Fork run: 3wo0vifc6apo
PR URL: https://github.com/ineersa/agent-core/pull/23
PR Status: open
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

## Task workflow update - 2026-05-18T02:02:14.103Z
- Recorded fork run: vyosiw4v4fra
- Validation: phpunit --filter=Editor: 82 tests, 206 assertions — all pass; deptrac: 0 violations, 0 errors; php-cs-fixer: 0 violations
- Summary: EDITOR-01 complete. Created pure editor state model under src/Tui/Editor/:
- EditorState (immutable readonly value object: lines, cursorLine, cursorColumn, scrollOffset with invariant validation)
- PromptEditor (stateful facade: insertText, deleteBackward/Forward, insertNewline, cursor movement, clear, isEmpty, submit)
- 62 new unit tests (EditorStateTest: 17, PromptEditorTextEditingTest: 22, PromptEditorCursorTest: 23)
- All 82 tests passing (--filter=Editor), deptrac clean (0 violations), CS clean

## Task workflow update - 2026-05-18T02:02:36.561Z
- Moved IN-PROGRESS → CODE-REVIEW.
- Pushed task/editor-01-pure-prompt-editor-state to origin.
- branch 'task/editor-01-pure-prompt-editor-state' set up to track 'origin/task/editor-01-pure-prompt-editor-state'.
- Created PR: https://github.com/ineersa/agent-core/pull/23

## Task workflow update - 2026-05-18T02:16:54.846Z
- Moved CODE-REVIEW → IN-PROGRESS.
- Summary: Moved back to IN-PROGRESS for rewrite based on code review. Key issues: reinvents EditorDocument, mb_* instead of grapheme-aware, no integration path. Rewriting to wrap Symfony TUI EditorDocument/EditorWidget instead.

## Task workflow update - 2026-05-18T02:35:59.065Z
- Recorded fork run: 3wo0vifc6apo
- Validation: phpunit --filter=Editor: 40 tests, 75 assertions — all pass; deptrac: 0 violations; php-cs-fixer: clean; phpstan: clean (baseline regenerated)
- Summary: REWRITE complete based on code review. Replaced reimplemented text buffer (347 lines) with thin facade over Symfony TUI EditorWidget:

- EditorState → lightweight snapshot DTO (no invariant validation, no scrollOffset, no with* builders)
- PromptEditor → 99-line facade delegating to EditorWidget (getText, setText, clear, isEmpty, extract, getState, getWidget)
- Deleted PromptEditorTextEditingTest + PromptEditorCursorTest (tested reimplemented logic)
- Added PromptEditorTest (20 tests testing facade with real EditorWidget)
- Kept PromptEditorWidget (ChatLayout depends on it, not dead code)
- Added SymfonyTui to TuiEditor deptrac allowlist
- Renamed submit() → extract() per review
- Net: -775 lines, 40 tests passing, 0 deptrac violations

## Task workflow update - 2026-05-18T02:36:22.198Z
- Moved IN-PROGRESS → CODE-REVIEW.
- Pushed task/editor-01-pure-prompt-editor-state to origin.
- branch 'task/editor-01-pure-prompt-editor-state' set up to track 'origin/task/editor-01-pure-prompt-editor-state'.
- PR already exists: https://github.com/ineersa/agent-core/pull/23
