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
Status: CODE-REVIEW
Branch: task/async-02-wire-publish-delete-runtime-events-jsonl
Worktree: /home/ineersa/projects/agent-core-worktrees/async-02-wire-publish-delete-runtime-events-jsonl
Fork run: i3hor7ekeqdg
PR URL: https://github.com/ineersa/agent-core/pull/39
PR Status: open
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

## Task workflow update - 2026-05-22T17:11:42.632Z
- Recorded fork run: i3hor7ekeqdg
- Validation: 805 tests pass, 0 deptrac violations, phpstan clean, cs-check clean; grep runtime-events.jsonl in src/ tests/ docs/ returns zero results
- Summary: Implemented by fork i3hor7ekeqdg. Commit 7a7e3bcc. 11 files changed (+46/-85). Stream subscribers wired to publish to both sink and publisher bus. All runtime-events.jsonl references deleted. RuntimeEventPoller no longer depends on HatfieldSessionStore. 805 tests pass, deptrac clean.

## Task workflow update - 2026-05-22T17:12:08.326Z
- Moved IN-PROGRESS → CODE-REVIEW.
- Pushed task/async-02-wire-publish-delete-runtime-events-jsonl to origin.
- branch 'task/async-02-wire-publish-delete-runtime-events-jsonl' set up to track 'origin/task/async-02-wire-publish-delete-runtime-events-jsonl'.
- Created PR: https://github.com/ineersa/agent-core/pull/39
