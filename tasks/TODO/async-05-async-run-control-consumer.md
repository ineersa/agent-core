# ASYNC-05: Async run-control consumer

## Goal
## Plan reference
`.pi/plans/async-headless-messenger-plan.md` — Phase 5

## Summary
Make orchestration itself a proper consumer path. Route command bus messages through the run_control Doctrine transport so the controller dispatches and returns to the event loop immediately.

## Tasks
- Route `StartRun`, `ApplyCommand`, `AdvanceRun`, `LlmStepResult`, `ToolCallResult` to `run_control` transport
- Controller dispatches and returns to event loop (no blocking)
- Ensure self-advance callbacks (`postCommit` → AdvanceRun) enqueue correctly through transport
- Validate terminal/stale-result/idempotent behavior
- Verify run-control consumer can be restarted without corrupting state
- Ensure command bus stays sync within the run-control consumer process

## Acceptance criteria
- Controller never blocks on run-control processing
- Run-control consumer can be restarted without corrupting state
- One run progresses from start → LLM → result → completion through transports
- Self-advance callbacks work across transport (postCommit dispatches AdvanceRun, consumer picks it up)
- `castor check` passes
- `castor run:agent-test` shows full flow working through transports

## Order
**Fourth.** Depends on ASYNC-04 (async execution working).  
**No parallelism** — builds on top of the full async pipeline.

## Critical constraint
Command bus MUST stay sync per-process. Self-advance callbacks dispatch AdvanceRun synchronously within the run-control consumer. The command bus must NOT route to Doctrine transport — only the run-control consumer's internal dispatch is sync.

## Acceptance criteria
- controller never blocks on run-control processing
- run-control consumer restarts without corrupting state
- one full run: start → LLM → result → completion through transports

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
- Created: 2026-05-22T01:54:16.966Z
