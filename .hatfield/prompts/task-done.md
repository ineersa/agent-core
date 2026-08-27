---
description: Merge a reviewed and approved task to DONE
argument-hint: "<task>"
---

Complete reviewed task: `$ARGUMENTS`

If the task argument is empty or still the literal placeholder `<task>`, ask the user for the task slug instead of guessing. Otherwise, complete the reviewed task named by `$ARGUMENTS` by merging the PR and running post-merge validation:

## Orchestrator role

You are an **orchestrator**, not an implementor. Your job is to dispatch work to specialized agents and coordinate their results:

- **Implementation owner** — main owns cohesive fixes it already understands; workers own complete bounded slices where the handoff reduces rereading/context growth. Never overlap file ownership.
- **Main agent (you)** — checks PR state, merges task branches, runs validation, records results, cleans up.

Main may implement cohesive work it already understands; use compact handoffs for worker-owned slices.

1. **Confirm PR is approved/merged**
   - Check task metadata for PR URL and PR Status.
   - If the PR is not yet merged on GitHub, verify the user has approved merging.
   - If the user merged via the GitHub UI, the integration checkout may be behind; pull main first (`git pull`).

2. **Move to DONE**
   - Call `move_task` with the task slug from `$ARGUMENTS` and `to="DONE"`.
   - This attempts to merge the task branch into the integration checkout.
   - It runs `git pull` after merging to sync with remote.
   - If merge conflicts occur, `move_task` reports them and keeps the task at CODE-REVIEW — do not force.

3. **Post-merge validation**
   - Run `LLM_MODE=true castor check` on the integration checkout (main) after the merge.
   - The gate is fully deterministic (replay-backed, no live LLM).
   - If prerequisites are unavailable (e.g. tmux), run the available subset:
     focused `castor test --filter=…`, `castor deptrac`, `castor phpstan`, `castor cs-check`.
   - **For TUI tasks that were merged: run controller replay or `castor test:tui` only when that selected proof layer is required.** If required proof fails post-merge, open a follow-up task immediately.
   - **When merged changes touch provider/LLM-visible code (Symfony AI provider, model routing, tool schemas, LLM prompts, streaming conversion), also run `castor test:llm-real` as opt-in post-merge validation.** This is NOT required for every normal merge.
   - If `castor install` is needed because of new dependencies (e.g. Doctrine bundles), run it first.

4. **Record results**
   - Use `update_task` to append validation results and any post-merge notes.
   - If validation reveals new failures, document them and decide whether to open a follow-up task.

5. **Clean up**
   - Ensure task-board commits are pushed to main (so main is not ahead of origin).
   - `move_task` with `to="DONE"` cleans up the worktree by default
     (cleanupWorktree defaults to true). The task branch is kept by default unless
     `deleteBranch: true` was explicitly passed.
   - Check whether the worktree directory still exists; if cleanup missed it,
     delete the surviving worktree directory after verifying it has no needed changes.
   - Confirm `git status` on integration checkout is clean.

## Shared workflow policy
Load the platform task-workflow skill and follow the named phase; do not duplicate its procedure here. Implementation ownership follows context: main may finish cohesive work it understands, while workers own complete bounded slices with compact handoffs. Use the TUI proof pyramid (virtual → controller replay → minimal tmux only for PTY/process boot). Before CODE-REVIEW use focused tests for touched areas plus deptrac/phpstan/cs-check; do not mandate full `castor test`. Flakes require deterministic root-cause fixes—never allowlist, quarantine, blind-retry, or increase timeouts. Delete dead or superseded code and uncited fallback paths; required error handling and documented local degradation remain valid.
