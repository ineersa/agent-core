---
description: Prepare an IN-PROGRESS task for PR by reviewing, recording, and moving to CODE-REVIEW
argument-hint: "<task>"
---

Prepare tracked task for PR and code review: `$ARGUMENTS`

If the task argument is empty or still the literal placeholder `<task>`, ask the user for the task slug instead of guessing.

Read the `task-workflow` router and its linked `task-to-pr` procedure now. The router alone is not the procedure. Do not review, run transition checks, or call `move_task` until you have read and applied the phase file.
