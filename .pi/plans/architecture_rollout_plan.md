# Architecture Rollout Plan

> **Status:** Phase 1–2 implemented (boundary + DTOs + in-process client + single command + deptrac).
> Phase 3–5 (process transport, full TUI screens, subagent reuse) are next.
> Deviations from plan: commands squashed into single `agent` command (`--headless` flag instead of separate `agent:headless`).
> In-process transport is the default and headless mode delegates to it internally.

## Goal

Keep the codebase flexible enough to support both fast in-process development and process-isolated headless/subagent execution without letting presentation code bypass runtime boundaries.

Core principle:

> TUI is a client. Coding Agent app is the runtime. Agent Core is the engine.

## Target layers

```text
packages/agent-core/
  Pure agent loop, domain model, commands, events, stores, replay, outbox.
  No TUI. No process supervision. No Symfony app assumptions.
  No HttpKernel or FrameworkBundle dependency.

packages/tui-bundle/
  Reusable Symfony TUI integration.
  Widgets, themes, keybindings, focus, rendering helpers.
  No agent orchestration or application workflows.

apps/coding-agent/
  Symfony 8.1 HTTP-less runtime application.
  Owns CLI commands, session dirs, extension loading, tool registry, process supervision.
  Can run interactive TUI, in-process runtime, headless JSONL runtime, and subagent workers.
```

## Runtime boundary

The TUI must never call agent-core directly. It should depend on a runtime client boundary only.

Suggested interface shape:

```php
interface AgentSessionClient
{
    public function start(StartRunRequest $request): RunHandle;
    public function resume(string $runId): RunHandle;
    public function send(string $runId, UserCommand $command): void;

    /** @return iterable<AgentRuntimeEvent> */
    public function events(string $runId): iterable;

    public function cancel(string $runId): void;
}
```

The app should provide at least two implementations:

```text
InProcessAgentSessionClient
  Calls the runtime/application services directly but still maps everything through the runtime protocol DTOs.

JsonlProcessAgentSessionClient
  Starts `bin/console agent:headless --jsonl`, writes protocol commands to stdin, reads protocol events from stdout.
```

The TUI depends only on `AgentSessionClient`, so transport can be switched without changing UI logic.

## Proposed app module layout

```text
apps/coding-agent/src/
  CLI/
    AgentChatCommand.php
    AgentHeadlessCommand.php
    AgentRunCommand.php
    AgentResumeCommand.php

  Runtime/
    Contract/
      AgentSessionClient.php
      RunHandle.php

    Protocol/
      RuntimeCommand.php
      RuntimeEvent.php
      JsonlCodec.php
      RuntimeEventMapper.php

    InProcess/
      InProcessAgentSessionClient.php

    Process/
      JsonlProcessAgentSessionClient.php
      AgentProcessSupervisor.php

  TUI/
    InteractiveMode.php
    Screens/
    Widgets/
```

## JSONL protocol

Treat JSONL as the canonical runtime protocol, even when the default development transport is in-process.

### Client/TUI -> runtime

```json
{"v":1,"id":"cmd_1","type":"start_run","payload":{"prompt":"...","cwd":"..."}}
{"v":1,"id":"cmd_2","type":"user_message","runId":"run_123","payload":{"text":"..."}}
{"v":1,"id":"cmd_3","type":"cancel","runId":"run_123"}
```

### Runtime -> client/TUI

```json
{"v":1,"type":"run_started","runId":"run_123","seq":1}
{"v":1,"type":"message_delta","runId":"run_123","seq":12,"payload":{"text":"hello"}}
{"v":1,"type":"tool_started","runId":"run_123","seq":18,"payload":{"name":"read"}}
{"v":1,"type":"run_finished","runId":"run_123","seq":99}
```

Protocol rules:

- stdout is protocol only.
- stderr is logs/debug output.
- every event has `runId`, `seq`, and `type`.
- every command has `id`.
- runtime sends command ACK/error events.
- runtime emits heartbeat events for health checks.
- persisted event log is the source of truth, not TUI state.
- in-process mode must emit the same normalized `RuntimeEvent` DTOs as process mode.

## CLI modes

```bash
agent:chat --transport=in-process
agent:chat --transport=process
agent:headless --jsonl
agent:run
agent:resume
```

Recommended rollout order:

