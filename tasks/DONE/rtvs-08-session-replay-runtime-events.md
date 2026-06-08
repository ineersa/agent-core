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
Status: DONE
Branch: task/rtvs-08-session-replay-runtime-events
Worktree: /home/ineersa/projects/agent-core-worktrees/rtvs-08-session-replay-runtime-events
Fork run: hl2c2mio273b
PR URL: https://github.com/ineersa/agent-core/pull/105
PR Status: merged
Started: 2026-06-08T14:37:11.366Z
Completed: 2026-06-08T15:57:46.614Z

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

## Task workflow update - 2026-06-08T14:44:49.472Z
- Validation: Scout 1: resume transcript path currently in SessionInitializer::replayFromEvents(); identified activity-state and E2E resume test gaps.; Scout 2: RunState replay integration exists in RunMessageProcessor::processWithRetry(); identified missing/stale file-backed integration coverage gaps.; Scout 3: active stale docs identified in docs/tui-architecture.md, docs/session-storage.md, docs/hitl-and-approvals.md, src/Tui/Application/SessionInitializer.php comment, and selected active task/doc artifact lists; historical .pi plans should generally remain as history except optional superseded note.
- Summary: Start-phase scouts completed for RTVS-08. Current post-08A/08B architecture: SessionInitializer replays canonical events.jsonl through RuntimeEventMapper + TranscriptProjector and sets TuiSessionState::lastSeq to max replayed persistent event seq; RuntimeEventPoller dedups seq <= lastSeq and shares the projector instance; RunMessageProcessor has RunStateReplayService integration for missing/stale state before handlers execute. Remaining likely scope: validate/fix activity state on resume (state defaults Idle and may not reflect replayed WaitingHuman/cancel/error/tool states), add end-to-end SessionInitializer resume tests with real projector for user+assistant, tool/HITL, cancellation/error, verify poller does not duplicate after resume cursor, add/confirm state replay integration coverage for missing/stale/corrupt state if not already sufficient, and clean stale active docs around TranscriptEntry/runtime-events.jsonl/transcript.jsonl.
- task-start: launched 3 scouts for resume transcript integration, RunState replay/continuation integration, and docs/tests stale references. Full scout output saved to /home/ineersa/.pi/agent/tmp/2026-06--0042baf7.txt.

## Task workflow update - 2026-06-08T14:58:25.398Z
- Recorded fork run: av5e2wo5qm90
- Validation: Verified commit 5034ee0b exists at HEAD in worktree; worktree clean; Verified diff vs origin/main: 5 files changed, 527 insertions, 31 deletions; Fork validation: castor test --filter=SessionInitializerReplayTest PASS (6/6); Fork validation: castor test --filter=SessionInitializerTest + ActivityStateMachineTest PASS (41/41); Fork validation: castor test PASS (2051/2051, 6025 assertions); Fork validation: castor deptrac PASS (0 violations); Fork validation: castor phpstan PASS (0 errors); Fork validation: castor cs-check PASS (clean); Fork validation: castor test:controller PASS (1/1); Fork validation: castor test:tui PASS (5/5); Fork validation: castor test:llm-real PASS (5/5); Fork safety check: grep runtime-events.jsonl in src/tests returned 0 hits
- Summary: Implementation fork completed RTVS-08 at commit 5034ee0b (`RTVS-08: Validate canonical session resume replay`). Verified worktree is clean on branch task/rtvs-08-session-replay-runtime-events. Diff vs origin/main: 5 files changed, +527/-31. Production fix: SessionInitializer::replayFromEvents() now restores TuiSessionState::activity by applying ActivityStateMachine::transition() for each replayed RuntimeEvent, matching RuntimeEventPoller live behavior. Tests: added SessionInitializerReplayTest with real SessionRunEventStore + RuntimeEventMapper + TranscriptProjector/subscribers covering user+assistant, tool+HITL, cancellation, error, lastSeq/dedup, and WaitingHuman activity. Docs: updated active docs in docs/tui-architecture.md, docs/session-storage.md, and docs/hitl-and-approvals.md to remove stale TranscriptEntry/formatEventToEntry/runtime-events.jsonl/transcript append references. Fork verified RunStateReplayService is already wired through RunMessageProcessor before resume continuation, so no duplicate client-level replay wiring was added.
- task-start: fork av5e2wo5qm90 completed implementation and committed 5034ee0b. No review/PR/gate step run per task-start workflow.
- task-start: remaining known gaps are out of scope/pre-existing: transient ToolCall blocks are not replayed from canonical events, llm_step_aborted lacks assistant payload, and HITL kind defaults to question.

