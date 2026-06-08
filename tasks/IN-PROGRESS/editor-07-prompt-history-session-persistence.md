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
Fork run: w6zyj6680lic
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
