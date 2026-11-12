---
description: Address PR feedback and resubmit a GitHub task
argument-hint: "<issue-or-pr-url>"
---

Selected task: `$ARGUMENTS`

If the argument is empty or still a placeholder, ask for an issue number or URL
rather than guessing. Listing is for choosing work, not a prerequisite for reading
an already selected issue.

Resolve the selected issue/PR identity directly and read its current body and
review feedback. Follow **Verify and deliver** in the `task-workflow` skill.
Move back to IN-PROGRESS, implement accepted feedback, refresh affected QA,
and resubmit through `move_task`. No compulsory reviewer delegation or task
bookkeeping forms; honor the project's review requirements. Do not merge.