## Task workflow update - 2026-06-08T15:14:23.297Z
- Recorded fork run: hl2c2mio273b
- Validation: Verified HEAD c6b69c68 and clean worktree; Verified branch diff vs origin/main: 5 files changed, 604 insertions, 34 deletions; Fork validation: castor test --filter=SessionInitializerReplayTest PASS (7/7, 32 assertions); Fork validation: castor test PASS (2052/2052, 6031 assertions); Fork validation: castor deptrac PASS (0 violations); Fork validation: castor phpstan PASS (0 errors); Fork validation: castor cs-check PASS (clean); Fork validation: castor test:tui PASS (5/5)
- Summary: Review-fix fork completed at commit c6b69c68 (`RTVS-08: Address resume replay review findings`). Worktree clean. Addressed reviewer suggestions: corrected docs/tui-architecture.md projection table to actual TranscriptBlockKindEnum/current behavior, updated TuiSessionState pseudo-code to UsageProjection, strengthened dedup test with poller-mapper comment and exact new-block assertion, added dropped/null-mapped events lastSeq regression test, and asserted replayed user+assistant blocks are not streaming. Skipped optional unreadable chmod test due platform sensitivity.
- task-review-iterate: fork hl2c2mio273b addressed all actionable reviewer suggestions; next step final reviewer pass.

## Task workflow update - 2026-06-08T15:26:29.613Z
- Validation: Reviewer verdict: APPROVED for HEAD c6b69c68; Local validation: castor test PASS (tests=2052, assertions=6031, errors=0, failures=0, skipped=0); Local validation: castor deptrac PASS (violations=0, errors=0); Local validation: castor phpstan PASS (errors=0, file_errors=0); Local validation: castor cs-check PASS (files_fixed=0)
- Summary: Task-to-PR review completed for RTVS-08. Initial reviewer returned APPROVE WITH SUGGESTIONS; fork hl2c2mio273b addressed all actionable findings at c6b69c68. Final strict reviewer returned APPROVED for HEAD c6b69c68 with no issues or security concerns; remaining NTH notes were explicitly non-actionable future cleanup. Current branch diff vs origin/main: 5 files changed, 604 insertions, 34 deletions.
- task-to-pr: final reviewer approved RTVS-08 at c6b69c68; proceeding to CODE-REVIEW full Castor gate and PR creation.

## Task workflow update - 2026-06-08T15:29:39.791Z
- Validation: move_task(to=CODE-REVIEW) reached branch push; remote head verified at c6b69c688a09d194ac7b4d75581282752a2d33d7; PR creation failed: gh is not authenticated for github.com account ineersa; token in default is invalid
- Summary: Attempted move_task to CODE-REVIEW after reviewer approval and local validation. Branch `task/rtvs-08-session-replay-runtime-events` was pushed to origin at c6b69c688a09d194ac7b4d75581282752a2d33d7, but PR creation failed because `gh` is not authenticated (`token in default is invalid`). Task remains IN-PROGRESS pending GitHub CLI authentication or manual PR creation.
- task-to-pr blocker: CODE-REVIEW transition could not complete because gh auth is invalid. Options: user runs `gh auth login -h github.com` then retry move_task, or user manually creates PR from branch `task/rtvs-08-session-replay-runtime-events`.
Castor Check Status: passed
Castor Check Commit: c6b69c688a09d194ac7b4d75581282752a2d33d7
Castor Check Command: LLM_MODE=true castor check
Castor Check Timeout: 900s
Castor Check Completed: 2026-06-08T15:38:44.814Z
Castor Check Output SHA256: d13a1ec7e60ab8bb3537ea34dcc622c687f4e419ec25a2cccfc85658bc4dfb34

## Task workflow update - 2026-06-08T15:38:47.901Z
- Moved IN-PROGRESS → CODE-REVIEW.
- Castor quality gate passed (900s timeout). Commit: c6b69c688a09.
- Pushed task/rtvs-08-session-replay-runtime-events to origin.
- branch 'task/rtvs-08-session-replay-runtime-events' set up to track 'origin/task/rtvs-08-session-replay-runtime-events'.
- Created PR: https://github.com/ineersa/agent-core/pull/105

## Task workflow update - 2026-06-08T15:57:46.614Z
- Moved CODE-REVIEW → DONE.
- Merged task/rtvs-08-session-replay-runtime-events into integration checkout.
- Already up to date.
- Removed worktree /home/ineersa/projects/agent-core-worktrees/rtvs-08-session-replay-runtime-events.
- Pulled integration checkout: Already up to date..
- Validation: PR #105 state: MERGED, mergedAt 2026-06-08T15:55:20Z
- Summary: PR #105 was already merged on GitHub (merge commit ac2dc874). Integration checkout was synced onto origin/main, then task moved to DONE. Note: unrelated copy-command work present in integration checkout was preserved in named stashes before merge/sync: `pre-rtvs08-done-unrelated-main-untracked` and `pre-rtvs08-done-unrelated-copy-command-work`.
