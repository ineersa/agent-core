# ASYNC-02: Wire publish sources + delete runtime-events.jsonl

## Goal
## Plan reference
`.pi/plans/async-headless-messenger-plan.md` — Phase 2

## Summary
Wire stream subscribers to use RuntimeEventPublisherInterface in async mode. Delete all runtime-events.jsonl references — no transition period.

## Tasks
- Wire stream subscribers (`AssistantTextStreamSubscriber`, `AssistantThinkingStreamSubscriber`, `ToolCallStreamSubscriber`) to use `RuntimeEventPublisherInterface` in async mode
- Delete all `runtime-events.jsonl` references:
  - `HatfieldSessionStore::initializeSession()` — remove file creation
  - `RuntimeEventPoller::poll()` — remove appendRuntimeEvent() writes
  - `HatfieldSessionStoreTest` — remove assertions
  - `TuiAgentSmokeTest` — remove from artifact list
  - `docs/session-storage.md` — remove references
  - Any other references found by grep
- Ensure in-process mode still uses `InMemoryRuntimeEventSink` (unchanged)

## Acceptance criteria
- Streaming deltas are dispatched to publish transport in async mode
- No `runtime-events.jsonl` file created anywhere
- All tests pass
- In-process mode still works (uses sink, not publisher)
- `castor check` passes
- `castor run:agent-test` shows prompt/response working

## Order
**Second.** Depends on ASYNC-01 (needs publish bus).  
**Parallel with ASYNC-03** — wiring publish sources is independent of building the controller event loop.

## Acceptance criteria
- streaming deltas dispatched to publish transport
- no runtime-events.jsonl created anywhere
- castor check passes
- in-process mode still works via sink

## Workflow metadata
Status: IN-PROGRESS
Branch: task/async-02-wire-publish-delete-runtime-events-jsonl
Worktree: /home/ineersa/projects/agent-core-worktrees/async-02-wire-publish-delete-runtime-events-jsonl
Fork run:
PR URL:
PR Status:
Started: 2026-05-22T02:33:43.728Z
Completed:

## Work log
- Created: 2026-05-22T01:53:30.881Z

## Task workflow update - 2026-05-22T02:33:43.728Z
- Moved TODO → IN-PROGRESS.
- Created branch task/async-02-wire-publish-delete-runtime-events-jsonl.
- Created worktree /home/ineersa/projects/agent-core-worktrees/async-02-wire-publish-delete-runtime-events-jsonl.
- Copied vendor directory into /home/ineersa/projects/agent-core-worktrees/async-02-wire-publish-delete-runtime-events-jsonl.
- Copied .vera index into /home/ineersa/projects/agent-core-worktrees/async-02-wire-publish-delete-runtime-events-jsonl.
