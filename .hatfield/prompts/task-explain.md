---
description: Analyze a task and present an implementation plan for discussion before starting work
argument-hint: "<task>"
---

Explain tracked task: `$ARGUMENTS`

If the task argument is empty or still the literal placeholder `<task>`, ask the user for the task slug instead of guessing.

Follow **Establish current state** in the `task-workflow` skill. Present the plan and stop; this request is read-only.
