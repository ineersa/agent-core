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
Status: TODO
Branch:
Worktree:
Fork run:
PR URL:
PR Status:
Started:
Completed:

## Work log
- Created: 2026-05-22T01:54:03.465Z
