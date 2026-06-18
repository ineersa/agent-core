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
Status: TODO
Branch:
Worktree:
Fork run:
PR URL:
PR Status:
Started:
Completed:

## Work log
- Created: 2026-06-18T17:01:17.380Z
