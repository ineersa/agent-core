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
- **Fork (tool)** — for ALL implementation fixes: editing files, fixing review blockers, resolving Castor gate failures. You MUST use a fork for any file modification. Never edit files directly.
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
   - If reviewer returns REQUEST CHANGES, analyze the blockers, create exact fork instructions, and launch a fork.
   - Repeat until reviewer returns APPROVED for current HEAD.

3. **Run focused local validation**
   - Run fast Castor validation on the worktree:
     `castor test`, `castor deptrac`, `castor phpstan`, `castor cs-check`.
   - Optionally run `castor test --filter=...` for targeted coverage.
   - Do NOT run `LLM_MODE=true castor check` here — `move_task(to="CODE-REVIEW")`
     runs the full Castor quality gate automatically.
   - Report exact validation results.

4. **Update task metadata**
   - Use `update_task` to record the reviewer decision, commit sha, and validation results.
   - Append a work log entry summarizing the fork commits and reviewer outcome.

5. **Move to CODE-REVIEW**
   - Call `move_task` with the task slug from `$ARGUMENTS` and `to="CODE-REVIEW"`. This runs the
     Castor quality gate (`LLM_MODE=true castor check`) on the task branch at its
     current HEAD before pushing and creating or updating the PR.
   - Record the PR URL returned in the notes.
   - If the Castor gate fails, the task remains IN-PROGRESS. Analyze the failure,
     prepare exact implementation details, and pass them to a fork to fix it.
     Retry only after the full gate can pass. There is no bypass.
