---
description: Respond to PR review comments with analysis, implementation iteration, and re-review
argument-hint: "<task-or-pr>"
---

Address code review feedback for task or PR: `$ARGUMENTS`

If the argument is empty or still the literal placeholder `<task-or-pr>`, ask the user for the task slug or PR URL or number instead of guessing.

Read the `task-workflow` router and its linked `task-review-iterate` procedure now. The router alone is not the procedure. Do not call `move_task` or change code until you have read and applied the phase file.
