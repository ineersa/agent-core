---
description: Context compaction settings, /compact command behavior, events, and validation.
---

# Context Compaction

Context compaction replaces older conversation history with a concise handoff summary while keeping the most recent messages raw. This prevents sessions from exceeding the model context window without losing the accumulated knowledge and decisions.

## Overview

After a long session, the LLM-visible message list becomes:

```text
[summary message — "The conversation history before this point was compacted…"]
[recent retained message 1]
[recent retained message 2]
…
```

The summary message contains a handoff summary of older messages. The retained tail preserves exact local context and current work so the next turn starts with both historical knowledge and precise recent details.

Repeated compaction works naturally: a second `/compact` treats the existing compact summary as part of the current message list and incorporates it into the new summary.

The compaction algorithm never splits tool-call/tool-result groups. If the cut point would orphan a tool call or its result, the algorithm moves the boundary earlier so the entire group stays together. Leading system and user-context messages (project instructions, injected AGENTS.md content) are preserved raw and never summarized.

## Manual command

```
/compact [custom instructions]
```

Alias: `/cmp`

Manual compaction is always available for parent sessions regardless of the `compaction.auto_enabled` setting.

**Fork and subagent child runs do not compact.** Children are identified by `RunStarted` metadata `session.kind=agent_child` (forks also set `child_kind=fork`). Automatic after-turn compaction, pre-LLM threshold compaction, and manual/API `CompactRun` are all unavailable for those runs. A child completes, hands off, or fails at the provider context limit. Parent automatic and manual compaction is unchanged. Parent-side fork snapshot compaction (`CompactionService::compactMessages` with trigger `fork` before launch) remains supported and is not gated by the child-run policy.

**Provider context-limit errors do not trigger compaction.** If the model provider rejects a request as context-too-large, the run fails through the ordinary LLM error path. Hatfield does not schedule compaction recovery, retry via compaction, or rewrite/hide that failure for parent or child runs. Compaction still occurs only via manual `/compact` (parents) or automatic threshold-based scheduling (parents).

**Custom instructions** are optional. When provided, they are appended to the summarization prompt to narrow or emphasize the summary:

```
/compact summarize only database decisions
```

### User-visible behavior

| Scenario | TUI output |
|----------|-----------|
| No active session | `No active session to compact.` |
| Compaction already in progress | `Compaction already in progress.` |
| Compaction starts | Progress block: `Compacting conversation...` |
| Compaction succeeds | `⧉ Conversation compacted. Token estimate: 142k → 38k.` |
| Compaction fails | `✕ Compaction failed: <reason>` |

Failure reasons include:

- `Compaction failed: there is not enough older context outside the retained tail to summarize.` (token estimate below `keep_recent_tokens`)
- `Compaction failed: no safe boundary found without splitting tool-call results.`
- `Compaction failed: The model returned an empty summary.`
- `Compaction failed: The summarization model returned an unexpected error.`
- `Compaction result is no longer relevant — the conversation has moved on.`

### Queueing and safe-boundary behavior

When `/compact` is requested during an active run (streaming, tool-running, or mid-turn), the command is queued via the command mailbox and applied at the next safe turn boundary — it is not rejected. The TUI shows the progress block immediately.

The compaction algorithm:

- Prefers cutting before a user message when possible.
- Never splits assistant tool-call/tool-result groups.
- Keeps leading system and user-context messages raw (immutable prologue).
- Falls back to the nearest safe boundary if the preferred cut point is inside a tool-call group.

If the queueing mechanism is unavailable (e.g. the run has already completed), compaction dispatches directly.

## Settings

All settings live under the `compaction` key:

```yaml
compaction:
    auto_enabled: true
    compact_after_tokens: 120000
    keep_recent_tokens: 20000
    model: null
    thinking_level: null
    provider_overrides: {}
    model_overrides: {}
```

