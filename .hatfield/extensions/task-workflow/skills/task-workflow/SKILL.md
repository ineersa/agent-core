---
name: task-workflow
description: Set up task workflow for this project, or plan, implement, validate, and deliver work tracked in GitHub issues.
version: 2.2.0
---

# Task workflow

## Set up this project

For “set up task workflow”, read the installed extension's
[project setup guide](../../extensions/vendor/ineersa/hatfield-ext-task-workflow/README.md#project-setup)
(relative to this installed project skill). Git and a committed repository are
prerequisites. Inspect instructions, remote/base branch, CI and existing build/test
commands first; propose discovered settings and all three project-owned hooks.
Prepare `.github/ISSUE_TEMPLATE/{feature,bug,experiment}.md` from the installed
extension's `templates/issue/` defaults, preserving existing customization.
This is agent-guided setup, not automatic file creation by `/tasks-init`.
Preserve existing files/settings. Ask only unresolved product/review/resource-ownership
decisions or required human authorization. Reuse a suitable existing owner field by its exact
`workflow_field` name; otherwise prefer manual creation over broad account scopes.
Leave unrelated fields alone; colors and extra artifacts are not prerequisites.
Commit approved setup in the stable primary checkout, restart Hatfield to load it,
then deliberately run `/tasks-init` with an existing ordinary issue for its read-only
capability probe. Setup must not create, migrate or merge real tasks. Report verified
readiness, exact blockers and unrun checks; initialization is not a lifecycle test.

## Plan

Main alone manages tasks and transitions using the task tools, not manual status
edits. Workflow is authoritative; native issue closure is not cleanup proof.
Read the selected issue, relevant code, and project instructions.
List tasks only when choosing work or resolving its identity.
If task identity is missing or ambiguous, resolve it rather than guessing.
For a read-only explanation, stop after discussion without issue edits or transitions.
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
Forks follow project development/testing rules; they do not manage task updates,
transitions, other agents, commits, reviews or PRs. Main owns that administration.

Inspect the resulting changes and run useful focused checks.
Update the plan when scope or a consequential decision changes.

## Verify and deliver

Inspect the full change and document actual QA results and remaining gaps.
Use `move_task` for review, completion, and cancellation. These transitions own
project hooks, PR operations, and cleanup. Hooks own the full validation gate;
do not repeat that gate as a separate ceremony. Add the hook's actual outcome to the QA summary.
A passing hook does not imply unrun manual acceptance checks passed.

The shared validate hook runs at review in the task worktree (`HATFIELD_TASK_REASON=review`).
At completion it runs again in the primary checkout (`HATFIELD_TASK_REASON=done`), after merge,
base update, cleanup and verified worktree removal, before remote DONE publication.
Hook cwd applies only to its subprocess, not the agent's cwd. Tool results show
stdout/stderr tails independently (up to 2,000 characters each), not full streamed output.
Failed completion validation retains pending recovery
and does not publish DONE; retry the same finalization, not implementation.

For accepted review feedback, return to implementation, make the changes,
refresh affected QA results, and resubmit. If a transition fails, use its
reported state and next action to recover; never reset unrelated work.

Honor the requested planning-only, implementation-only, or delivery endpoint.
Merge only when authorized and required review is satisfied. Inspect the actual
PR head and review state before accepting a revision, especially on another machine.
Report terminal success only after the transition confirms cleanup, Git worktree
registry removal, directory absence and remote Workflow finalization.
