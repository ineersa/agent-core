---
description: Prepare an IN-PROGRESS task for PR by reviewing, recording, and moving to CODE-REVIEW
argument-hint: "<task>"
---

Prepare tracked task for PR/code review: `$ARGUMENTS`

If the task argument is empty or still the literal placeholder `<task>`, ask the user for the task slug instead of guessing. Otherwise, prepare the tracked task named by `$ARGUMENTS` for code review:

## Orchestrator role

You are an **orchestrator**, not an implementor. Your job is to dispatch work to specialized agents and coordinate their results:

- **Reviewer subagent** — for code review of the worktree changes.
- **Researcher subagents** — for web searches, documentation lookups, changelog checks.
- **Fork (tool)** — for ALL implementation fixes: editing files and fixing review blockers. You MUST use a fork for any file modification. Never edit files directly.
- **Main agent (you)** — reads diffs, launches reviewers, analyzes feedback, prepares fork instructions, records results, moves task state.

If you catch yourself about to open an editor, write a file, or run a code change — stop and launch a fork instead.

1. **Inspect worktree state**
   - `task_list` or read the task file to confirm it is IN-PROGRESS with worktree metadata.
   - `cd` into the worktree path from task metadata.
   - Run `git status --short --branch` and `git log --oneline --decorate -10`.
   - Inspect `git diff --stat origin/main...HEAD` to understand the full diff.

2. **Review quality**
   - Run the reviewer subagent on the worktree (subagent agent="reviewer" cwd=worktree).
   - Use the researcher subagent for web searches or web-based research when up-to-date external information is needed.
   - **For TUI tasks: instruct the reviewer to explicitly check for and reject work that lacks a real TmuxHarness + test LLM E2E proof of the user-visible feature.** Mocks, service-only tests, custom PHP smoke scripts, and picker/footer visibility assertions are NOT substitutes and must be flagged as a blocker.
   - If reviewer returns REQUEST CHANGES or APPROVE WITH SUGGESTIONS, analyze **all actionable findings** (not only CRITICAL/BUG), create exact fork instructions, and launch a fork.
   - Address all sensible findings across severity levels: CRITICAL, BUG, EDGE CASE, SEC, CONVENTION, SIMPLIFY, NAMING, DEAD CODE, and reasonable NTH items. Skip only clearly subjective style preferences or items the reviewer explicitly marks as non-actionable.
   - Repeat until reviewer returns APPROVED for current HEAD.

3. **Run focused local validation**
   - Run fast Castor validation on the worktree:
     `castor test`, `castor deptrac`, `castor phpstan`, `castor cs-check`.
   - **For TUI tasks: also run `castor test:tui` as part of local validation.** The TUI E2E proof test must pass before moving to CODE-REVIEW.
   - Optionally run `castor test --filter=...` for targeted coverage.
   - `move_task(to="CODE-REVIEW")` automatically runs deterministic `castor check` in the worktree, then pushes the branch and creates the PR. The orchestrator/user should run focused validation before moving to catch issues early.
   - Report exact validation results.

4. **Update task metadata**
   - Use `update_task` to record the reviewer decision, commit sha, and validation results.
   - Append a work log entry summarizing the fork commits and reviewer outcome.

5. **Move to CODE-REVIEW**
   - Call `move_task` with the task slug from `$ARGUMENTS` and `to="CODE-REVIEW"`. This verifies the worktree is clean, pushes the branch, and creates or updates the PR.
   - Record the PR URL returned in the notes.
