---
description: Merge a reviewed and approved task to DONE
argument-hint: "<task>"
---

Complete reviewed task: `$ARGUMENTS`

If the task argument is empty or still the literal placeholder `<task>`, ask the user for the task slug instead of guessing.

Read the `task-workflow` router and its linked `task-done` procedure now. The router alone is not the procedure. Do not call `move_task` or merge anything until you have read and applied the phase file.
