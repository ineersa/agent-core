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
Status: TODO
Branch:
Worktree:
Fork run:
PR URL:
PR Status:
Started:
Completed:

## Work log
- Created: 2026-05-22T01:53:47.888Z
