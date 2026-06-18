# Revert accidental issue-137 main contamination

## Goal
Rollback task requested after PR #164/current issue-137 architecture was rejected. Main contains accidental issue-137 code commits from the earlier wrong-cwd fork: 6a32a879e, b499d8911, eecb668c0. Create a safe revert branch from origin/main and revert those commits via normal history, then validate with Castor. Do not reset or force-push shared history.

## Acceptance criteria
- Branch task/revert-issue-137-main-contamination is based on origin/main.
- Commits eecb668c0, b499d8911, and 6a32a879e are reverted with git revert (no history rewrite).
- Rejected issue-137 runtime/TUI output-cap observability code is removed from main via the revert branch.
- castor check passes or any blocker is recorded with diagnostics.
- PR is prepared for review; PR #164 remains rejected/unmerged.

## Workflow metadata
Status: CODE-REVIEW
Branch: task/revert-issue-137-main-contamination
Worktree: /home/ineersa/projects/agent-core-worktrees/revert-issue-137-main-contamination
Fork run: 198rgy17emyg
PR URL: https://github.com/ineersa/agent-core/pull/165
PR Status: open
Started: 2026-06-18T17:01:24.734Z
Completed:

## Work log
- Created: 2026-06-18T17:01:17.380Z

## Task workflow update - 2026-06-18T17:01:24.734Z
- Moved TODO → IN-PROGRESS.
- Created branch task/revert-issue-137-main-contamination.
- Created worktree /home/ineersa/projects/agent-core-worktrees/revert-issue-137-main-contamination.
- Copied vendor directory into /home/ineersa/projects/agent-core-worktrees/revert-issue-137-main-contamination.
- Copied .vera index into /home/ineersa/projects/agent-core-worktrees/revert-issue-137-main-contamination.
- Summary: Starting rollback requested by user: safe revert branch for accidental issue-137 commits on main. Implementation must be done in worktree via fork; no reset/rebase/force-push.

## Task workflow update - 2026-06-18T17:05:29.427Z
- Recorded fork run: 198rgy17emyg
- Validation: Fork ran castor check: deptrac OK; unit/integration OK (2702 tests, 8057 assertions); controller replay OK (1 test, 14 assertions); TUI replay OK (8 tests, 83 assertions); cs-check OK; phpstan lane timed out at 30s on cold worktree.; Fork retried castor phpstan separately: 0 errors, 0 file_errors.; Parent verification: git status clean; git diff --stat origin/main...HEAD shows exactly 7 files / 932 deletions; leftover src grep for rejected issue-137 symbols returned zero hits.
- Summary: Fork completed rollback branch task/revert-issue-137-main-contamination. Created and pushed three safe git-revert commits: 7b23272bc reverts eecb668c0, 11c9e14a8 reverts b499d8911, 03f20946e reverts 6a32a879e. No conflicts, no history rewrite. Revert deletes SystemNoticeProjectionSubscriber and TuiOutputCapNoticeE2eTest and removes issue-137 observability additions from RuntimeEventTranslator, RuntimeEventTypeEnum, ToolProjectionSubscriber, TranscriptProjectorTest, and RuntimeEventMapperTest. Parent verification confirmed worktree clean, 7 files changed with 932 deletions, and zero leftover source refs for output_cap_notice/model_tool_inputs/ModelToolInput/model_input_messages/SystemNotice/TuiOutputCapNotice.

## Task workflow update - 2026-06-18T17:06:21.579Z
- Moved IN-PROGRESS → CODE-REVIEW.
- Running deterministic castor check in worktree (timeout 480s)...
- castor check passed (38.5s).
- Pushed task/revert-issue-137-main-contamination to origin.
- branch 'task/revert-issue-137-main-contamination' set up to track 'origin/task/revert-issue-137-main-contamination'.
- Skipped PR creation (pushOnly: true).
- Validation: Fork validation: castor check passed all lanes except initial phpstan timeout; standalone castor phpstan retry passed with 0 errors.; Parent verification: worktree clean, 7 files changed / 932 deletions vs origin/main, no leftover rejected issue-137 source symbols.; move_task CODE-REVIEW deterministic castor check result recorded by tool.
- Summary: Rollback branch is ready for review. It safely reverts accidental issue-137 main contamination via normal git revert commits (7b23272bc, 11c9e14a8, 03f20946e), with no conflicts and no history rewrite. Branch pushed; gh auth is unavailable in this environment, so PR creation is skipped and should be done manually from origin/task/revert-issue-137-main-contamination to main.

## Task workflow update - 2026-06-18T17:07:24.847Z
- Validation: gh pr create failed: HTTP 401 Requires authentication (https://api.github.com/graphql).
- Summary: User requested PR creation for rollback branch. Attempted `gh pr create --base main --head task/revert-issue-137-main-contamination`, but GitHub CLI failed with HTTP 401 Requires authentication. Branch remains pushed and ready; PR must be created from an authenticated environment.

## Task workflow update - 2026-06-18T17:09:05.623Z
- Updated PR URL: https://github.com/ineersa/agent-core/pull/165
- Updated PR Status: open
- Summary: PR created successfully after GitHub CLI authentication was refreshed: https://github.com/ineersa/agent-core/pull/165
