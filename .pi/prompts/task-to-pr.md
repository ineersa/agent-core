---
description: Prepare an IN-PROGRESS task for PR by reviewing, recording, and moving to CODE-REVIEW
argument-hint: "<task>"
---

Prepare tracked task for PR/code review: `$ARGUMENTS`

If the task argument is empty or still the literal placeholder `<task>`, ask the user for the task slug instead of guessing. 
Load if not loaded yet `task-workflow` skill and follow the `task-to-pr` phase, including Pi's reviewer workflow and the CODE-REVIEW transition.
