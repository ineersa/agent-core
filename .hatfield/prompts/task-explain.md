---
description: Read a GitHub issue and explain a plan without starting work
argument-hint: "<issue-number-or-url>"
---

Selected task: `$ARGUMENTS`

If the argument is empty or still a placeholder, ask for an issue number or URL
rather than guessing. Listing is for choosing work, not a prerequisite for reading
an already selected issue.

Read the selected issue directly and relevant code/instructions. Follow
**Plan** in the `task-workflow` skill. Explain intended behavior, approach,
risks, unresolved decisions and verification. Stop for discussion: this endpoint
is read-only; do not update the issue, provision a worktree or move Workflow.
