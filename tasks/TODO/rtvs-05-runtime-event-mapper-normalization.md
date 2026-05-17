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
Status: TODO
Branch:
Worktree:
Fork run:
PR URL:
PR Status:
Started:
Completed:

## Work log
- Created: 2026-05-17T22:16:52.560Z
