---
name: task-workflow
description: Set up task workflow for this project, or plan, implement, validate, and deliver work tracked in GitHub issues.
version: 2.1.0
---

# Task workflow

## Set up this project

For “set up task workflow”, read the installed extension's
[project setup guide](../../extensions/vendor/ineersa/hatfield-ext-task-workflow/README.md#project-setup)
(relative to this installed project skill). Git and a committed repository are
prerequisites. Inspect instructions, remote/base branch, CI and existing build/test
commands first; propose discovered settings, shared feature/bug/experiment Markdown
templates and all three project-owned hooks. Preserve existing files/settings and
customization. Ask only unresolved product/review/resource-ownership decisions or
required human authorization. Reuse a suitable existing owner field by its exact
`workflow_field` name; otherwise prefer manual creation over broad account scopes.
Leave unrelated fields alone; colors and extra artifacts are not prerequisites.
Commit approved setup in the stable primary checkout, restart Hatfield to load it,
then deliberately run `/tasks-init` with an existing ordinary issue for its read-only
capability probe. Setup must not create, migrate or merge real tasks. Report verified
readiness, exact blockers and unrun checks; initialization is not a lifecycle test.

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
