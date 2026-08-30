---
description: Start a tracked task by moving TODO -> IN-PROGRESS and choosing implementation ownership
argument-hint: "<task>"
---

Start tracked task: `$ARGUMENTS`

If the task argument is empty or still the literal placeholder `<task>`, ask the user for the task slug instead of guessing.

Read the `task-workflow` router and its linked `task-start` procedure now. The router alone is not the procedure. Do not call `move_task` or begin implementation until you have read and applied the phase file.
