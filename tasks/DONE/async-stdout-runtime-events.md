# Replace Doctrine publish transport with STDOUT streaming for runtime events

## Goal
## Problem
Transient runtime events (LLM streaming deltas — thinking, text, tool-call arguments) currently go through Doctrine SQLite publish transport. Each delta = serialize → INSERT → poll → SELECT → ack → DELETE. This is too slow and heavy for high-throughput streaming (hundreds of deltas/sec).

## Solution
LLM consumer writes transient runtime events as JSONL to STDOUT. Controller reads from LLM child's stdout pipe (via Symfony Process `getIncrementalOutput()` or Revolt `onReadable()`) and forwards to controller's own stdout → TUI.

## What changes

### Delete
- `PublishTransportPoller` — no more Doctrine polling
- `PublishRuntimeEvent` message class
- `MessengerRuntimeEventPublisher` — no more Messenger dispatch
- `RuntimeEventPublisherInterface` in AgentCore/Contract — nothing in AgentCore uses it
- `agent.publisher.bus` from messenger config
- `publish` Doctrine transport from messenger config

### Modify
- `AssistantTextStreamSubscriber` — write JSONL to `php://stdout` instead of `$this->runtimeEventPublisher->publish()`
- `AssistantThinkingStreamSubscriber` — same
- `ToolCallStreamSubscriber` — same
- `HeadlessController` — replace `PublishTransportPoller` with `onReadable()` on LLM child's stdout pipe, forward to controller stdout
- `ConsumerSupervisor` — may need to expose child process stdout pipe reference
- `messenger.yaml` — remove publisher bus and publish transport

### Keep unchanged
- `RuntimeEventSinkInterface` / `InMemoryRuntimeEventSink` — in-process mode still uses sink
- Canonical events through `events.jsonl` — RunCommit → controller drain (already works)
- Tool execution events — canonical only, flow through events.jsonl
- `agent.command.bus` and `agent.execution.bus` — unchanged
- `RuntimeEventTypeEnum` — unchanged

## Event flow after change

```
Canonical events (seq > 0):
  RunCommit → events.jsonl → controller drain (50ms poll) → stdout → TUI

Transient streaming deltas (seq = 0):
  Stream subscribers → STDOUT in LLM consumer process
    → controller reads child stdout pipe (onReadable)
    → controller forwards to own stdout → TUI
```

## Acceptance criteria
- Streaming thinking/text/tool-call deltas appear in TUI in real-time
- No Doctrine publish transport for runtime events
- `agent.publisher.bus` removed from config
- In-process mode still works (uses sink, not STDOUT)
- All tests pass, deptrac clean, phpstan clean
- castor run:agent-test shows streaming with real LLM

## Acceptance criteria
- No PublishRuntimeEvent, PublishTransportPoller, MessengerRuntimeEventPublisher, or agent.publisher.bus in codebase
- Stream subscribers write JSONL to STDOUT instead of Doctrine publish
- Controller reads LLM child stdout via onReadable and forwards to TUI
- castor run:agent-test shows real streaming thinking/text in TUI
- castor test passes, deptrac clean, phpstan clean, cs-check clean

## Workflow metadata
Status: DONE
Branch: task/async-stdout-runtime-events
Worktree: /home/ineersa/projects/agent-core-worktrees/async-stdout-runtime-events
Fork run: n3l3bqdzrx97
PR URL: https://github.com/ineersa/agent-core/pull/44
PR Status: merged
Started: 2026-05-23T04:27:28.409Z
Completed: 2026-05-23T19:25:33.657Z

## Work log
- Created: 2026-05-23T04:27:03.231Z

## Task workflow update - 2026-05-23T04:27:28.409Z
- Moved TODO → IN-PROGRESS.
- Created branch task/async-stdout-runtime-events.
- Created worktree /home/ineersa/projects/agent-core-worktrees/async-stdout-runtime-events.
- Copied vendor directory into /home/ineersa/projects/agent-core-worktrees/async-stdout-runtime-events.
- Copied .vera index into /home/ineersa/projects/agent-core-worktrees/async-stdout-runtime-events.

