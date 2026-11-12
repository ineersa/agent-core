---
name: task-workflow
description: Plan, implement, validate, and deliver work tracked in GitHub issues.
version: 2.0.0
---

# Task workflow

## Plan

Read the selected issue, relevant code, and project instructions.
List tasks only when choosing work or resolving its identity.
For a new issue, use the matching feature, bug, or experiment template
from the project's `.github/ISSUE_TEMPLATE` directory. Strip frontmatter and
submit the prepared Markdown body, not a template filename.

Keep the issue as the current specification and plan: intended behavior,
acceptance criteria, important decisions, planned tests, and actual QA results.
For bugs, include reproduction, expected and actual behavior, root cause
or a clearly labeled hypothesis, and the fix plan. Update changed understanding
in place. Keep execution logs and agent metadata out of the issue.
An extended linked artifact is optional, not a second copy of the plan.
All templates use the PR lifecycle, including experiments with negative results.

## Implement

Use `move_task` to start work in the task worktree.
Implement directly or delegate a bounded larger piece to an implementation
fork when useful. Give the fork its assignment and checkout; obtain changes,
blockers, and relevant validation results. Keep one writer per checkout.
Task updates and transitions stay in the main conversation.

Inspect the resulting changes and run useful focused checks.
Update the plan when scope or a consequential decision changes.

## Verify and deliver

Inspect the full change and document actual QA results and remaining gaps.
Use `move_task` for review, completion, and cancellation. These transitions own
project hooks, PR operations, and cleanup; do not duplicate their full
validation gate. Add the hook's actual outcome to the QA summary.
A passing hook does not imply unrun manual acceptance checks passed.

For accepted review feedback, return to implementation, make the changes,
refresh affected QA results, and resubmit. If a transition fails, use its
reported state and next action to recover; never reset unrelated work.

Honor the requested planning-only, implementation-only, or delivery endpoint.
Merge only when authorized and required review is satisfied. Inspect the actual
PR head and review state before accepting a revision, especially on another machine.
Report completion only after the transition confirms cleanup and removal.
