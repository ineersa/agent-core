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

Dependencies:
- RTVS-07 (RuntimeEventPoller projection integration) — MERGED.
- RTVS-11 AC1 (explicit TUI run activity state) — completes the follow_up/steer
  semantics that replay must preserve; replay should not codify the previous
  getWorkingMessage() heuristic.
- RTVS-11 AC2 (after_turn_commit_hook_fixed) — replay replaying persisted events
  should not trigger hook deserialization warnings; fix must be in place before
  heavy replay development to avoid noisy log noise.
- Async/process runtime plan (RTVS-11 AC4) — deferred/separate, but the replay
  design should anticipate that live polling may eventually come from a separate
  headless process rather than in-process calls.

Note: RTVS-09 and RTVS-10 have been removed per user decision. Their intent
(regression coverage, manual smoke) is absorbed by existing tests and AGENTS.md
validation rules.

Parallelizable with: none remaining in the RTVS family.
- Avoid concurrent edits to session replay code and RuntimeEventPoller.

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
