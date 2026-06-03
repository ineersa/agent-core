# 05-refactor-agentcore-tool-orchestration: extract LLM tool-call orchestration

## Goal
Plan: .pi/plans/architecture-refactor-plan.md
Reports: .pi/reports/agent-core-architecture.md

Extract the cohesive tool-call orchestration logic from LlmStepResultHandler so the handler focuses on run-state transitions and the new service owns tool extraction, policy/schema resolution, ExecuteToolCall effects, and batch setup.

Scope:
- Add an AgentCore application service such as ToolCallOrchestrator with a narrow result DTO.
- Move active tool-set resolution, policy resolution, schema lookup, and ExecuteToolCall effect construction out of LlmStepResultHandler.
- Preserve event payloads, tool batch behavior, and follow-up scheduling semantics.

## Acceptance criteria
- LlmStepResultHandler is materially smaller and delegates tool orchestration to a focused service.
- The new orchestration service has focused unit tests for multi-tool, denied/disabled, schema, and policy edge cases.
- Existing LlmStepResultHandler behavior and event ordering remain unchanged.
- Run and report Castor validation: castor test --filter=LlmStepResultHandler and new orchestrator tests plus castor check, or exact environmental blockers.

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
- Created: 2026-06-03T00:31:41.998Z
