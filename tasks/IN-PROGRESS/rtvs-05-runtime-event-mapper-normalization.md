# RTVS-05 RuntimeEventMapper normalization

## Goal
Plan: .pi/plans/runtime-transcript-vertical-slice-plan.md

Scope:
- Update RuntimeEventMapper to map important AgentCore RunEvent types/payloads into stable runtime event names from RTVS-01.
- Cover first-slice events for user messages, assistant stream/result, tool lifecycle, waiting_human/HITL, cancellation, and errors where current AgentCore events expose enough data.
- Preserve raw AgentCore event type/payload as debug metadata only where useful.
- Keep TUI independent from AgentCore internals.

Exclusions:
- Do not create TranscriptBlock DTOs; RTVS-02 owns that.
- Do not implement projector/rendering/poller integration.
- Do not guess AI-13 usage/cost payloads beyond fields already exposed.

Dependencies: RTVS-01.
Parallelizable with: RTVS-02, RTVS-03, RTVS-04.

## Acceptance criteria
- RuntimeEventMapper emits stable event names for the vertical slice instead of relying only on raw passthrough.
- Mapping tests cover representative AgentCore events including waiting_human and cancellation.
- Raw event details, if preserved, are nested as debug metadata and not required by TUI rendering.
- No Tui code imports AgentCore Application/Infrastructure/Messenger namespaces.
- castor deptrac passes.

## Workflow metadata
Status: IN-PROGRESS
Branch: task/rtvs-05-runtime-event-mapper-normalization
Worktree: /home/ineersa/projects/agent-core-worktrees/rtvs-05-runtime-event-mapper-normalization
Fork run: layq6f28tvog
PR URL:
PR Status:
Started: 2026-05-19T21:59:11.393Z
Completed:

## Work log
- Created: 2026-05-17T22:16:52.560Z

## Task workflow update - 2026-05-19T21:59:11.393Z
- Moved TODO → IN-PROGRESS.
- Created branch task/rtvs-05-runtime-event-mapper-normalization.
- Created worktree /home/ineersa/projects/agent-core-worktrees/rtvs-05-runtime-event-mapper-normalization.
- Copied vendor directory into /home/ineersa/projects/agent-core-worktrees/rtvs-05-runtime-event-mapper-normalization.
- Copied .vera index into /home/ineersa/projects/agent-core-worktrees/rtvs-05-runtime-event-mapper-normalization.
- Summary: Starting implementation. RTVS-05 remains needed after the Symfony projection pipeline because the merged projector subscribes to stable `RuntimeEventTypeEnum` names, while current `RuntimeEventMapper` still passes raw AgentCore RunEvent type strings through unchanged. Scope is to normalize AgentCore events into stable runtime event names/payloads for the vertical slice while keeping TUI independent from AgentCore internals.

## Task workflow update - 2026-05-19T21:59:47.700Z
- Recorded fork run: layq6f28tvog
- Summary: Launched implementation fork in RTVS-05 worktree. Scope: normalize AgentCore RunEvent types/payloads in `RuntimeEventMapper` into stable `RuntimeEventTypeEnum` events for the merged Symfony transcript projection pipeline; add focused mapper tests; validate with Castor.
