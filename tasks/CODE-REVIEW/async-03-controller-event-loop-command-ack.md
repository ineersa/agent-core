# ASYNC-03: Controller event loop + command ACK

## Goal
## Plan reference
`.pi/plans/async-headless-messenger-plan.md` — Phase 3

## Summary
Build the controller process — a Revolt event loop that reads TUI commands, ACKs immediately, dispatches to Messenger, polls publish transport, and forwards events to TUI stdout.

## Tasks
- Implement controller event loop using Revolt (`revolt/event-loop`, already available via `symfony/tui`):
  - `EventLoop::onReadable($stdin)` for TUI commands
  - `EventLoop::repeat(0.01)` for publish transport polling
  - `EventLoop::repeat(5.0)` for consumer supervision
  - `EventLoop::onSignal()` for graceful shutdown
- Add `command_ack` runtime event type to `RuntimeEventTypeEnum`
- Controller validates commands, ACKs (accepted/rejected), dispatches to Messenger
- Controller polls publish transport `Receiver::get()`, forwards as JSONL to stdout
- Controller launches/supervises `messenger:consume` child processes
- Handle hard-cancel escalation (SIGTERM consumer on timeout)

## Acceptance criteria
- Controller accepts commands and ACKs within ~5ms
- Controller forwards publish events to stdout within ~15ms
- Controller supervises consumers (restart on crash)
- TUI works through controller (prompt → response visible)
- `command_ack` event type added
- `castor check` passes
- `castor run:agent-test` works through controller

## Order
**Second.** Depends on ASYNC-01 (needs publish transport and Messenger wiring).  
**Parallel with ASYNC-02** — controller event loop is independent of wiring publish sources.

## Key Revolt APIs
- `onReadable($stream, $callback)` — non-blocking stdin
- `repeat($interval, $callback)` — periodic polling
- `onSignal($signo, $callback)` — graceful shutdown
- `run()` / `stop()`

## Acceptance criteria
- controller accepts commands and ACKs within ~5ms
- controller forwards publish events to stdout within ~15ms
- controller supervises consumers (restart on crash)
- TUI works through controller (prompt → response visible)
- command_ack event type added to RuntimeEventTypeEnum

## Workflow metadata
Status: CODE-REVIEW
Branch: task/async-03-controller-event-loop-command-ack
Worktree: /home/ineersa/projects/agent-core-worktrees/async-03-controller-event-loop-command-ack
Fork run: slhj3ckvypgm
PR URL: https://github.com/ineersa/agent-core/pull/40
PR Status: open
Started: 2026-05-22T02:34:22.140Z
Completed:

## Work log
- Created: 2026-05-22T01:53:47.888Z

## Task workflow update - 2026-05-22T02:34:22.140Z
- Moved TODO → IN-PROGRESS.
- Created branch task/async-03-controller-event-loop-command-ack.
- Created worktree /home/ineersa/projects/agent-core-worktrees/async-03-controller-event-loop-command-ack.
- Copied vendor directory into /home/ineersa/projects/agent-core-worktrees/async-03-controller-event-loop-command-ack.
- Copied .vera index into /home/ineersa/projects/agent-core-worktrees/async-03-controller-event-loop-command-ack.

## Task workflow update - 2026-05-22T17:12:29.937Z
- Recorded fork run: slhj3ckvypgm
- Validation: 806 tests pass, 0 deptrac violations, phpstan clean, cs-check clean; bin/console agent --controller flag available; messenger.transport.publish injectable as ReceiverInterface
- Summary: Implemented by fork slhj3ckvypgm. Commit f83fb806. 6 files changed (+531/-7). Revolt event loop controller with command ACK, publish transport polling, consumer supervision. New --controller flag. InProcessAgentSessionClient used for command dispatch (blocks, but ACK emitted first). 806 tests pass, deptrac clean.

## Task workflow update - 2026-05-22T17:12:56.254Z
- Moved IN-PROGRESS → CODE-REVIEW.
- Pushed task/async-03-controller-event-loop-command-ack to origin.
- branch 'task/async-03-controller-event-loop-command-ack' set up to track 'origin/task/async-03-controller-event-loop-command-ack'.
- Created PR: https://github.com/ineersa/agent-core/pull/40
