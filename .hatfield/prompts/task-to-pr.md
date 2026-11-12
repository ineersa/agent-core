---
description: Validate and submit a GitHub task for PR review
argument-hint: "<issue-number-or-url>"
---

Selected task: `$ARGUMENTS`

If the argument is empty or still a placeholder, ask for an issue number or URL
rather than guessing. Listing is for choosing work, not a prerequisite for reading
an already selected issue.

Read the selected issue directly. Follow **Verify and deliver** in the
`task-workflow` skill through review submission only. Inspect the change and
record actual focused QA. Use `move_task(to="CODE-REVIEW")` for the configured
validation gate, push and native PR. Update the issue QA from the actual hook
outcome. Do not merge.
