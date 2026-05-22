# ASYNC-04: Async execution transports

## Goal
## Plan reference
`.pi/plans/async-headless-messenger-plan.md` — Phase 4

## Summary
Move slow LLM and tool work out of the run-control path by routing ExecuteLlmStep and ExecuteToolCall to async Doctrine transports.

## Tasks
- Route `ExecuteLlmStep` to `llm` transport
- Route `ExecuteToolCall` to `tool` transport
- Route `LlmStepResult` / `ToolCallResult` back to `run_control`
- Controller launches `messenger:consume llm` and `messenger:consume tool` child processes
- Keep run-control single-consumer at first
- Verify streaming deltas flow through publish bus → controller → TUI
- Verify cooperative cancel works across process boundary

## Acceptance criteria
- Controller command handling returns quickly after scheduling execution
- LLM/tool work runs in separate consumer process
- Canonical `events.jsonl` and `state.json` remain correct
- Streaming deltas flow through publish bus → controller → TUI
- Steer/cancel commands arrive while LLM work is in progress
- `castor check` passes
- `castor run:agent-test` shows responsive TUI during LLM call

## Order
**Third.** Depends on ASYNC-02 (publish sources wired) and ASYNC-03 (controller running).  
**No parallelism** — needs both previous tasks complete.

## Acceptance criteria
- controller returns quickly after scheduling execution
- LLM/tool work runs in separate consumer process
- canonical events.jsonl and state.json remain correct
- streaming deltas flow: publish bus → controller → TUI
- steer/cancel commands arrive while LLM work in progress

## Workflow metadata
Status: IN-PROGRESS
Branch: task/async-04-async-execution-transports
Worktree: /home/ineersa/projects/agent-core-worktrees/async-04-async-execution-transports
Fork run:
PR URL:
PR Status:
Started: 2026-05-22T19:03:33.774Z
Completed:

## Work log
- Created: 2026-05-22T01:54:03.465Z

## Task workflow update - 2026-05-22T19:03:33.774Z
- Moved TODO → IN-PROGRESS.
- Created branch task/async-04-async-execution-transports.
- Created worktree /home/ineersa/projects/agent-core-worktrees/async-04-async-execution-transports.
- Copied vendor directory into /home/ineersa/projects/agent-core-worktrees/async-04-async-execution-transports.
- Copied .vera index into /home/ineersa/projects/agent-core-worktrees/async-04-async-execution-transports.
