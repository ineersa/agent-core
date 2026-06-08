# RTVS-08 Resume/relaunch integration from canonical events

## Goal
Make `agent --resume=<session id>` / session relaunch reliable end-to-end by
replaying canonical `.hatfield/sessions/<id>/events.jsonl` through the TUI
transcript projection and reconstructed/checkpointed RunState.

This task is the **final integration** in a three-task sequence:

```
RTVS-08A  Remove transcript.jsonl, rebuild TUI transcript from events.jsonl
RTVS-08B  Make events.jsonl complete, add deterministic RunState rebuild from events
RTVS-08   [THIS TASK]  Resume/relaunch end-to-end integration, validation, docs
```

Note: `agent --resume=<sessionId>` already exists as a CLI flag in
`src/CodingAgent/CLI/AgentCommand.php`. This task validates/fixes behavior, not
add the flag from scratch.

Plan reference (partially stale — see sequencing above):
`.pi/plans/runtime-transcript-vertical-slice-plan.md`

## Scope
- Validate and fix `agent --resume=<sessionId>` / `InteractiveMode::run()` with
  `sessionId` so it reliably replays transcript from canonical `events.jsonl`.
- Verify that replay through `RuntimeEventMapper` + `TranscriptProjector` (from
  RTVS-08A) produces the same basic block list as live polling.
- Verify that the canonical event log (made complete by RTVS-08B) contains all
  prompt-context mutations needed for transcript reconstruction and continuation.
- Verify that `state.json` is treated as a rebuildable checkpoint/projection
  and that a missing or stale checkpoint is recovered via RTVS-08B replay before
  the run advances.
- Verify that the TUI dedup cursor (`lastSeq`) is set to the max replayed
  persistent event seq so the live poller does not duplicate history after resume.
- Verify that activity state, pending HITL/cancel/error/tool state, and
  continuation behavior are correct after resume.
- Update docs (`docs/session-storage.md`, `docs/tui-architecture.md`,
  related plan references) to reflect canonical `events.jsonl` as replay source
  and remove stale `runtime-events.jsonl` references.

## Exclusions
- Do not revive or write to `runtime-events.jsonl`; it was deleted by the
  async/headless plan and is superseded by canonical `events.jsonl`.
- Do not add backward-compatibility fallback to old `transcript.jsonl` or
  `runtime-events.jsonl` unless explicitly requested (project rules forbid it).
- Do not implement fork/branch session trees.
- Do not implement rich compaction UI.
- Do not move state storage to the database or canonical event storage to DB.
- Do not add the `--resume` CLI flag from scratch (it already exists).

## Dependencies
- **RTVS-08A** (Remove transcript.jsonl, rebuild TUI transcript from events.jsonl)
  — **MUST be complete first.** RTVS-08A does the actual wire-up of
  `SessionInitializer` event replay and removes `transcript.jsonl` I/O. RTVS-08
  validates the integrated result end-to-end.
- **RTVS-08B** (Canonical event completeness and RunState rebuild)
  — **MUST be complete first.** RTVS-08B ensures `events.jsonl` contains all
  replayable events user input events (prompts, steers, follow-ups, HITL
  answers) and provides a deterministic `RunState` rebuild-from-events path.
  RTVS-08 depends on events being complete for transcript and continuation.
- RTVS-07 (RuntimeEventPoller projection integration) — **MERGED**; the live
  polling path this resume path must not duplicate.

## Acceptance criteria
- `agent --resume=<sessionId>` loads an existing session and replays the full
  transcript history from canonical `events.jsonl` through `RuntimeEventMapper`
  + `TranscriptProjector` (from RTVS-08A).
- Replay is idempotent and does **not** duplicate streamed deltas or blocks
  when the live `RuntimeEventPoller` resumes polling after replay.
- The TUI dedup cursor (`TuiSessionState::lastSeq`) is set to the max persistent
  event seq consumed during replay so that subsequent live polling starts at the
  correct position.
- Resume recovers gracefully when `state.json` is missing or stale: the
  RTVS-08B `RunState` replay/projector rebuilds the execution state from
  `events.jsonl` before the run advances.
- Activity state, pending HITL/cancel/error/tool-call state, and continuation
  behavior are correct after resume — no stale working-message, zombie polling,
  or desynchronized projector.
- Tests cover resume for at least: user + assistant conversation,
  one tool/HITL sequence, and one cancellation or error (where replayable).
- No production code reads or writes the deleted `runtime-events.jsonl` or
  relies on `transcript.jsonl` as a resume source.
- `docs/session-storage.md` and related docs updated to reflect that
  `events.jsonl` is the canonical replay source and `state.json` is a
  rebuildable checkpoint.
- `castor deptrac` passes; full validation (`castor check`) required for
  changes touching TUI runtime, Messenger, or LLM-visible flow.

## Workflow metadata
Status: IN-PROGRESS
Branch: task/rtvs-08-session-replay-runtime-events
Worktree: /home/ineersa/projects/agent-core-worktrees/rtvs-08-session-replay-runtime-events
Fork run:
PR URL:
PR Status:
Started: 2026-06-08T14:37:11.366Z
Completed:

## Work log
- Created: 2026-05-17T22:17:13.135Z
- 2026-06-07: Rewritten to reflect new sequencing: RTVS-08A → RTVS-08B → RTVS-08.
  Replaced stale `runtime-events.jsonl` references with canonical `events.jsonl`;
  updated dependencies and acceptance criteria.

## Task workflow update - 2026-06-08T14:37:11.367Z
- Moved TODO → IN-PROGRESS.
- Created branch task/rtvs-08-session-replay-runtime-events.
- Created worktree /home/ineersa/projects/agent-core-worktrees/rtvs-08-session-replay-runtime-events.
- Copied vendor directory into /home/ineersa/projects/agent-core-worktrees/rtvs-08-session-replay-runtime-events.
- Copied .vera index into /home/ineersa/projects/agent-core-worktrees/rtvs-08-session-replay-runtime-events.
