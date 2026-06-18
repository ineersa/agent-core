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
Status: IN-PROGRESS
Branch: task/revert-issue-137-main-contamination
Worktree: /home/ineersa/projects/agent-core-worktrees/revert-issue-137-main-contamination
Fork run:
PR URL:
PR Status:
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
