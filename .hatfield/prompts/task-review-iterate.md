---
description: Respond to PR review comments with analysis, implementation iteration, and re-review
argument-hint: "<task-or-pr>"
---

Address code review feedback for task or PR: `$ARGUMENTS`

If the argument is empty or still the literal placeholder `<task-or-pr>`, ask the user for the task slug or PR URL/number instead of guessing. Otherwise, load the Hatfield task-workflow skill and follow the `task-review-iterate` phase.
