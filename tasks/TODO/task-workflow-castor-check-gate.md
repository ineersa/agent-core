# Enforce Castor check gate before code review

## Goal
Update `.pi/extensions/task-workflow.ts` so moving a task from IN-PROGRESS to CODE-REVIEW requires an extension-run `LLM_MODE=true castor check` quality gate at the task branch HEAD before pushing/creating a PR. The gate must have an OS-level timeout to prevent model/E2E hangs, must keep the task in IN-PROGRESS on failure/timeout, and must record a commit-bound validation receipt in the task log/metadata. Do not rely on model-provided proof.

## Acceptance criteria
- IN-PROGRESS → CODE-REVIEW runs `LLM_MODE=true castor check` in the task worktree before push/PR by default.
- The check uses a hard timeout (default around 240s) with child-process cleanup; timeout/failure aborts the transition without moving the task.
- Gate verifies HEAD is unchanged and worktree remains clean after validation.
- Task metadata/log records passed status, commit sha, command, timeout, timestamp, and a sha256/output receipt or concise output summary.
- Gate can be explicitly bypassed only with a non-empty reason that is loudly recorded in task log/metadata.
- Existing move_task behavior for TODO→IN-PROGRESS and CODE-REVIEW→DONE remains intact.
- Validation via Castor passes, including tests covering gate pass/fail/timeout/bypass behavior if practical.

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
- Created: 2026-06-02T21:35:52.947Z
