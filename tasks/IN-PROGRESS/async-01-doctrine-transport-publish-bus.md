# ASYNC-01: Doctrine SQLite transport + publish bus

## Goal
## Plan reference
`.pi/plans/async-headless-messenger-plan.md` — Phase 1

## Summary
Establish the transport layer and publish bus infrastructure. This is the foundation everything else depends on.

## Tasks
- Install `symfony/doctrine-messenger` and `doctrine/doctrine-bundle`
- Configure Doctrine DBAL with SQLite at `.hatfield/messenger.sqlite`
- Add `agent.publisher.bus` to framework.yaml messenger config
- Define four Doctrine transports: run_control, llm, tool, publish
- Create `RuntimeEventPublisherInterface` in `src/AgentCore/Contract/`
- Create `PublishRuntimeEvent` message in `src/AgentCore/Domain/Message/`
- Create `MessengerRuntimeEventPublisher` in CodingAgent implementing the interface
- Wire publisher bus transport and service alias
- Verify with `debug:messenger`

## Acceptance criteria
- `bin/console debug:messenger` shows all three buses, four transports
- `MessengerRuntimeEventPublisher` dispatches to publish transport
- `Receiver::get()` on publish transport returns dispatched messages
- All existing tests pass (sync mode unchanged)
- `castor check` passes

## Order
**First.** Everything else depends on this. No parallel implementation possible.

## Architecture note
- Interface in AgentCore (`Contract/`), implementation in CodingAgent
- Only transient streaming deltas on publish bus — canonical events stay in events.jsonl
- Three buses: command (sync), execution (async), publisher (async)

## Acceptance criteria
- debug:messenger shows 3 buses + 4 transports
- MessengerRuntimeEventPublisher dispatches to publish transport
- Receiver::get() returns dispatched messages
- castor check passes
- sync mode unchanged

## Workflow metadata
Status: IN-PROGRESS
Branch: task/async-01-doctrine-transport-publish-bus
Worktree: /home/ineersa/projects/agent-core-worktrees/async-01-doctrine-transport-publish-bus
Fork run:
PR URL:
PR Status:
Started: 2026-05-22T01:57:43.688Z
Completed:

## Work log
- Created: 2026-05-22T01:53:18.348Z

## Task workflow update - 2026-05-22T01:57:43.688Z
- Moved TODO → IN-PROGRESS.
- Created branch task/async-01-doctrine-transport-publish-bus.
- Created worktree /home/ineersa/projects/agent-core-worktrees/async-01-doctrine-transport-publish-bus.
- Copied vendor directory into /home/ineersa/projects/agent-core-worktrees/async-01-doctrine-transport-publish-bus.
- Copied .vera index into /home/ineersa/projects/agent-core-worktrees/async-01-doctrine-transport-publish-bus.
