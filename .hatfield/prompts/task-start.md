---
description: Start a selected GitHub issue and implement in its task workspace
argument-hint: "<issue-number-or-url>"
---

Selected task: `$ARGUMENTS`

If the argument is empty or still a placeholder, ask for an issue number or URL
rather than guessing. Listing is for choosing work, not a prerequisite for reading
an already selected issue.

Read the selected issue with `read_task` and follow **Plan** and **Implement**
in the `task-workflow` skill. Use `move_task` to start/resume the workspace.
Implement directly or use a useful bounded implementation fork. Stop after
implementation; do not submit, merge or finalize.
