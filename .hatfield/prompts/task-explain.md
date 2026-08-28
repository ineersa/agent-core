---
description: Analyze a task and present an implementation plan for discussion before starting work
argument-hint: "<task>"
---

Explain tracked task: `$ARGUMENTS`

If the task argument is empty or still the literal placeholder `<task>`, ask the user for the task slug instead of guessing.
Load if not loaded yet the `task-workflow` skill and follow the `task-explain` phase. This phase is read-only: present the plan for discussion and stop before implementation.