1. Define `AgentSessionClient`, request/handle/command/event DTOs, and `JsonlCodec`.
2. Implement `InProcessAgentSessionClient` first for fast development.
3. Make `InteractiveMode` use only `AgentSessionClient`.
4. Add `agent:headless --jsonl` around the same runtime boundary.
5. Add `JsonlProcessAgentSessionClient` and `AgentProcessSupervisor`.
6. Add `--transport` selection to `agent:chat`.
7. Reuse the process transport for future subagents.

## Boundary enforcement

Use architecture checks such as Deptrac and/or Rector/custom static-analysis rules to prevent accidental boundary violations.

### Intended dependency rules

```text
apps/coding-agent/src/TUI
  may depend on:
    - App\Runtime\Contract
    - App\Runtime\Protocol
    - Ineersa\TuiBundle
    - Symfony\Component\Tui
    - Symfony\Component\Console

  must not depend on:
    - Ineersa\AgentCore\Application
    - Ineersa\AgentCore\Infrastructure
    - Symfony\Component\Messenger
    - application runtime internals outside App\Runtime\Contract and App\Runtime\Protocol

packages/tui-bundle
  may depend on:
    - Symfony\Component\Tui
    - Symfony\Component\Console
    - Symfony\Component\DependencyInjection

  must not depend on:
    - App\*
    - Ineersa\AgentCore\*
    - Symfony\Component\HttpKernel
    - Symfony\Bundle\FrameworkBundle

packages/agent-core
  may depend on:
    - Symfony components needed by the core/runtime contracts, e.g. console, messenger, dependency-injection, event-dispatcher

  must not depend on:
    - App\*
    - Ineersa\TuiBundle\*
    - Symfony\Component\Tui
    - Symfony\Component\HttpKernel
    - Symfony\Bundle\FrameworkBundle
```

### Suggested Deptrac layers

```yaml
layers:
  - name: AppTui
    collectors:
      - type: directory
        value: apps/coding-agent/src/TUI/.*

  - name: AppRuntimeContract
    collectors:
      - type: directory
        value: apps/coding-agent/src/Runtime/(Contract|Protocol)/.*

  - name: AppRuntimeInternals
    collectors:
      - type: directory
        value: apps/coding-agent/src/Runtime/(InProcess|Process)/.*

  - name: AgentCoreApplication
    collectors:
      - type: classLike
        value: Ineersa\\AgentCore\\Application\\.*

  - name: AgentCoreInfrastructure
    collectors:
      - type: classLike
        value: Ineersa\\AgentCore\\Infrastructure\\.*

  - name: TuiBundle
    collectors:
      - type: classLike
        value: Ineersa\\TuiBundle\\.*

  - name: SymfonyTui
    collectors:
      - type: classLike
        value: Symfony\\Component\\Tui\\.*

  - name: ForbiddenHttp
    collectors:
      - type: classLike
        value: (Symfony\\Component\\HttpKernel\\.*|Symfony\\Bundle\\FrameworkBundle\\.*)

ruleset:
  AppTui:
    - AppRuntimeContract
    - TuiBundle
    - SymfonyTui

  TuiBundle:
    - SymfonyTui

  AgentCoreApplication: []
  AgentCoreInfrastructure: []
```

This is only a starting sketch; wire it to the actual Deptrac version/config syntax when adding the tool.

## Contract tests

Add a shared test suite that runs against all `AgentSessionClient` implementations.

Example matrix:

```text
AgentSessionClientContractTest
  - InProcessAgentSessionClient
  - JsonlProcessAgentSessionClient
```

Test expectations:

- start emits `run_started`.
- user message produces ordered message events.
- cancel emits cancellation/finalization event.
- invalid command emits protocol error.
- events are ordered by `seq`.
- reconnect/resume behavior is consistent.
- both implementations expose the same `RuntimeEvent` stream for the same scenario.

## Design guardrails

- Do not let `TUI/` receive raw `RunEvent`, command bus, stores, or agent-core services.
- Do not let `packages/tui-bundle` know about agent-core or app-specific sessions.
- Keep protocol DTOs boring, serializable, and versioned.
- Keep stdout clean in process mode; logs go to stderr.
- Prefer in-process mode for development speed, but keep it behaviorally equivalent to JSONL mode.
- Any future subagent should be spawned through the same process runtime protocol, not through a special side channel.
