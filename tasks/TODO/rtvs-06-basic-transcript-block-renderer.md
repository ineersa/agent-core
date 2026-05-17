# RTVS-06 Basic TranscriptBlock renderer in TUI

## Goal
Plan: .pi/plans/runtime-transcript-vertical-slice-plan.md

Scope:
- Add or adapt TUI transcript rendering to render TranscriptBlock DTOs plainly.
- Render at least user message, assistant message, assistant thinking, tool preview/result, question/approval placeholder, cancelled, and error blocks.
- Keep output simple: no rich markdown, no interactive question form, no final tool widgets.
- Use existing TUI theme tokens and role prefixes where sensible.

Exclusions:
- Do not implement TranscriptProjector logic.
- Do not refactor RuntimeEventPoller integration; RTVS-07 owns that.
- Do not implement local TUI question widgets; see .pi/plans/tui-question-hitl-plan.md.

Dependencies: RTVS-02.
Parallelizable with: RTVS-03, RTVS-04, RTVS-05.

## Acceptance criteria
- TUI can render a static list of TranscriptBlock DTOs without relying on rendered strings in transcript.jsonl.
- Renderer covers required block kinds with readable plain output.
- Renderer does not import AgentCore internals.
- Focused tests or snapshot-style unit tests cover representative blocks.
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
- Created: 2026-05-17T22:16:59.539Z
