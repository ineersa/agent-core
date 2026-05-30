# OBS-01 Structured run observability and Datadog-ready tracing

## Goal
# OBS-01 Structured run observability and Datadog-ready tracing

## Context

Local Datadog log collection/APM is now useful for visually inspecting Hatfield runs, but the application logs are not yet consistently correlated across the async runtime, Messenger workers, tools, LLM calls, TUI polling/rendering, and persistence. The next observability improvement should keep local JSONL logs as the source of truth while making them Datadog-friendly via stable fields, channels, event-style messages, spans, and metrics.

## Goals

Make it easy to answer, from either `castor log:*` or Datadog:

- What is this `run_id` doing right now?
- Which queue/handler/worker is processing it?
- Which LLM request, tool call, or persistence operation is slow/failing?
- Where did a run get stuck?
- Which errors belong to the same run/session/trace?

## Required implementation work

### 1. Add automatic correlation context for all logs

Implement a central logging context mechanism so call sites do not have to remember every field manually.

Recommended shape:

- Add a small runtime logging context service/value object, e.g. `RunLogContext`, `RuntimeLogContext`, or similarly explicit semantic name.
- Add a Monolog processor that injects active context into every record.
- Context must be scoped safely for long-lived CLI/Messenger workers; avoid leaking a previous run into the next message. Use explicit enter/leave/reset semantics, `try/finally`, or immutable context propagation.

Fields to include when known:

- `run_id`
- `session_id` (currently same as run id, but keep field explicit)
- `trace_id` / `span_id` when ddtrace provides them
- `command_id` or protocol command id where applicable
- `message_id` / Messenger envelope id where available
- `event_id` where available
- `event_type`
- `handler`
- `queue`
- `worker`
- `attempt`
- `retry_count`
- `component`
- `tool.name`
- `model`
- `provider`
- `outcome`

### 2. Introduce subsystem channels/facets

Use Monolog channels and/or a stable `component` field for major subsystems. Prefer same JSONL file initially; do not split files unless there is a clear reason.

Suggested channels/components:

- `runtime`
- `messenger`
- `llm`
- `tool`
- `tui`
- `storage`
- `extension`
- `safeguard`

Ensure the channel/component is visible in JSONL and therefore available as a Datadog facet.

### 3. Normalize important messages into event-style names

Replace or supplement prose logs around important runtime events with stable event names suitable for search and dashboards.

Examples:

- `run.start.received`
- `run.started`
- `run.advance.started`
- `run.advance.completed`
- `run.advance.failed`
- `messenger.message.received`
- `messenger.message.handled`
- `messenger.message.failed`
- `llm.request.started`
- `llm.request.completed`
- `llm.request.failed`
- `llm.stream.first_token`
- `tool.execute.started`
- `tool.execute.completed`
- `tool.execute.failed`
- `tool.approval.waiting`
- `persistence.cas_retry`
- `event_store.appended`
- `tui.events.polled`
- `tui.frame.rendered`

Keep log context structured; do not embed important IDs only inside message strings.

### 4. Add spans around high-value runtime phases

Add optional tracing spans in a Datadog-compatible way without making the core architecture depend on Datadog-specific APIs everywhere. Prefer an abstraction/service if direct ddtrace calls would spread through the codebase. If ddtrace is unavailable, tracing must degrade to no-op without errors.

Suggested spans:

- `hatfield.run.start`
- `hatfield.run.advance`
- `hatfield.messenger.handle`
- `hatfield.llm.request`
- `hatfield.llm.stream`
- `hatfield.tool.execute`
- `hatfield.tool.approval_wait`
- `hatfield.persistence.cas_retry`
- `hatfield.event_store.append`
- `hatfield.tui.poll_events`
- `hatfield.tui.render_frame`

Tags to set when known:

- `run.id`
- `session.id`
- `handler`
- `queue`
- `worker`
- `model`
- `provider`
- `tool.name`
- `event.type`
- `retry.count`
- `outcome`
- `error.type`

### 5. Add lightweight metrics where practical

If there is an existing metrics path, use it. Otherwise add a narrow abstraction that can no-op locally and later emit to DogStatsD/Datadog.

Prioritize metrics for:

- run duration
- LLM request latency
- time to first token
- tool duration
- tool failure count
- CAS retry count
- queue lag / message age where available
- pending messages per queue if cheaply observable
- TUI event poll lag
- events written per run
- token counts in/out if available without logging raw content

### 6. Preserve privacy and log safety

Do not log raw prompts, raw tool output, raw environment variables, API keys, or full session content by default.

Prefer:

- byte/character counts
- item counts
- hashes when useful
- short previews only behind an explicit debug setting
- existing/redaction-compatible structured fields

Any caught exception must either be propagated or logged with diagnostics, per AGENTS.md. Empty catch blocks are forbidden.

### 7. Keep JSONL local logs correct

The existing Monolog JSONL output must remain valid: one JSON object per line in `.hatfield/logs/agent-YYYY-MM-DD.log`.

Validate that:

- `castor log:tail` still works
- `castor log:search` still works
- Datadog Agent can still tail the file config under `ops/datadog/hatfield.d/conf.yaml`

### 8. Documentation and examples

Update relevant docs:

- `docs/datadog.md` with new facets/tags/span names and recommended Datadog searches.
- `docs/settings.md` if any settings are added.
- Optionally add a short observability section to README or an observability doc if the implementation is large.

Document recommended Datadog facets:

- `run_id`
- `session_id`
- `channel`
- `component`
- `event_type`
- `handler`
- `queue`
- `tool.name`
- `model`
- `provider`
- `outcome`

## Likely code areas to inspect

- `config/packages/monolog.yaml`
- `src/CodingAgent/Logging/HatfieldRotatingLogHandler.php`
- `src/CodingAgent/Config/LoggingConfig.php`
- `src/AgentCore/Application/Pipeline/RunMessageProcessor.php`
- `src/AgentCore/Application/Handler/*RunHandler.php` and other `RunMessageHandler` implementations
- Runtime protocol/controller code under `src/CodingAgent/Runtime/`
- LLM integration services under `src/CodingAgent/` / `src/AgentCore/`
- Tool execution pipeline/services
- Event store/session persistence services
- TUI polling/rendering boundary (`AgentSessionClient`, `RuntimeEventPoller`, transcript projection/rendering)

## Architecture constraints

- Preserve boundaries in `AGENTS.md` and `depfile.yaml`.
- Do not make `src/AgentCore/` depend on `src/CodingAgent/` or Datadog-specific implementation classes.
- If tracing is needed in core flows, use a narrow interface/port owned by the appropriate layer and wire implementation from the app/container side.
- Do not add HTTP routes/controllers/session/profiler features.
- Prefer Symfony-native Monolog processors/services/subscribers over ad-hoc global state.
- Do not add production APIs solely for tests.

## Suggested implementation sequence

1. Map current logging/spans and identify critical run lifecycle points.
2. Add context service + Monolog processor + tests for context reset/leak prevention.
3. Add context scopes around Messenger message handling and run processing.
4. Normalize high-value log messages to event-style names.
5. Add tracing abstraction and no-op implementation; add ddtrace implementation only when extension/functions are available.
6. Instrument LLM/tool/persistence/TUI hotspots.
7. Add metrics abstraction/emission if practical within the task; otherwise create follow-up task with concrete plan.
8. Update docs and Datadog search examples.
9. Run required validation.

## Validation

Use Castor as required by AGENTS.md; do not use raw `vendor/bin/*` as the primary QA path.

Required before handoff:

- `castor test`
- `castor deptrac`
- `castor phpstan`
- `castor cs-check`
- `castor check`

Also validate local observability behavior:

- Start or use local Datadog Agent if available.
- `castor datadog:status`
- `castor datadog:smoke-log`
- Run at least one agent/controller flow and confirm JSONL logs include `run_id`/`session_id`/component fields.
- Confirm traces/spans appear when ddtrace + Agent are available, and no errors occur when disabled via `HATFIELD_DATADOG=0`.

If `castor check` cannot run because tmux or llama.cpp on port 9052 is unavailable, keep the task in progress and record the exact blocker output.

## Acceptance criteria
- Every log emitted during a run has automatic correlation context (`run_id` and `session_id`) whenever that context is known, without leaking stale context between runs/messages.
- Important runtime/Messenger/LLM/tool/persistence/TUI logs use stable event-style message names and structured fields rather than IDs embedded only in prose.
- Monolog JSONL remains valid one-object-per-line and existing `castor log:*` commands continue to work.
- Subsystem channel/component fields are present and suitable for Datadog facets.
- Tracing is optional/no-op when ddtrace is unavailable or Datadog is disabled, and emits useful spans/tags when enabled.
- Privacy is preserved: no raw prompts, API keys, environment variables, or full tool/session output are logged by default.
- Docs describe the new fields, recommended Datadog facets/searches, and any new settings.
- Architecture boundaries remain valid under `castor deptrac`.
- Required Castor validation is run and reported, including `castor check` or exact environmental blocker output.

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
- Created: 2026-05-30T18:25:32.218Z