## Task workflow update - 2026-05-23T04:49:55.601Z
- Recorded fork run: n3l3bqdzrx97
- Validation: 806 tests, 9575 assertions pass; 0 deptrac violations; phpstan clean on Runtime and AgentCore; cs-check clean; debug:messenger shows 2 buses only (command + execution); messenger:stats shows 3 transports (run_control, llm, tool), no publish; grep for deleted classes returns zero results
- Summary: Fork n3l3bqdzrx97 completed: replaced Doctrine publish transport with STDOUT streaming. 16 files changed (+179/-365), 4 deleted. Stream subscribers now write JSONL to STDOUT via StdoutRuntimeEventSink. Controller polls LLM child stdout at 10ms via getIncrementalOutput(). Publisher bus, transport, PublishTransportPoller, MessengerRuntimeEventPublisher all removed. 806 tests pass, deptrac/phpstan/cs-check clean. Awaiting manual castor run:agent-test validation.

## Task workflow update - 2026-05-23T17:49:09.667Z
- Moved IN-PROGRESS → CODE-REVIEW.
- Pushed task/async-stdout-runtime-events to origin.
- branch 'task/async-stdout-runtime-events' set up to track 'origin/task/async-stdout-runtime-events'.
- Created PR: https://github.com/ineersa/agent-core/pull/44

## Task workflow update - 2026-05-23T19:25:33.657Z
- Moved CODE-REVIEW → DONE.
- Merged task/async-stdout-runtime-events into integration checkout.
- Merge made by the 'ort' strategy.
 .castor/tasks.php                                  |  17 +
 .env                                               |   3 +-
 AGENTS.md                                          |  81 ++-
 config/packages/messenger.yaml                     |  19 +-
 config/services.yaml                               |  33 +-
 depfile.yaml                                       |   2 +-
 docs/async-runtime-architecture.md                 | 734 +++++++++++++++++++++
 .../Contract/RuntimeEventPublisherInterface.php    |  30 -
 .../Domain/Message/PublishRuntimeEvent.php         |  31 -
 .../Controller/CommandHandler/StartRunHandler.php  |  17 +-
 .../CommandHandler/UserMessageHandler.php          |  25 +-
 .../Runtime/Controller/ConsumerSupervisor.php      |  52 +-
 .../Runtime/Controller/HeadlessController.php      | 136 +++-
 .../Runtime/Controller/PublishTransportPoller.php  |  75 ---
 .../Process/JsonlProcessAgentSessionClient.php     | 142 +++-
 .../AssistantStreamProjectionSubscriber.php        |  12 +
 .../Publish/MessengerRuntimeEventPublisher.php     |  47 --
 .../Stream/AssistantTextStreamSubscriber.php       |  25 +-
 .../Stream/AssistantThinkingStreamSubscriber.php   |  21 +-
 .../Runtime/Stream/StdoutRuntimeEventSink.php      |  65 ++
 .../Runtime/Stream/ToolCallStreamSubscriber.php    |  19 +-
 src/Tui/Runtime/RuntimeEventPoller.php             |   3 +-
 .../Infrastructure/SymfonyAi/LlamaCppSmokeTest.php |  69 +-
 .../Runtime/Controller/E2E/ControllerSmokeTest.php | 580 ++++++++++++++++
 24 files changed, 1913 insertions(+), 325 deletions(-)
 create mode 100644 docs/async-runtime-architecture.md
 delete mode 100644 src/AgentCore/Contract/RuntimeEventPublisherInterface.php
 delete mode 100644 src/AgentCore/Domain/Message/PublishRuntimeEvent.php
 delete mode 100644 src/CodingAgent/Runtime/Controller/PublishTransportPoller.php
 delete mode 100644 src/CodingAgent/Runtime/Publish/MessengerRuntimeEventPublisher.php
 create mode 100644 src/CodingAgent/Runtime/Stream/StdoutRuntimeEventSink.php
 create mode 100644 tests/CodingAgent/Runtime/Controller/E2E/ControllerSmokeTest.php
- Removed worktree /home/ineersa/projects/agent-core-worktrees/async-stdout-runtime-events.
- Pulled integration checkout: Merge made by the 'ort' strategy..
