---
builtin: true
description: Commands, prompts, exec, session events, compaction hooks, lifecycle, and agent jobs.
---

# Extension Runtime Capabilities

## Slash commands

```php
$api->registerCommand(
    new CommandDefinitionDTO(name: 'review', description: 'Review the current session', usage: '/review'),
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

## Run identity

`isChildRun($runId)` returns true for a known named subagent or fork, false only
for a known main run. Persisted sessions use the authoritative session registry
(including fork parentage); native children use the operational relationship
projection. No canonical transcripts are read, so it is suitable for hot hooks
and queued-job gates. Persisted main sessions remain identifiable during controller
startup, which clears disposable projections before invoking session-start hooks.
This also permits startup jobs to execute before run-control projection recovery.
Unknown identity or unavailable storage throws; never treat a failed lookup as
main-run permission. Recheck eligibility when a queued job executes as well as
when it is dispatched. Parent-side fork snapshot hooks carry the parent's run ID.

## Session events

`sessionEvents()` returns a `SessionEventReaderInterface` for canonical session events.
Treat access as recovery/read-only analytics, not a substitute for missing public APIs.

## Selected session and live events

`session()` returns a `SessionContextInterface` for the session selected in the
interactive process: `getSessionId()` (empty when none is selected),
`getSessionDisplayTitle()` (canonical picker title or `null`), and
`getBackgroundSnapshot()`. The snapshot has separate counts of nonterminal owned
subagents and background scripts, and the pending schedules (`ScheduleDTO`: ID,
interval label, recurrence flag and nullable next run time; no tools, arguments,
prompts or commands). A zero count means none; `null` counts or schedules mean
inventory is unavailable. Each call reads storage, so do not call it in a loop.
Other processes have no selected session.

To observe the selected session, implement Symfony's `EventSubscriberInterface`
on the extension class and subscribe to the data-only classes in
`Session\Event`. The host attaches the subscriber when it loads the extension.
CodingAgent dispatches these events only in the interactive process, from the
live event stream of the selected session's main run:

| Event | When |
|---|---|
| `SessionSelectedEvent` | A new or resumed session is selected, or a draft obtains its session ID. A new run in the selected session does not select again. |
| `SessionClosingEvent` | Final graceful interactive quit of the selected session. Not for drafts, switches, reloads or abrupt exits. |
| `SessionTitleChangedEvent` | The selected session's display title changed outside a turn, e.g. by `/rename`. Carries the new title. |
| `TurnStartedEvent` | A main turn started; internal model or tool steps of one response also start turns. `trigger` is `schedule` for scheduled work. |
| `TurnCompletedEvent` / `TurnFailedEvent` / `TurnCancelledEvent` | The main run completed, or a main turn or run failed or was cancelled. |
| `AssistantMessageCompletedEvent` | A main assistant message completed. `text` is its full visible text, with separate text blocks joined by a blank line; empty for tool-only messages. |
| `ToolStartedEvent` / `ToolFinishedEvent` | A main tool execution started, or completed, failed, was cancelled or acknowledged. Tool inputs and results are not included. |
| `InputRequestedEvent` / `InputResolvedEvent` | A question or approval of the main run, or of the child named by `childRunId`, is pending or resolved. Match them by `requestId`. |
| `ChildExecutionStartedEvent` / `ChildExecutionEndedEvent` | A child run owned by the session started or ended. `sequence` is the child-local order. |
| `BackgroundJobStartedEvent` / `BackgroundJobEndedEvent` | A background job owned by the session (native owner `ownerRunId`) started or ended, also while the session is idle. |

Each event carries the session ID; main-run events also carry the canonical
sequence. Bootstrap history, streaming deltas and other sessions are never
dispatched. A terminal main event does not prove that background work settled.

Listeners run synchronously on the interactive event loop: only record state,
and move I/O or slow work into an `Amp\async()` fiber or an event-loop timer.
A listener exception is logged and does not stop delivery of that event to other listeners or of later events.

## Compaction hooks

`registerBeforeCompactionHook()` can contribute notes or influence compaction input via
`BeforeCompactionHookResultDTO` within the public contract. Do not reach into compaction internals.

## Agent runner and async jobs

- `agent()` → `AgentRunnerInterface` for isolated agent calls with explicit provider/model and tool lists as required by the DTO contracts.
- Optional `AgentCallRequestDTO::maxDurationSeconds` sets both Symfony HttpClient idle `timeout` and total `max_duration` for that call only. Omit it to keep the shared LLM client defaults.
- Optional `AgentCallRequestDTO::thinkingLevel` overrides Hatfield thinking for that call only (`off`, `minimal`, `low`, `medium`, `high`, `xhigh`, `max`). PHP callers migrating from strings should pass `Ineersa\Hatfield\ExtensionApi\Agent\ThinkingLevelEnum` cases, e.g. `thinkingLevel: ThinkingLevelEnum::Off` instead of `thinkingLevel: 'off'`. Configuration and wire values remain strings. Pass `null` or omit the argument to keep session/default reasoning. An explicit `Off` may add provider disable options only when the model catalog declares a supported `thinking_format` (for example `llama_cpp` → `chat_template_kwargs.enable_thinking=false`). Session/default `off` without this field does not add that flag.
- `registerExtensionAgentJobHandler($id, $handler)` + `dispatchExtensionAgentJob($request)` for asynchronous extension jobs with JSON-safe payloads.

Failures are bounded to the job/call; do not assume shared mutable memory across host processes.

## Related

- Tools/hooks: [extension-api-tools.md](extension-api-tools.md)
- TUI: [extension-api-tui.md](extension-api-tui.md)
- Overview: [extension-api.md](extension-api.md)
