# RTVS-08A Remove transcript.jsonl and replay transcripts from events.jsonl

## Goal
## Context
User decision: remove `.hatfield/sessions/<id>/transcript.jsonl` instead of keeping a second transcript projection/cache. `events.jsonl` should be the canonical replay source for resume/relaunch, with `state.json` reserved for AgentCore continuation state.

Scout findings:
- `runtime-events.jsonl` was deleted by the async/headless plan; do not revive it.
- Current `transcript.jsonl` is lossy and inconsistent: TUI writes user/system/slash-command entries, controller mode writes finalized projected blocks collapsed to role/text/meta, and TUI runtime projection is in memory only.
- Current resume path (`SessionInitializer::buildInitialTranscript()`) reads `transcript.jsonl`; this must change to replay canonical events through `RuntimeEventMapper` + `TranscriptProjector`.
- Critical gap before removal: current `events.jsonl`/`RuntimeEventTranslator` does not appear to emit `user.message_submitted` for normal user prompts/follow-ups/steers, so replay may miss user message blocks unless AgentCore emits replayable user-message events or the mapper can derive them from canonical events.

Primary production touchpoints identified by scouts:
- `src/Tui/Application/SessionInitializer.php` — replace `transcript.jsonl` resume loading with event replay; set `TuiSessionState::lastSeq` to max replayed seq.
- `src/Tui/Listener/SubmitListener.php` — stop writing user/slash-command transcript entries; keep in-memory block updates.
- `src/CodingAgent/Session/HatfieldSessionStore.php` — stop creating/reading/writing `transcript.jsonl`; remove transcript store APIs.
- `src/CodingAgent/Session/TranscriptEntry.php` — remove persisted DTO if no longer used.
- `src/CodingAgent/Runtime/Session/TranscriptPersistenceService.php` and `src/CodingAgent/Runtime/Controller/RuntimeEventEmitter.php` — remove headless/controller transcript persistence wiring.
- `src/CodingAgent/Runtime/Protocol/RuntimeEventTranslator.php` and AgentCore event emission — ensure user messages are present in canonical event replay.

No compatibility fallback to old `transcript.jsonl` should be added unless explicitly requested; active development rules prefer replacing old behavior.

## Acceptance criteria
- New sessions no longer create `.hatfield/sessions/<id>/transcript.jsonl`.
- No production code reads or writes `transcript.jsonl`; `HatfieldSessionStore::appendTranscriptEntry()` and `getTranscript()` are removed.
- Persisted `Ineersa\CodingAgent\Session\TranscriptEntry` DTO is removed if unused after transcript file removal.
- Resume/relaunch rebuilds TUI `TranscriptBlock` history by reading canonical `events.jsonl`, mapping `RunEvent` to `RuntimeEvent`, and feeding a reset `TranscriptProjector`.
- Replay sets the TUI dedup cursor (`lastSeq`) to the max replayed persistent runtime event seq so the live poller does not duplicate history after resume.
- `events.jsonl` contains or can derive all transcript-critical user inputs: initial prompt, follow-up messages, steers, and accepted HITL answers where applicable.
- Replayed transcript covers at least user + assistant messages and one tool, HITL, cancellation, or error sequence.
- Controller/headless runtime no longer persists projected blocks to `transcript.jsonl`; runtime events remain emitted to the TUI transport and canonical events remain in `events.jsonl`.
- Tests updated to remove `transcript.jsonl` file assertions/diagnostic dumps and add resume-from-events coverage.
- Docs and task/plan references updated to remove `transcript.jsonl` and stale `runtime-events.jsonl` as session projection files.
- `castor deptrac` passes; full validation must use Castor per project rules.

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
- Created: 2026-06-07T00:17:18.300Z
