# EDITOR-07 Prompt history navigation and session persistence

## Goal
Plan: .pi/plans/editor_rollout_plan.md

MVP: no.

Scope:
- Add PromptHistory abstraction and wire submitted prompts into session-aware history.
- Implement MVP history behavior: empty editor + Up/Down cycles submitted prompts.
- Exit history navigation mode once user types normal input.
- Persist/load prompt history alongside session data according to docs/session-storage.md.

Exclusions:
- No visual-line-aware history navigation within multiline entries yet.
- No global cross-session history unless explicitly designed later.
- No completion or command palette history search.

Dependencies: EDITOR-05.
Parallelizable with: EDITOR-06 and EDITOR-08, but avoid concurrent session storage edits.

## Acceptance criteria
- Submitted prompts are appended to session prompt history.
- Empty editor Up/Down recalls previous/next prompt.
- Typing normal input exits history navigation mode.
- History survives session resume when session storage is available.
- Tests cover in-memory navigation and persistence/reload behavior.
- castor deptrac passes.

## Workflow metadata
Status: IN-PROGRESS
Branch: task/editor-07-prompt-history-session-persistence
Worktree: /home/ineersa/projects/agent-core-worktrees/editor-07-prompt-history-session-persistence
Fork run: 6126vm4szkvv
PR URL:
PR Status:
Started: 2026-06-08T00:25:29.815Z
Completed:

## Work log
- Created: 2026-05-18T00:15:49.260Z

## Task workflow update - 2026-06-08T00:25:29.816Z
- Moved TODO → IN-PROGRESS.
- Created branch task/editor-07-prompt-history-session-persistence.
- Created worktree /home/ineersa/projects/agent-core-worktrees/editor-07-prompt-history-session-persistence.
- Copied vendor directory into /home/ineersa/projects/agent-core-worktrees/editor-07-prompt-history-session-persistence.
- Copied .vera index into /home/ineersa/projects/agent-core-worktrees/editor-07-prompt-history-session-persistence.
- Summary: Starting implementation. Planning decision: do not add prompt_history.jsonl, do not read events.jsonl a second time, and do not duplicate prompt text in a separate history list. Implement prompt history as a cursor-only navigator over existing session state/projection user-message blocks that are already rebuilt from canonical events/state during resume. Avoid direct SQLite access from TUI; use existing runtime/TUI projection boundaries.

## Task workflow update - 2026-06-08T00:26:25.866Z
- Recorded fork run: w6zyj6680lic
- Launched implementation fork w6zyj6680lic in worktree /home/ineersa/projects/agent-core-worktrees/editor-07-prompt-history-session-persistence. Fork instructions require cursor-only prompt history over existing TuiSessionState/projection user-message blocks, no new prompt_history.jsonl, no second events.jsonl replay, no prompt-string duplication, and Castor-only validation.

## Task workflow update - 2026-06-08T00:37:07.768Z
- Recorded fork run: w6zyj6680lic
- Validation: fork reported: castor test --filter=PromptHistoryNavigator ok (14 tests, 27 assertions); fork reported: castor test --filter=PromptHistoryListener ok (13 tests, 25 assertions); fork reported: castor test --filter=PromptHistory ok (27 tests, 52 assertions); fork reported: castor test --filter=SessionInitializer ok (4 tests, 20 assertions); fork reported: castor test ok (2012 tests, 5851 assertions); fork reported: castor deptrac ok (0 violations); fork reported: castor phpstan ok (0 errors); fork reported: castor cs-check ok; fork reported: LLM_MODE=true castor check ok, including controller E2E, real LLM E2E, and TUI E2E; parent verified: git status clean; git diff --stat main...HEAD shows 4 files changed, 911 insertions
- Summary: Implementation fork completed at commit 4b1412fd (feat(editor-07): add prompt history navigation over existing transcript state). Verified worktree status is clean and diff stat is limited to four new files: src/Tui/Listener/PromptHistoryListener.php, src/Tui/Listener/PromptHistoryNavigator.php, tests/Tui/Listener/PromptHistoryListenerTest.php, tests/Tui/Listener/PromptHistoryNavigatorTest.php. Design follows agreed constraints: cursor-only navigator over existing TuiSessionState transcript/projection user-message blocks; no prompt_history.jsonl; no second events.jsonl replay; no duplicated prompt-string list; no direct SQLite access from TUI.

## Task workflow update - 2026-06-08T00:50:55.506Z
- Summary: Reviewer subagent completed review of commit 4b1412fd. Verdict: APPROVE WITH SUGGESTIONS (no blocking issues). Non-blocking notes: Up at oldest history entry currently returns false so multiline recalled text may receive Symfony cursor movement; Down clear assumes navigator cursor remains valid; PromptHistoryListener single-slot onInput comment should be clearer for future EDITOR-08 composition; minor unused imports/PHPDoc-only import/naming suggestions in tests/navigator. Reviewer found no correctness, storage, memory, persistence, or Deptrac boundary blockers.
- Reviewer launched for EDITOR-07 on worktree /home/ineersa/projects/agent-core-worktrees/editor-07-prompt-history-session-persistence. Review result: APPROVE WITH SUGGESTIONS; no blocking issues. Note: reviewer report mentioned raw tool names; per project rules those are not being recorded as validation evidence. Prior fork Castor validation remains the validation evidence.

## Task workflow update - 2026-06-08T00:53:39.257Z
- Recorded fork run: 6126vm4szkvv
- Launched follow-up implementation fork 6126vm4szkvv to address reviewer suggestions: consume Up at oldest active history entry as no-op with tests; clarify PromptHistoryListener onInput single-slot/future composition comment; remove unused test imports; optionally rename currentCursor() to currentBlockIndex() and remove PHPDoc-only import if low-risk. Fork instructed to preserve cursor-only/no-new-storage design and run Castor validation.

## Task workflow update - 2026-06-08T00:56:05.263Z
- Recorded fork run: 6126vm4szkvv
- Validation: follow-up fork reported: castor test --filter=PromptHistory ok (29 tests, 58 assertions); follow-up fork reported: castor deptrac ok (0 violations); follow-up fork reported: castor phpstan ok (0 errors); follow-up fork reported: castor cs-check ok; parent checked: git diff --stat 4b1412fd..3ac834d9 shows 4 files changed, 67 insertions, 11 deletions; parent checked: git diff --check 4b1412fd..3ac834d9 passed; parent checked: worktree status clean at HEAD 3ac834d9
- Summary: Follow-up fork completed at commit 3ac834d9 (fix(editor-07): address reviewer suggestions). Parent checked follow-up diff: 4 files changed, 67 insertions, 11 deletions; worktree clean at HEAD 3ac834d9. Changes address reviewer suggestions without altering core design: Up at oldest active history entry is consumed as no-op; PromptHistoryListener docblock now explicitly warns EditorWidget::onInput() is single-slot and future completion work must compose; unused test imports removed; currentCursor() renamed to currentBlockIndex(). User smoke tested and reports it works. Reviewer was not relaunched per user instruction.
