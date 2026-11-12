---
description: Complete an authorized reviewed GitHub task and verify cleanup
argument-hint: "<issue-number-or-url>"
---

Selected task: `$ARGUMENTS`

If the argument is empty or still a placeholder, ask for an issue number or URL
rather than guessing. Listing is for choosing work, not a prerequisite for reading
an already selected issue.

Read the selected issue and actual PR head/review state. Follow **Verify and
deliver** in the `task-workflow` skill, subject to explicit merge authorization
and project review requirements. Use `move_task(to="DONE")`; a queued merge
is pending, not complete. Reconcile failed cleanup/removal rather than claiming
success from native issue closure. Report completion only after both deletion
postconditions and Workflow finalization are confirmed.
