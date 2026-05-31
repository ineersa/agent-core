# FIX Castor check failures on main

## Goal
## Goal
Investigate and fix current `castor check` failures on main/integration checkout that block TOOLS-09 handoff.

Observed from TOOLS-09 fork validation:
- `ControllerSmokeTest` fails on log path resolution, apparently trying to use `//agent-2026-05-31.log` with empty project_dir.
- llm-real/TUI E2E fails on tmux pane issue such as `%14 not found`.

The user suspects an actual database or infrastructure state issue may have been corrupted. Start by reproducing on main with Castor, inspect persisted QA reports/logs, and determine whether failures are code/config bugs, stale runtime DB/session state, tmux environment state, or test isolation problems.

## Scope
- Run only Castor QA/tooling commands as primary validation.
- Reproduce `castor check` or the failing subcommands (`castor test:controller`, `castor test:tui`, `castor test:llm-real`) as needed.
- Inspect `var/qa/`, logs, test temp dirs, messenger sqlite files, and tmux state to identify root cause.
- Fix code/config/test isolation if the failure is a repository bug.
- If it is environment-only, document exact cleanup/remediation steps and validate after cleanup.
- Keep changes focused on restoring reliable `castor check` on main.

## Out of scope
- Do not modify TOOLS-09 implementation unless the failure is proven caused by that branch after rebasing/merging.
- Do not bypass Castor with raw vendor/bin commands except after a Castor failure and only for diagnosis with an explanatory comment.
- Do not hide failing E2E checks by weakening assertions or skipping tests unless the skip reflects a legitimate prerequisite check with explicit blocker output.

## Acceptance criteria
- Root cause of the main `castor check` failures is identified.
- `castor check` passes on main, or a precise external prerequisite blocker is documented with command output.
- Any required cleanup/remediation steps are documented in the task work log.
- If code changes are made, focused Castor validation plus `castor check` are run and reported.

## Acceptance criteria
- Reproduce and diagnose current `castor check` failures on main via Castor.
- Fix repository bug or document exact external environment blocker/remediation.
- `castor check` passes, or blocker output is exact and actionable.
- Commit any code/config/test fixes on task branch.

## Workflow metadata
Status: TODO
Branch:
Worktree:
Fork run:
PR URL:
PR Status:
Started:
Completed:

## Work log
- Created: 2026-05-31T18:22:41.233Z
