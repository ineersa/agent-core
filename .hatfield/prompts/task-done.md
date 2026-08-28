---
description: Merge a reviewed and approved task to DONE
argument-hint: "<task>"
---

Complete reviewed task: `$ARGUMENTS`

If the task argument is empty or still the literal placeholder `<task>`, ask the user for the task slug instead of guessing.
Load the `task-workflow` skill and follow the `task-done` phase.
