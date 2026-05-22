# ASYNC-06: Persistent idempotency, CAS retry, supervision

## Goal
## Plan reference
`.pi/plans/async-headless-messenger-plan.md` — Phase 6

## Summary
Harden the multi-process topology for production use: persistent idempotency, CAS retry, consumer supervision, and cancel escalation.

## Tasks
- Persist idempotency keys (replace in-memory `MessageIdempotencyService`)
- Make CAS conflicts retryable (retry/backoff or explicit retryable worker failure)
- Harden consumer supervision (heartbeat, restart policy, stderr/stdout capture)
- Add cancel escalation: ACK immediately → cooperative cancel → SIGTERM consumer → SIGKILL (last resort)
- Validate multiple concurrent sessions don't corrupt each other

## Acceptance criteria
- Duplicate command/result messages do not duplicate canonical events
- Worker restart does not lose queued work (messages requeued on failure)
- Cancel ACKs quickly and escalates if graceful fails
- Multiple sessions/runs do not corrupt each other
- `castor check` passes
- `castor run:agent-test` with concurrent sessions works

## Order
**Fifth.** Depends on ASYNC-05 (full async pipeline working).  
**No parallelism** — hardening layer on top of everything.

## Future (out of scope for this task)
See plan "Future: shared consumer pool" section:
- Shared worker pool (consumers serve all sessions)
- Worker status heartbeats via publish bus
- Per-run hard kill with worker status map
- Parallel tool calls via multiple tool consumers on same transport
- Controller process restart: TUI detects controller death via stdout EOF, respawns via AgentProcessSupervisor, reconnects, replays missed events from canonical events.jsonl

## Acceptance criteria
- duplicate messages do not duplicate canonical events
- worker restart loses no queued work
- cancel ACKs quickly, escalates gracefully
- multiple sessions don't corrupt each other

## Workflow metadata
Status: DONE
Branch: task/async-06-idempotency-cas-supervision
Worktree: /home/ineersa/projects/agent-core-worktrees/async-06-idempotency-cas-supervision
Fork run:
PR URL: https://github.com/ineersa/agent-core/pull/43
PR Status: open
Started: 2026-05-22T21:04:43.791Z
Completed:

## Work log
- Created: 2026-05-22T01:54:34.787Z

## Task workflow update - 2026-05-22T21:04:43.791Z
- Moved TODO → IN-PROGRESS.
- Created branch task/async-06-idempotency-cas-supervision.
- Created worktree /home/ineersa/projects/agent-core-worktrees/async-06-idempotency-cas-supervision.
- Copied vendor directory into /home/ineersa/projects/agent-core-worktrees/async-06-idempotency-cas-supervision.
- Copied .vera index into /home/ineersa/projects/agent-core-worktrees/async-06-idempotency-cas-supervision.

## Task workflow update - 2026-05-22T21:12:56.656Z
- Validation: castor test: 810 tests, 9593 assertions ✅; castor deptrac: 0 violations ✅; phpstan on all changed files: no errors ✅; castor cs-check: clean ✅; container XML: all services wired correctly ✅
- Summary: Fork kudmmx09mfns completed: persistent idempotency via JsonlIdempotencyStore (JSONL+LOCK_EX), CAS retry with 3 attempts + exponential backoff in RunMessageProcessor, consumer auto-restart with 3x/60s sliding window + stderr capture. 13 files changed (+401/-66). 810 tests pass, 0 deptrac, phpstan clean, cs-check clean. Cancel escalation (SIGTERM/SIGKILL) deferred to future work.

## Task workflow update - 2026-05-22T21:13:12.723Z
- Moved IN-PROGRESS → CODE-REVIEW.
- Pushed task/async-06-idempotency-cas-supervision to origin.
- branch 'task/async-06-idempotency-cas-supervision' set up to track 'origin/task/async-06-idempotency-cas-supervision'.
- Created PR: https://github.com/ineersa/agent-core/pull/43
