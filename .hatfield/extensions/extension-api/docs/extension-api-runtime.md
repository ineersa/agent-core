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

`CommandDefinitionDTO(name, aliases = [], description = '', usage = '', acceptsArguments = false)`
describes the command. Handlers implement `ExtensionCommandHandlerInterface::handle(string $args,
CommandContextInterface $context): void`; `$args` is the trimmed text after the command name.
The context exposes only `notify(string $message, string $level = 'info')` with levels
`info`, `success`, `warning` and `error`.

Registration throws `InvalidArgumentException` when the name or any alias equals a name or alias
that is already registered, including every built-in command name and alias such as `resume`,
`r`, `q`, `quit`, `h` and `?`; the extension then fails to load. `code-review`, prompt-template
and `skill:` command names are not reserved: they are added after extensions load and skip any
name an extension already registered.

## Prompt contributors

`registerPromptContributor()` takes a `PromptContributorInterface`. Its `contribute(): string`
runs once per prompt build and its markdown is appended after static host append content;
return an empty string to contribute nothing.

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

No shell string evaluation. `ExecOptionsDTO` also accepts `env` (merged over the inherited
environment) and a `cancellationToken`. `ExecResultDTO` exposes `stdout`, `stderr`,
`exitCode`, `timedOut` and `cancelled`; check them rather than assuming success.

## After-turn commit hooks

`registerAfterTurnCommitHook()` runs when a turn reaches a stable committed boundary
(useful for checkpoints, indexes, side ledgers). Implement
`AfterTurnCommitHookInterface::onAfterTurnCommit(AfterTurnCommitHookContextDTO $context)`.
The context carries `runId`, `turnNo`, `status`, `effectsCount` and `events`: the committed
batch as `AfterTurnCommitEventSummaryDTO` (`seq`, `type`, `payload`, nullable `turnNo` and
ISO-8601 `createdAt`) — not private host services. Delivery is best effort; repair gaps
from `sessionEvents()` during recovery or compaction.

## Session-start hooks

`registerSessionStartHook()` takes an `AfterSessionStartHookInterface`; its
`onAfterSessionStart(AfterSessionStartHookContextDTO $context)` (carrying the session
`runId`) runs once when an interactive controller session starts,
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

`sessionEvents()` returns a `SessionEventReaderInterface`. `readRange(string $runId,
int $startSeq, int $endSeq): iterable` yields `SessionEventDTO` (`runId`, `seq`, `turnNo`,
`type`, `payload`, `createdAt`) with inclusive bounds; unknown or empty sessions yield
nothing, and read failures throw `SessionEventReaderException`. Implementations may scan
the whole log: use it for recovery/compaction catch-up, not on every turn, and not as a
substitute for missing public APIs.

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

`registerBeforeCompactionHook()` takes a `BeforeCompactionHookInterface`;
`beforeCompaction(BeforeCompactionHookContextDTO): BeforeCompactionHookResultDTO` runs
synchronously before summarization. The context is scalar only: `runId`, `turnNo`, `trigger`,
token and message counts, prior-summary flag, custom instructions, model, thinking level and
a paired `requiredStartSeq`/`requiredEndSeq` coverage watermark (both `null` for snapshot or
fork compaction; see `hasCoverageWatermark()`). Return `continue()`, `cancel($reason)`,
`replaceSummary($text)`, or construct the DTO with `additionalInstructions` and JSON-safe
`metadata`. Do not reach into compaction internals.

## Agent runner and async jobs

- `agent()` → `AgentRunnerInterface`. `run(AgentCallRequestDTO $request): void` blocks until the call and its tool loop finish; failures throw. `contextWindow(string $exactModel): ?int` reads the configured window; treat `null` as a configuration error, not a default.
- `AgentCallRequestDTO` requires `model` (an exact configured `provider/model`; `@alias` forms are rejected), `sessionId`, `instructions` and `input`. Optional `tools` is a list of `AgentToolDTO(name, description, parametersJsonSchema, handler)` with an `ExtensionToolHandlerInterface` handler, available only to that call. Optional `correlationId` tags logs; `maxToolCalls` (default 50) bounds the tool loop.
- Optional `AgentCallRequestDTO::maxDurationSeconds` sets both Symfony HttpClient idle `timeout` and total `max_duration` for that call only. Omit it to keep the shared LLM client defaults.
- Optional `AgentCallRequestDTO::thinkingLevel` overrides Hatfield thinking for that call only (`off`, `minimal`, `low`, `medium`, `high`, `xhigh`, `max`). PHP callers migrating from strings should pass `Ineersa\Hatfield\ExtensionApi\Agent\ThinkingLevelEnum` cases, e.g. `thinkingLevel: ThinkingLevelEnum::Off` instead of `thinkingLevel: 'off'`. Configuration and wire values remain strings. Pass `null` or omit the argument to keep session/default reasoning. An explicit `Off` may add provider disable options only when the model catalog declares a supported `thinking_format` (for example `llama_cpp` → `chat_template_kwargs.enable_thinking=false`). Session/default `off` without this field does not add that flag.
- `registerExtensionAgentJobHandler($id, $handler)` + `dispatchExtensionAgentJob($request)` for asynchronous extension jobs. `ExtensionAgentJobRequestDTO(handlerId, payload = [], jobId = null, correlationId = null)` rejects non-JSON-safe payloads. The worker calls `ExtensionAgentJobHandlerInterface::handle(ExtensionApiInterface $api, array $payload, ?string $jobId, ?string $correlationId)` with its own process-local API.

Failures are bounded to the job/call; do not assume shared mutable memory across host processes.

## Related

- Tools/hooks: [extension-api-tools.md](extension-api-tools.md)
- TUI: [extension-api-tui.md](extension-api-tui.md)
- Overview: [extension-api.md](extension-api.md)