| Setting | Default | Description |
|---------|---------|-------------|
| `auto_enabled` | `true` | Controls auto-compaction only for parent sessions. Manual `/compact` remains available for parents. Fork/subagent child runs never compact (auto or manual). |
| `compact_after_tokens` | `120000` | Flat token threshold for auto-compaction trigger. When estimated context exceeds this, auto-compaction fires. |
| `keep_recent_tokens` | `20000` | Approximate number of newest tokens to retain raw after compaction. Messages are kept whole — the actual retained count may modestly exceed this target. |
| `model` | `null` | Summarization model override in `provider/model` format (e.g. `llama_cpp/flash`). When `null`, the active session model is used. |
| `thinking_level` | `null` | Thinking/reasoning level for summarization calls. Typical values: `off`, `minimal`, `low`, `medium`, `high`, `xhigh`. When `null`, the session's active thinking level is used. |
| `provider_overrides` | `{}` | Per-provider overrides. Keys are provider IDs (e.g. `openai`, `llama_cpp`). Each entry may set `compact_after_tokens`, `model`, and/or `thinking_level`. |
| `model_overrides` | `{}` | Per-model overrides. Keys are `provider/model` strings (e.g. `openai/gpt-4.1`). Each entry may set `compact_after_tokens`, `model`, and/or `thinking_level`. |

**Precedence:** model override > provider override > global setting.

**Obsolete settings to avoid:** There is no `enabled`, `reserve_tokens`, or `max_summary_tokens` key. These were removed during design and are not recognized.

### Example with overrides

```yaml
compaction:
    auto_enabled: true
    compact_after_tokens: 120000
    keep_recent_tokens: 20000
    model: null
    thinking_level: null
    provider_overrides:
        openai:
            compact_after_tokens: 120000
            model: openai/gpt-4.1-mini
            thinking_level: low
    model_overrides:
        openai/gpt-4.1:
            compact_after_tokens: 140000
            thinking_level: off
```

## Prompt template

The compaction prompt is file-backed with precedence:

1. `<project>/.hatfield/COMPACTION.md` (project-level override)
2. `~/.hatfield/COMPACTION.md` (home-level override)
3. `config/COMPACTION.md` (built-in default)

The template uses the following placeholders:

| Placeholder | Source |
|-------------|--------|
| `{date}` | Current date |
| `{cwd}` | Working directory |
| `{custom_instructions_part}` | Custom instructions from `/compact <text>` or compaction hooks |

The built-in prompt instructs the summarization model to respond with text only (no tools), then produce a structured handoff summary with numbered sections: primary request/intent; key context and decisions; files, code, commands, and results; errors and fixes; progress and current work; pending tasks; and an optional next step. It requires merging prior compaction summaries when present, and favors exact paths, commands, errors, and user quotes over vague paraphrase. The runtime wraps the model's summary text in a separate user-message prefix/suffix (`SessionCompactor`); that wrapper is not part of the template file.

## Model and tool behavior

### Model selection

Compaction defaults to the active session model unless overridden:

1. If `compaction.model` is set to a non-null `provider/model` string, that model is used.
2. If `compaction.model` is `null`, the current session provider and model are used.
3. Per-provider and per-model overrides in `provider_overrides` and `model_overrides` take precedence over the global `model` setting when they match.

### Thinking level

`compaction.thinking_level` is Hatfield/CodingAgent configuration passed through as generic model options to the summarization call. It is not an AgentCore domain concept.

### Tools during summarization

Summarization runs with tools disabled (`toolsEnabled: false`). No tool descriptions are injected, even if generic model options contain a `tools` key. The summarization prompt explicitly instructs the model not to call tools.

## Events

### Core events (persisted in `events.jsonl`)

| Event | When |
|-------|------|
| `context_compaction_started` | Emitted before the LLM summarization call |
| `context_compacted` | Emitted after successful state replacement |
| `context_compaction_failed` | Emitted on any compaction failure |

`context_compacted.payload.messages` is the authoritative full replacement message list. Replay uses this to reconstruct the compacted conversation exactly.

### Runtime/TUI events (projected from core events)

| Event | When |
|-------|------|
| `compaction.started` | TUI shows "Compacting conversation..." progress block |
| `compaction.completed` | TUI shows "Conversation compacted." with token estimates |
| `compaction.failed` | TUI shows error block with friendly reason |

Runtime events are projected from core events via `RuntimeEventTranslator` and surfaced in the TUI through `CompactionProjectionSubscriber`.

## Failure and error behavior

All failure paths preserve the original message list unchanged. `context_compaction_failed.payload.messages_replaced` is `false` on failures.

Common failure reasons:

