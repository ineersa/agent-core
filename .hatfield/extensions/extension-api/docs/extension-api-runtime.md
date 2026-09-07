---
builtin: true
description: Commands, prompts, exec, session events, compaction hooks, lifecycle, and agent jobs.
---

# Extension Runtime Capabilities

## Slash commands

```php
$api->registerCommand(
    new CommandDefinitionDTO(name: 'rewind', description: '…', usage: '/rewind'),
    $handler, // ExtensionCommandHandlerInterface
);
```

Handlers receive `CommandContextInterface` for notifications and host interactions exposed publicly.

## Prompt contributors

`registerPromptContributor()` appends markdown to the system prompt after static host append content.

## Exec

```php
$result = $api->exec()->exec(
    'git',
    ['status', '--short'],
    new ExecOptionsDTO(
        cwd: $api->getCwd(),
        timeout: 30,
    ),
);
```

No shell string evaluation. Check exit codes via `ExecResultDTO`.

## After-turn commit hooks

`registerAfterTurnCommitHook()` runs when a turn reaches a stable committed boundary
(useful for checkpoints, indexes, side ledgers). Context includes event summaries — not private host services.

## Session-start hooks

`registerSessionStartHook()` runs once when an interactive controller session starts,
before the event loop and before any turn. Use it for startup background work that must
call `dispatchExtensionAgentJob()` on the async extension_agent transport. The TUI parent
process remains fail-closed for that dispatch path.

## Session events

`sessionEvents()` returns a `SessionEventReaderInterface` for canonical session events.
Treat access as recovery/read-only analytics, not a substitute for missing public APIs.

## Compaction hooks

`registerBeforeCompactionHook()` can contribute notes or influence compaction input via
`BeforeCompactionHookResultDTO` within the public contract. Do not reach into compaction internals.

## Agent runner and async jobs

- `agent()` → `AgentRunnerInterface` for isolated agent calls with explicit provider/model and tool lists as required by the DTO contracts.
- `registerExtensionAgentJobHandler($id, $handler)` + `dispatchExtensionAgentJob($request)` for asynchronous extension jobs with JSON-safe payloads.

Failures are bounded to the job/call; do not assume shared mutable memory across host processes.

## Related

- Tools/hooks: [extension-api-tools.md](extension-api-tools.md)
- TUI: [extension-api-tui.md](extension-api-tui.md)
- Overview: [extension-api.md](extension-api.md)
