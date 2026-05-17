# RTVS-08 Session replay from runtime events

## Goal
Plan: .pi/plans/runtime-transcript-vertical-slice-plan.md

Scope:
- On resume, load .hatfield/sessions/<id>/runtime-events.jsonl and rebuild TranscriptBlock projection through TranscriptProjector.
- Treat transcript.jsonl as projection/cache data, not rendered ANSI strings.
- Validate that replay from runtime events produces the same basic block list as live polling.
- Preserve session_id === run_id assumptions and existing session directory rules.

Exclusions:
- Do not implement RuntimeEventPoller live integration; RTVS-07 owns that.
- Do not implement fork/branch session trees.
- Do not implement rich compaction UI.

Dependencies: RTVS-07.
Parallelizable with: RTVS-09 fixture preparation only, but avoid concurrent edits to session replay code.

## Acceptance criteria
- Resuming a session rebuilds transcript blocks from runtime-events.jsonl.
- Replay is idempotent and does not duplicate streamed deltas.
- transcript.jsonl, if written, contains projection/block data rather than ANSI-rendered strings.
- Tests cover resume/replay for user+assistant and at least one cancellation or tool/HITL event.
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
- Created: 2026-05-17T22:17:13.135Z
