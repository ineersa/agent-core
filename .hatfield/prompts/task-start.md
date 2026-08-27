---
description: Start a tracked task by moving TODO -> IN-PROGRESS and choosing implementation ownership
argument-hint: "<task>"
---

Start tracked task: `$ARGUMENTS`

If the task argument is empty or still the literal placeholder `<task>`, ask the user for the task slug instead of guessing. Otherwise, load the Hatfield task-workflow skill and follow the `task-start` phase. Choose implementation ownership, implement and record validation under that ownership, then stop before PR preparation or review.
