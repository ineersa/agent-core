# RTVS-01 Runtime event contract constants and docs

## Goal
Plan: .pi/plans/runtime-transcript-vertical-slice-plan.md

Scope:
- Add stable runtime event-name constants/enums under src/CodingAgent/Runtime/Protocol.
- Document event names and payload shapes used by transcript projection near the runtime protocol code.
- Include run/turn, user, assistant, tool, HITL, cancellation, and usage/status event families as contract names.
- Keep RuntimeEvent DTO shape unchanged for JSONL/process compatibility.

Exclusions:
- Do not implement TranscriptProjector.
- Do not modify TUI rendering.
- Do not import AgentCore internals into Tui.

Dependencies: none.
Parallelizable with: RTVS-02.

## Acceptance criteria
- Runtime event names used by transcript projection are centralized in constants/enums, not duplicated raw strings.
- RuntimeEvent remains backward-compatible with fields type, runId, seq, payload, v.
- Protocol documentation explains payload shapes for the first vertical slice.
- Focused tests or static assertions cover constant values where practical.
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
- Created: 2026-05-17T22:16:19.329Z
