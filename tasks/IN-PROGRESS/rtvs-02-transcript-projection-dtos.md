# RTVS-02 Transcript projection DTOs

## Goal
Plan: .pi/plans/runtime-transcript-vertical-slice-plan.md

Scope:
- Add scalar transcript projection DTOs under src/CodingAgent/Runtime/Projection.
- Include TranscriptBlock and TranscriptBlockKind, plus minimal helpers for serialization if needed.
- Support block kinds for user_message, assistant_message, assistant_thinking, tool_call/tool_result, question/approval, cancelled, error, system/progress if needed.
- Keep DTOs free of Symfony TUI and AgentCore dependencies.

Exclusions:
- Do not implement projector logic.
- Do not implement rendering widgets.
- Do not persist transcript.jsonl yet.

Dependencies: none.
Parallelizable with: RTVS-01, RTVS-05, RTVS-06 after agreeing rough DTO shape.

## Acceptance criteria
- Projection DTOs live under CodingAgent/Runtime/Projection and contain only scalar/array data.
- DTOs can round-trip to/from arrays suitable for transcript.jsonl.
- DTOs do not depend on Tui or AgentCore Application/Infrastructure namespaces.
- Tests cover construction and serialization of representative block kinds.
- castor deptrac passes.

## Workflow metadata
Status: IN-PROGRESS
Branch: task/rtvs-02-transcript-projection-dtos
Worktree: /home/ineersa/projects/agent-core-worktrees/rtvs-02-transcript-projection-dtos
Fork run:
PR URL:
PR Status:
Started: 2026-05-19T01:13:42.351Z
Completed:

## Work log
- Created: 2026-05-17T22:16:25.801Z

## Task workflow update - 2026-05-19T01:13:42.351Z
- Moved TODO → IN-PROGRESS.
- Created branch task/rtvs-02-transcript-projection-dtos.
- Created worktree /home/ineersa/projects/agent-core-worktrees/rtvs-02-transcript-projection-dtos.
- Copied vendor directory into /home/ineersa/projects/agent-core-worktrees/rtvs-02-transcript-projection-dtos.
- Copied .vera index into /home/ineersa/projects/agent-core-worktrees/rtvs-02-transcript-projection-dtos.
