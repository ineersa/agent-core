# MAINT-03 Harden task workflow Castor gate timeout handling

## Goal
The repo-local `.pi/extensions/task-workflow.ts` implements `move_task`. Its CODE-REVIEW transition runs `LLM_MODE=true castor check`. Current behavior can appear to hang forever when Castor check hangs or runs too long. Scout found `runCastorCheckGate()` already wraps `castor check` with GNU `timeout --kill-after=15s`, but it still relies on `pi.exec()` and should be hardened/observable so task transitions cannot wait indefinitely. Underlying `pi.exec()` has a known Node bug in its SIGKILL escalation (`proc.killed` is true immediately after SIGTERM), so the extension should avoid relying solely on that layer and should expose clear timeout diagnostics.

## Acceptance criteria
- `.pi/extensions/task-workflow.ts` CODE-REVIEW Castor gate has a hard bounded wall-clock timeout with kill-after semantics and clear diagnostics.
- The gate output records the exact command, timeout, kill-after, elapsed time, and whether failure was timeout vs non-zero exit.
- If GNU `timeout` is missing, the failure is immediate and clear, not a hang.
- The extension does not rely solely on `pi.exec` timeout behavior for Castor gate termination.
- `castorCheckTimeoutSeconds` validation/default/min/max behavior remains intact.
- Typecheck/lint or available validation for the extension passes, or a documented repo-valid substitute is run.

## Workflow metadata
Status: IN-PROGRESS
Branch: task/maint-03-task-workflow-castor-timeout-hardening
Worktree: /home/ineersa/projects/agent-core-worktrees/maint-03-task-workflow-castor-timeout-hardening
Fork run:
PR URL:
PR Status:
Started: 2026-06-10T23:11:35.698Z
Completed:

## Work log
- Created: 2026-06-10T23:11:29.848Z

## Task workflow update - 2026-06-10T23:11:35.698Z
- Moved TODO → IN-PROGRESS.
- Created branch task/maint-03-task-workflow-castor-timeout-hardening.
- Created worktree /home/ineersa/projects/agent-core-worktrees/maint-03-task-workflow-castor-timeout-hardening.
- Copied vendor directory into /home/ineersa/projects/agent-core-worktrees/maint-03-task-workflow-castor-timeout-hardening.
- Copied .vera index into /home/ineersa/projects/agent-core-worktrees/maint-03-task-workflow-castor-timeout-hardening.
- Summary: Starting immediately because the task-workflow extension controls move_task CODE-REVIEW gates and must not hang indefinitely on castor check.