| Reason | Description |
|--------|-------------|
| `too_few_messages` | Not enough messages to attempt compaction. |
| `below_keep_recent_tokens` | The token estimate is below `keep_recent_tokens` — all messages fit in the retained tail. |
| `no_boundary` | Could not determine a boundary for the retained tail. |
| `no_safe_boundary` | No safe boundary exists without splitting tool-call results. |
| `empty_summary` | The model returned an empty or whitespace-only summary. Always treated as a failure. |
| `model_error` | The summarization model call failed (provider error, timeout, etc.). |
| `stale_result` | The compaction result arrived after the run state changed (turn advanced, run ended). |
| Hook cancel | A `BeforeCompactionHookInterface` implementation requested cancellation (reason prefixed with `hook_cancelled:`). |

### Privacy

Failure payloads and structured logs do **not** include:

- Raw prompts or summarization messages
- Full session content
- API keys or provider credentials
- Provider base URLs
- Raw tool output

Logs carry correlation fields (`run_id`, `session_id`, `component`, `event_type`) for observability without leaking sensitive data.

## Hooks and observability

### Before-compaction hooks

There are two related hook surfaces:

1. **Internal tagged hooks** (`Ineersa\CodingAgent\Compaction\BeforeCompactionHookInterface`) — not exposed through `ExtensionApi`. Used by both CompactRun and snapshot paths. Best-effort isolation: exceptions are logged and other hooks continue.
2. **Public before-compaction hooks** (`Ineersa\Hatfield\ExtensionApi\Compaction\BeforeCompactionHookInterface` via `registerBeforeCompactionHook`) — one public hook set for both CompactRun and snapshot/fork. CompactRun supplies the session-global paired coverage watermark (`requiredStartSeq`..`requiredEndSeq`, MVP `1..RunState.lastSeq`). Snapshot/fork (`CompactionService::compactMessages`) supplies `null`/`null`. Fail closed on exception (cancel / hard-fail, no silent LLM fallback). Structural snapshot no-ops (`too_few_messages`, `below_keep_recent_tokens`, …) skip hooks entirely and leave the original messages unchanged.

Internal and public hooks share the same aggregation rules (first cancel wins; first non-empty replacement wins; instructions append; metadata shallow-merges). Each may:

- **Cancel** compaction with a reason (CompactRun emits `context_compaction_failed` with `hook_cancelled:` prefix; snapshot returns `MessageSnapshotCompactionResult::failed`).
- **Provide a replacement summary** (skips the LLM call).
- **Append additional instructions** to the summarization prompt (merged in order).
- **Attach sanitized metadata** to the compaction event (CompactRun path).

Internal hooks are dispatched via `CompactionHookDispatcher`. Public hooks are aggregated by `ExtensionCompactionHookDispatcher` after internal hooks (`dispatchForCompactRun` / `dispatchForSnapshot` only differ in the optional watermark). Hook metadata is sanitized before event/transport persistence — objects, resources, and closures are silently dropped.

### After-compaction observation

Existing after-turn hooks (subscribers of `AfterTurnCommitHookContext`) receive committed `context_compacted` events in the events array, enabling observation without a separate after-compaction hook contract.

### Structured logging

`CompactRunHandler` and `CompactionStepResultHandler` emit structured logs with correlation fields (`run_id`, `session_id`, `component`, `event_type`) for each compaction lifecycle stage. Logs do not include raw prompts, full summaries, or provider URLs.

## Existing validation coverage

The following tests exercise compaction. No new tests are added by this documentation update — the coverage described below already exists.

### Live LLM smoke

There is no dedicated live compaction controller smoke in the `llm-real` group. Compaction async LLM summarization is covered by `ExecuteCompactionStepWorkerTest`, `CompactionStepResultHandlerTest`, and replay-backed controller/TUI E2E tests (`ControllerReplayAutoCompaction*`, `TuiCompactCommandVirtualTest`, `TuiAutoCompactionE2eTest`). A prior `CompactionLiveSmokeTest` was removed because it was not deterministic under `castor check` (stall or upstream HTTP 500 on large summarization bodies).

### Deterministic TUI proof

- `TuiCompactCommandVirtualTest` (unit/virtual, `castor test`): exercises `/compact` through `VirtualTuiHarness` for progress and completed transcript blocks without tmux.
- `TuiAutoCompactionE2eTest` / `TuiAutoCompactionCancelE2eTest` (`#[Group('tui-e2e-replay')]`): real interactive TUI (`TmuxHarness`) with replay-backed fixtures for auto-compaction visibility and Escape cancel. Run with:

  ```bash
  castor test --filter=TuiCompactCommandVirtualTest
  castor test:tui --filter=TuiAutoCompactionE2eTest
  ```

