# Improve move_task DONE dirty-check handling

## Goal
The DONE transition currently refuses to merge whenever the integration checkout is dirty. That is safe, but the recent CODE-REVIEW→DONE flow was painful because the checkout had stale staged/deleted index entries (AD) that only needed an unstaging/reset cleanup. Do not auto-commit arbitrary staged changes by default; instead make the tool provide safer, explicit handling for dirty integration checkouts.

## Acceptance criteria
- When CODE-REVIEW→DONE is blocked by a dirty integration checkout, the error output includes categorized status and actionable suggestions (commit/stash unrelated work, reset stale index entries, or retry with explicit option).
- Add an explicit safe option such as autostashMain or cleanupStaleIndexEntries; default behavior remains require-clean-main/abort.
- Do not silently commit unrelated staged changes during DONE transition.
- Document the behavior in tasks/README.md and workflow prompt.
- Extension still loads cleanly with pi --no-extensions -e .pi/extensions/task-workflow.ts --list-models no-such-model.

## Workflow metadata
Status: DONE
Branch: task/2026-05-16-improve-move-task-done-dirty-check-handling
Worktree: /home/ineersa/projects/agent-core-worktrees/2026-05-16-improve-move-task-done-dirty-check-handling
Fork run:
PR URL: https://github.com/ineersa/agent-core/pull/2
PR Status: merged
Started: 2026-05-16T02:26:00.009Z
Completed: 2026-05-16T02:30:52.479Z

## Work log
- Created: 2026-05-16T02:25:07.604Z

## Task workflow update - 2026-05-16T02:26:00.009Z
- Moved TODO → IN-PROGRESS.
- Created branch task/2026-05-16-improve-move-task-done-dirty-check-handling.
- Created worktree /home/ineersa/projects/agent-core-worktrees/2026-05-16-improve-move-task-done-dirty-check-handling.
- Copied vendor directory into /home/ineersa/projects/agent-core-worktrees/2026-05-16-improve-move-task-done-dirty-check-handling.
- Copied .vera index into /home/ineersa/projects/agent-core-worktrees/2026-05-16-improve-move-task-done-dirty-check-handling.
- Summary: Claiming task for implementation.

## Task workflow update - 2026-05-16T02:27:43.965Z
- Validation: pi --no-extensions -e .pi/extensions/task-workflow.ts --list-models no-such-model: exit=0; git diff --check: clean
- Summary: Implemented dirty-check handling improvements in worktree. Added explicit cleanupStaleIndexEntries option for DONE merges, categorized/actionable dirty checkout errors, docs/workflow prompt updates, and preserved default abort behavior without auto-committing unrelated changes. Commit: 45a8d666 feat(task-workflow): improve dirty checkout handling.

## Task workflow update - 2026-05-16T02:28:30.426Z
- Moved IN-PROGRESS → CODE-REVIEW.
- Pushed task/2026-05-16-improve-move-task-done-dirty-check-handling to origin.
- branch 'task/2026-05-16-improve-move-task-done-dirty-check-handling' set up to track 'origin/task/2026-05-16-improve-move-task-done-dirty-check-handling'.
- Created PR: https://github.com/ineersa/agent-core/pull/2

## Task workflow update - 2026-05-16T02:30:52.479Z
- Moved CODE-REVIEW → DONE.
- Merged task/2026-05-16-improve-move-task-done-dirty-check-handling into integration checkout.
- Merge made by the 'ort' strategy.
 .pi/extensions/task-workflow.ts | 57 ++++++++++++++++++++++++++++++++++++++---
 tasks/README.md                 |  2 ++
 2 files changed, 55 insertions(+), 4 deletions(-)
- Removed worktree /home/ineersa/projects/agent-core-worktrees/2026-05-16-improve-move-task-done-dirty-check-handling.
- Summary: PR merged; moving task to DONE.
