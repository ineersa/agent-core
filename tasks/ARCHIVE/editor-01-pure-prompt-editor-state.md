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
Status: DONE
Branch: task/editor-01-pure-prompt-editor-state
Worktree: /home/ineersa/projects/agent-core-worktrees/editor-01-pure-prompt-editor-state
Fork run: ldq25jwzc6an
PR URL: https://github.com/ineersa/agent-core/pull/23
PR Status: merged
Started: 2026-05-18T01:40:46.601Z
Completed: 2026-05-18T03:26:45.620Z

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

## Task workflow update - 2026-05-18T03:04:22.287Z
- Recorded fork run: ldq25jwzc6an
- Validation: phpunit --filter=Editor: 48 tests, 74 assertions — all pass; deptrac: 0 violations; php-cs-fixer: clean; phpstan: 0 errors
- Summary: Round 2 code review fixes applied:
- PromptEditor now owns EditorWidget creation (parameterless constructor, autowireable)
- Removed dead cursorLine/cursorColumn from EditorState (@todo EDITOR-07)
- Made $lines private with getLines() getter (true immutability)
- Added \r\n/\r normalization to fromText() matching EditorDocument
- Clear docblocks distinguishing PromptEditor vs PromptEditorWidget
- Updated docs/tui-architecture.md with editor module class table
- 48 tests passing, deptrac clean, CS clean, PHPStan clean

## Task workflow update - 2026-05-18T03:26:45.620Z
- Moved CODE-REVIEW → DONE.
- Merged task/editor-01-pure-prompt-editor-state into integration checkout.
- Merge made by the 'ort' strategy.
 depfile.yaml                                |   1 +
 docs/tui-architecture.md                    |  14 ++
 src/Tui/Editor/EditorState.php              | 101 ++++++++++++
 src/Tui/Editor/PromptEditor.php             | 120 +++++++++++++++
 src/Tui/Editor/PromptEditorWidget.php       |   9 +-
 tests/Tui/Editor/EditorStateTest.php        | 230 ++++++++++++++++++++++++++++
 tests/Tui/Editor/PromptEditorTest.php       | 224 +++++++++++++++++++++++++++
 tests/Tui/Editor/PromptEditorWidgetTest.php |  22 +--
 8 files changed, 708 insertions(+), 13 deletions(-)
 create mode 100644 src/Tui/Editor/EditorState.php
 create mode 100644 src/Tui/Editor/PromptEditor.php
 create mode 100644 tests/Tui/Editor/EditorStateTest.php
 create mode 100644 tests/Tui/Editor/PromptEditorTest.php
- Removed worktree /home/ineersa/projects/agent-core-worktrees/editor-01-pure-prompt-editor-state.
- Pulled integration checkout: Merge made by the 'ort' strategy..