### Unit and integration tests

| Test | Coverage |
|------|----------|
| `SessionCompactorTest` | Preparation algorithm, boundary selection, message construction, prologue handling, below-threshold skip |
| `CompactionTokenEstimatorTest` | Token estimation accuracy |
| `CompactRunHandlerTest` | Handler flow, hook integration (cancel, replacement, instructions, metadata) |
| `CompactionStepResultHandlerTest` | Result handling, stale detection, error classification |
| `ExecuteCompactionStepWorkerTest` | Worker execution, model invocation |
| `ExecuteCompactionStepSerializerTest` | Message DTO serialization round-trip |
| `CompactHandlerTest` | JSONL runtime command routing |
| `CompactionHookDispatcherTest` | Hook dispatch, aggregation, ordering, sanitization |
| `CompactionConfigTest` | Configuration resolution, per-provider/per-model overrides |

### Process/JSONL runtime

The `CompactHandler` in `src/CodingAgent/Runtime/Controller/CommandHandler/` routes JSONL `compact` command types through the headless controller to the same `AgentRunnerInterface::compact()` path used by the in-process client. This path is exercised indirectly by `CompactHandlerTest`.

## Observational Memory compaction (optional extension)

When `Ineersa\HatfieldExt\ObservationalMemory\ObservationalMemoryExtension` is enabled,
CompactRun-only public hooks can replace the normal LLM summary path with OM active
memory. See `.hatfield/extensions/observational-memory/README.md` and
`docs/settings.md` → `extensions.settings.observational_memory`.

Normative behavior for implementors (summary):

- **Hot path (Pi-style):** public CompactRun hook and public snapshot/fork hook both open OM SQLite and deterministically render current durable active memory (`listActiveReflections` + `listActiveCandidateObservations` → `ActiveMemoryRenderer`). Non-empty → `replaceSummary`; empty → `continue()` so core keep-recent / normal summary (CompactRun) or ordinary model snapshot compaction (fork) may run. No Observer catch-up, Reflector, `extension_agent` dispatch, poll, fingerprint, or timeout on either hook path. CompactRun receives the coverage watermark; snapshot hooks do not.
- **Async consolidation:** turn-boundary Observer remains async and event-backed; Reflector runs only after Observer when active candidate observations exceed `reflect_after_observation_tokens` (default 40000). Consolidation finishing later affects a later compaction.
- **Transport:** Hatfield-managed single FIFO `extension_agent` queue for Observer/threshold Reflector only, Symfony native default `max_retries: 3` (4 attempts total), no failure transport. Exhausted jobs emit sanitized `extension_agent.job_failed` TUI Error blocks when `run_id` is present.
- **Settings:** nested keys only (`observer.*`, `reflector.*`, `pools.*`); envelopes use `floor(context_window * context_window_ratio)` (default 0.65). No `compaction.wait_timeout_seconds`, no `compaction.mode`, no compaction-model inheritance.
- **Hard failure:** DB/read/render failures on the OM hook cancel CompactRun with a privacy-safe reason (no raw exception text) and preserve original context — no silent LLM summary fallback after an extension failure.
- **Commands / recall:** `/om-status`, `/om-view`, and permanent ambient `recall` tool are extension-owned UX on top of OM SQLite + public session event reads (session-global, non-branch-aware).

## Manual smoke checklist

Concise steps to verify compaction manually:

1. **Deterministic TUI proof (virtual + auto-compaction tmux):**
   ```bash
   castor test --filter=TuiCompactCommandVirtualTest
   castor test:tui --filter=TuiAutoCompactionE2eTest
   ```

2. **Full QA gate (deterministic, before PR):**
   ```bash
   castor check
   ```
   This runs all deterministic tests including replay-backed TUI E2E and controller E2E. It does **not** include live LLM smoke.

3. **Manual TUI exploration:**
   ```bash
   castor run:agent-test
   ```
   Type `/compact` with a session that has at least a few turns. A short session will fail with "below keep recent tokens" — this is expected structural behavior, not a bug. To see a successful compaction, you need a session whose estimated token count exceeds `keep_recent_tokens` (default 20000).
