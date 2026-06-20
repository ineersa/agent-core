# Context Compaction and `/compact` Implementation Plan

## 1. Purpose

Long-running agent sessions eventually exceed the model context window. Context compaction solves this by replacing older conversation history with a concise handoff summary while keeping the most recent messages raw.

The implementation must preserve these properties:

- The next LLM call receives useful prior context without the full old transcript.
- The user can manually run `/compact [custom instructions]`.
- The compacted state survives resume/replay/fork.
- Tool calls and tool results are never separated by the cut algorithm.
- Repeated compaction works naturally.
- Extensions can observe and optionally customize compaction, but compaction itself is a core session operation.

## 2. Target behavior

After compaction, the LLM-visible message list becomes:

```text
[compaction summary message]
[recent retained message 1]
[recent retained message 2]
...
```

The summary message contains a handoff summary of older messages. The retained tail contains recent raw messages, preserving exact local context and current work.

Example:

```text
Before compaction:
  messages[0..140] = full long conversation

After compaction:
  messages[0]      = user-role compact summary message
  messages[1..18]  = recent raw tail from the original conversation
```

Repeated compaction works because the previous summary message is part of the current message list. A second compaction summarizes the current compacted history, including the previous summary if it is outside the retained tail.

## 3. Design summary

### 3.1 Core design choices

1. **Compaction is a core run/session command.**
   - It rewrites `RunState.messages`, so it belongs in the core runtime/pipeline path.
   - Extension hooks may customize or observe compaction, but extension commands should not own the primary state rewrite.

2. **Compaction stores a replayable checkpoint event.**
   - A `context_compacted` event stores the full new compacted message list in `payload.messages`.
   - Replay treats `payload.messages` as a full replacement snapshot.

3. **Manual `/compact` is implemented first.**
   - Auto-compaction is a later phase using the same compaction service and same event model.

4. **The summary prompt is intentionally short.**
   - The prompt asks for a handoff summary, not a rigid section-by-section report.
   - It includes one repeated-compaction instruction: incorporate prior summaries if present.

5. **Auto-compaction uses a compact-after-tokens flat threshold.**
   - Auto trigger condition:

```text
estimatedContextTokens > compact_after_tokens
```

   - Default threshold: 120000 tokens.
   - Supports per-provider and per-model overrides for testing and tuning.
   - Initial settings:

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

## 4. Repository integration points

The implementation should use these existing concepts and files.

### 4.1 Run state

`src/AgentCore/Domain/Run/RunState.php`

`RunState.messages` is the hot message list used for LLM context. Compaction replaces this list with the compacted message list.

### 4.2 LLM context resolution

`src/AgentCore/Infrastructure/SymfonyAi/LlmPlatformAdapter.php`

`LlmPlatformAdapter` resolves messages for provider calls. It supports two useful paths:

- `ModelInvocationInput.messages !== null`: use the provided messages directly.
- `ModelInvocationInput.messages === null && runId !== null`: load current messages from `RunStore`.

Compaction summarization should use direct messages to avoid reading the current run state again during the summarization call.

### 4.3 Model invocation DTOs

Relevant files:

- `src/AgentCore/Domain/Model/ModelInvocationInput.php`
- `src/AgentCore/Domain/Model/ModelInvocationRequest.php`
- `src/AgentCore/Domain/Model/PlatformInvocationResult.php`
- `src/AgentCore/Contract/Model/PlatformInterface.php`

Compaction uses `PlatformInterface::invoke()` with a direct `ModelInvocationInput.messages` list and no tools.

### 4.4 Events

Relevant files:

- `src/AgentCore/Domain/Event/RunEventTypeEnum.php`
- `src/AgentCore/Domain/Event/EventFactory.php`
- `src/AgentCore/Application/Pipeline/RunCommit.php`

Compaction adds core event types and persists them through the normal commit path.

### 4.5 Replay / hot prompt state

Relevant files:

- `src/AgentCore/Application/Handler/ReplayService.php`
- `src/AgentCore/Domain/Run/PromptState.php`
- `src/AgentCore/Contract/PromptStateStoreInterface.php`

`ReplayService::replayMessages()` already supports full message replacement when an event payload contains `messages`. `context_compacted.payload.messages` should intentionally use this mechanism.

### 4.6 Runtime boundary

Relevant files:

- `src/CodingAgent/Runtime/Contract/AgentSessionClient.php`
- `src/CodingAgent/Runtime/InProcess/InProcessAgentSessionClient.php`
- `src/CodingAgent/Runtime/Process/JsonlProcessAgentSessionClient.php`
- `src/CodingAgent/Runtime/Protocol/RuntimeCommand.php`
- `src/CodingAgent/Runtime/Controller/HeadlessController.php`

The TUI should call a runtime `compact()` operation. Both in-process and JSONL process runtime paths should support it if possible in Phase 1.

### 4.7 TUI slash commands

Relevant files:

- `src/Tui/Command/SubmissionRouter.php`
- `src/Tui/Command/SlashCommandRegistry.php`
- `src/Tui/Command/SlashCommandHandler.php`
- `src/Tui/Listener/SubmitListener.php`

Add `/compact [custom instructions]` as a slash command.

### 4.8 Existing compactor services

`src/CodingAgent/Compaction/` (namespace `Ineersa\CodingAgent\Compaction`)

Production classes already implemented in COMP-01:

- `SessionCompactor` — orchestrator for preparation, summarization message construction, and compacted message construction.
- `CompactionBoundarySelector` — safe cut-point algorithm using `AgentMessageToolCallSequenceValidator`.
- `CompactionTokenEstimator` — approximate token estimation using model-facing text (UnicodeString length / 3.25).
- `ToolResultDigestService` — deterministic tool-result digestion before summarization.
- `CompactionPromptBuilder` — loads `config/COMPACTION.md` with precedence (project > home > built-in) and renders via Symfony AI `StringTemplateRenderer`.
- `CompactionPreparationDTO`, `CompactionPreparationResultDTO`, `CompactResultDTO` — data transfer objects.
- `CompactionSkipReasonEnum` — skip reasons for `prepare()` result.

Tests: `tests/CodingAgent/Compaction/SessionCompactorTest.php`, `tests/CodingAgent/Compaction/CompactionTokenEstimatorTest.php`, `tests/CodingAgent/Compaction/ToolResultDigestServiceTest.php`.

Settings: `CompactionConfig` on `AppConfig::$compaction` (`src/CodingAgent/Config/CompactionConfig.php`, tests at `tests/CodingAgent/Config/CompactionConfigTest.php`).

## 5. Prompt specification

### 5.1 Prompt template

The compaction prompt is file-backed: built-in `config/COMPACTION.md`, with precedence:

1. `<project>/.hatfield/COMPACTION.md` (project-level override)
2. `~/.hatfield/COMPACTION.md` (home-level override)
3. `config/COMPACTION.md` (built-in default)

There is no `APPEND_COMPACTION.md`.

The template uses Symfony AI `StringTemplateRenderer` and supports context placeholders:
- `{date}` — current date
- `{cwd}` — current working directory (for relative path resolution)
- `{custom_instructions_part}` — injected custom instructions from `/compact`
- `{summary_prefix}` — injected summary prefix wrapper

Implementation: `CompactionPromptBuilder` renders the resolved template file and injects placeholders. The resulting system message and user message are returned as the summarization message list.

### 5.2 Summarization system message (from COMPACTION.md)

```text
You are a context summarization assistant. Read the conversation and produce only a handoff summary.

Do not continue the conversation. Do not answer questions from the conversation. Do not call tools. Output only the summary text.
```

### 5.3 Summarization user prompt (from COMPACTION.md)

```text
You are performing a CONTEXT CHECKPOINT COMPACTION. Create a handoff summary for another LLM that will resume the task.

Include:
- Current progress and key decisions made
- Important context, constraints, or user preferences
- What remains to be done (clear next steps)
- Any critical data, examples, file paths, commands, errors, or references needed to continue

If a prior compaction summary exists in the conversation, incorporate it and preserve still-relevant facts.

Be concise, structured, and focused on helping the next LLM seamlessly continue the work.
```

### 5.4 Custom instruction handling

If the user runs:

```text
/compact summarize only database decisions
```

Append this to the summarization user prompt:

```text
Additional user instructions for this compaction:
summarize only database decisions
```

Custom instructions should narrow or emphasize the summary. They should not remove the base requirements to preserve progress, decisions, constraints, next steps, and critical references.

### 5.5 Injected summary prefix

The compact summary is injected into future LLM context as a user-role message:

```text
The conversation history before this point was compacted into the following handoff summary. Use it as prior context, not as a new user request.

<summary>
[summary text]
</summary>
```

The injected message must have metadata:

```php
['compact_summary' => true]
```

## 6. Settings specification

Add compaction settings to `config/hatfield.defaults.yaml` and document them in `docs/settings.md`.

Actual settings (implemented in COMP-01):

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

Field meanings:

| Setting | Meaning |
|---|---|
| `auto_enabled` | Controls auto-compaction only. Manual `/compact` is always available regardless of this flag. |
| `compact_after_tokens` | Flat auto-trigger threshold. When estimated context tokens exceed this value, auto-compaction is triggered. |
| `keep_recent_tokens` | Approximate number of newest tokens to retain raw after compaction. |
| `model` | Override for the summarization model. `null` uses the active session model. When set, use format `provider/model`, e.g. `llama_cpp/flash`. |
| `thinking_level` | Optional thinking/reasoning-effort level for the summarization model call. |
| `provider_overrides` | Map of provider name → compaction config override (e.g. `compact_after_tokens` per provider). |
| `model_overrides` | Map of `provider/model` string → compaction config override (e.g. `compact_after_tokens` per model). |

Per-provider and per-model overrides use the same field subset (any of `compact_after_tokens`, `model`, `thinking_level`). Resolution: model override > provider override > global.

Removed/obsolete settings (do NOT implement):
- `enabled` — replaced by `auto_enabled`; manual compaction is always available.
- `reserve_tokens` — replaced by flat `compact_after_tokens` threshold.
- `max_summary_tokens` — removed entirely.

Do not introduce percentage-threshold, minimum-turn, or cooldown settings in the initial implementation.

## 7. Event specification

### 7.1 Event types

Add these core event types in `RunEventTypeEnum`:

```php
case ContextCompactionStarted = 'context_compaction_started';
case ContextCompacted = 'context_compacted';
case ContextCompactionFailed = 'context_compaction_failed';
```

All three events are mandatory for Phase 1. They provide TUI feedback (working/status/error messages) and observability (structured logs per event).

### 7.2 `context_compaction_started` payload

```json
{
  "trigger": "manual",
  "custom_instructions": "summarize only database decisions",
  "token_estimate_before": 142000,
  "messages_total": 140,
  "messages_to_summarize": 122,
  "messages_retained": 18
}
```

### 7.3 `context_compacted` payload

```json
{
  "trigger": "manual",
  "custom_instructions": "summarize only database decisions",
  "summary_text": "...",
  "token_estimate_before": 142000,
  "token_estimate_after": 38000,
  "messages_compacted": 122,
  "messages_retained": 18,
  "first_retained_index": 122,
  "summary_message": {
    "role": "user",
    "content": [
      {
        "type": "text",
        "text": "The conversation history before this point was compacted into the following handoff summary..."
      }
    ],
    "metadata": {"compact_summary": true}
  },
  "messages": [
    {
      "role": "user",
      "content": [
        {
          "type": "text",
          "text": "The conversation history before this point was compacted into the following handoff summary..."
        }
      ],
      "metadata": {"compact_summary": true}
    },
    {
      "role": "user",
      "content": [{"type": "text", "text": "recent retained message"}]
    }
  ]
}
```

`payload.messages` is authoritative for replay and must be the complete new `RunState.messages` list.

### 7.4 `context_compaction_failed` payload

```json
{
  "trigger": "manual",
  "custom_instructions": "summarize only database decisions",
  "reason": "llm_error",
  "message": "Provider returned context window exceeded.",
  "retryable": true
}
```

Do not include raw full prompt content or raw full conversation in failure payloads/logs.

## 8. Message model

### 8.1 Summary message

Build an `AgentMessage`:

```php
new AgentMessage(
    role: 'user',
    content: [[
        'type' => 'text',
        'text' => $prefix."\n\n<summary>\n".$summaryText."\n</summary>",
    ]],
    metadata: ['compact_summary' => true],
);
```

Use user role because providers consistently accept user-role context messages. The prefix explicitly tells the model it is prior context, not a new task.

### 8.2 Compacted message list

```php
$compactedMessages = [
    $summaryMessage,
    ...$retainedTailMessages,
];
```

This list replaces `RunState.messages`.

## 9. Compaction preparation algorithm

Create a preparation step that can run without an LLM call.

Input:

- current message list,
- `keep_recent_tokens`,
- token estimator.

Output:

- messages to summarize,
- retained tail messages,
- token estimates,
- counts/indexes,
- whether a prior compact summary exists.

Algorithm:

1. Hydrate all messages into `AgentMessage` objects.
2. Estimate total tokens for current messages.
3. Walk backward from newest to oldest, accumulating approximate tokens.
4. Stop after accumulating at least `keep_recent_tokens`.
5. Move the boundary to a safe cut point.
6. Return a `CompactionPreparationResultDTO` with success/skip details:

```text
On success: result with preparation (messagesToSummarize, retainedTailMessages, tokenEstimateBefore, etc.) and skipReason = null.
On skip: result with skipReason enum (Disabled, BelowThreshold, NoSafeBoundary, InvalidSequence, NothingToCompact, TooShort) and null preparation.
```

If all messages fit inside `keep_recent_tokens`, return skip reason `BelowThreshold` and do not compact.

## 10. Safe cut-point rules

The cut point must not create invalid tool-call history.

Rules:

1. Prefer cutting before a `user` message.
2. Never retain a `tool` result if its corresponding assistant tool call was summarized away.
3. Never summarize an assistant tool-call message while retaining its tool results.
4. If the boundary falls inside a tool-call group, move the boundary earlier so the entire group is retained.
5. If the algorithm cannot prove a boundary is safe, keep more messages rather than fewer.

Phase 1 conservative approach:

- Prefer the nearest safe `user` boundary at or before the token target.
- Treat assistant/tool-call groups as indivisible.
- If no safe boundary exists, skip compaction rather than risk corrupting provider message order.

Future enhancement:

- Split-turn compaction: if a very large turn crosses the boundary, summarize the old prefix and retain the recent suffix. This is not required for Phase 1.

## 11. Compaction services (implemented)

Implemented under `Ineersa\CodingAgent\Compaction` / `src/CodingAgent/Compaction` (COMP-01).

### 11.1 `SessionCompactor` (orchestrator)

```php
final class SessionCompactor
{
    public function __construct(
        private CompactionConfig $config,
        private CompactionTokenEstimator $tokenEstimator,
        private CompactionBoundarySelector $boundarySelector,
        private ToolResultDigestService $toolResultDigestService,
        private CompactionPromptBuilder $promptBuilder,
    ) {}

    public function prepare(
        array $messages,
        ?string $activeModel,
    ): CompactionPreparationResultDTO;

    public function buildSummarizationMessages(
        CompactionPreparationDTO $preparation,
        ?string $customInstructions,
    ): array;

    public function buildCompactedMessages(
        string $summaryText,
        CompactionPreparationDTO $preparation,
    ): CompactResultDTO;
}
```

`prepare()` returns `CompactionPreparationResultDTO` with:
- `?CompactionPreparationDTO $preparation` — null on skip.
- `CompactionSkipReasonEnum $skipReason` — reason when preparation is null.

Skip reasons: `Disabled`, `BelowThreshold`, `NoSafeBoundary`, `InvalidSequence`, `NothingToCompact`.

### 11.2 `CompactionTokenEstimator`

Estimates tokens using model-facing text only — no JSON envelope. Computes `ceil(UnicodeString::length(modelFacingText) / 3.25)`.

### 11.3 `CompactionBoundarySelector`

Safe cut-point algorithm using `AgentMessageToolCallSequenceValidator` for two-layer safety: cross-boundary assistant/tool-call integrity and independent partition validity.

### 11.4 `ToolResultDigestService`

Deterministic tool-result digestion before summarization. Digest fields: tool id/name, command, exit_code (numeric-normalized), status, token/char counts, full_output path when present, important_lines_detected, preview_start/preview_end.

### 11.5 `CompactionPromptBuilder`

Loads `COMPACTION.md` with precedence (project > home > built-in), renders via Symfony AI `StringTemplateRenderer` with context placeholders `{date}`, `{cwd}`, `{custom_instructions_part}`, `{summary_prefix}`.

### 11.6 DTOs

- `CompactionPreparationDTO` — preparation data (messagesToSummarize, retainedTailMessages, estimates, counts).
- `CompactionPreparationResultDTO` — wrapper around optional preparation + skip reason.
- `CompactResultDTO` — compacted result (summaryText, summaryMessage, compactedMessages, estimates).
- `CompactionSkipReasonEnum` — skip reason enum.

## 12. Summarization model call

The summarization call uses the existing model platform, but must not expose tools or emit normal assistant stream deltas.

Request shape:

```php
$platform->invoke(new ModelInvocationRequest(
    model: $compactionModel,
    input: new ModelInvocationInput(
        runId: null,
        messages: $summarizationMessages,
        toolsRef: null,
    ),
));
```

Model resolution (via `CompactionConfig::resolveRuntimeSettings(activeModel)`):

- If `settings.model` is non-null, parse as `provider/model` and use that provider and model.
- If `settings.model` is null, use the active session provider and model.
- If `settings.thinking_level` is non-null, apply it to the summarization call.
- Per-provider and per-model overrides (`provider_overrides`, `model_overrides`) are resolved by `resolveRuntimeSettings()`: model override > provider override > global.
- The resolved `CompactionRuntimeSettingsDTO` provides `model`, `thinkingLevel`, and `compactAfterTokens`.
- The resolved model is passed as `$compactionModel` in the `ModelInvocationRequest`.

Requirements:

- `runId: null` so the adapter does not load current run messages from `RunStore`.
- `messages` explicitly contains only:
  - summarization system message,
  - messages being summarized,
  - summarization user prompt.
- No tools are available to this invocation.
- Summary result is extracted from `PlatformInvocationResult.assistantMessage` text.
- If the result has an error, compaction fails without mutating `RunState.messages`.

If current platform wiring always streams, the compaction worker can consume the stream internally, but it must not project stream deltas as normal assistant transcript output.

## 13. Core pipeline flow

Preferred implementation shape mirrors existing async LLM step handling.

### 13.1 Manual command flow

```text
TUI /compact [instructions]
  -> AgentSessionClient::compact(runId, instructions)
  -> AgentRunnerInterface::compact(runId, instructions)
  -> dispatch CompactRun command/message
```

### 13.2 Preparation and execution flow

```text
CompactRunHandler
  -> load RunState
  -> validate run exists and compaction is allowed
  -> SessionCompactor::prepare(...)
  -> if no useful compaction: emit/reply with no-op status
  -> emit context_compaction_started
  -> dispatch ExecuteCompactionStep with:
       - runId
       - state version / turn / step id
       - messagesToSummarize snapshot
       - retainedTail snapshot
       - custom instructions
       - preparation metadata

ExecuteCompactionStepWorker
  -> build summarization messages
  -> call PlatformInterface::invoke() with direct messages and no tools
  -> dispatch CompactionStepResult

CompactionStepResultHandler
  -> reload RunState
  -> verify state is still compatible with the preparation snapshot
  -> build summary message and compacted message list
  -> replace RunState.messages
  -> emit context_compacted
  -> RunCommit persists events/state and rebuilds hot prompt
```

Benefits:

- No long LLM call inside CAS retry loop.
- Compaction has start/result/failure events.
- TUI can show progress.
- State mutation happens through the normal pipeline commit path.

### 13.3 Simpler implementation option

If the full async worker/result path is too large for Phase 1, a synchronous core handler may be used. It still must:

- be a core runtime/session command,
- use CAS for state replacement,
- persist `context_compacted`,
- rebuild hot prompt state,
- avoid extension-command `map()` as the primary state rewrite path.

## 14. Runtime and TUI flow

### 14.1 Runtime API

Add to `AgentSessionClient`:

```php
public function compact(string $runId, ?string $customInstructions = null): void;
```

Phase 1 must implement in both:

- `InProcessAgentSessionClient::compact()` — calls `AgentRunnerInterface::compact()` directly.
- `JsonlProcessAgentSessionClient::compact()` — sends a JSONL `RuntimeCommand` of type `compact`.

Add JSONL runtime command type to `RuntimeCommand`:

```json
{"type":"compact","run_id":"...","custom_instructions":"..."}
```

`HeadlessController` must route the `compact` command type to `AgentRunnerInterface::compact()`.

If compaction is requested during an active run (streaming, tool-running, or mid-turn), queue the command until the next safe turn boundary. Do not reject the request. The existing PendingCommand mechanism in the command mailbox handles this naturally.

### 14.2 TUI slash command

Add `/compact` handler:

```text
/compact
/compact <custom instructions>
```

Validation:

- If no active session: status `No active session to compact.`
- If terminal run: status `Cannot compact a completed run.`
- If compaction is already running: status `Compaction already in progress.`
- If the run is active (streaming, mid-turn), the runtime queues the command; the TUI may show a queued status.
- Otherwise call `AgentSessionClient::compact()`.

During compaction:

```text
Working message: Compacting conversation...
```

After success:

```text
⧉ Conversation compacted. Token estimate: 142k -> 38k.
```

After failure (including empty summary):

```text
Compaction failed: <reason message>
```

Example failure messages:

- `Compaction failed: The model returned an empty summary.`
- `Compaction failed: The provider reported an error.`

Do not print the full summary into the normal transcript by default. It can be available in debug details or events.

## 15. Replay and hot prompt behavior

Replay must reconstruct compacted context exactly.

Expected event replay behavior:

```text
events before compaction -> build old messages
context_compacted        -> replace messages with payload.messages
later events             -> append later messages normally
```

Acceptance requirement:

- After TUI restart/resume, the run uses compacted messages.
- Forking from a compacted run uses compacted messages.

Known existing issue to address:

- Scout found `ReplayService::replayMessages()` checks `payload['assistant']`, while LLM completion events emit `payload['assistant_message']`.
- This replay bug should be fixed before or during Phase 1 because compaction relies on replay correctness.

## 16. Hooks

Hooks customize or observe core compaction. They do not replace the core compaction command.

### 16.1 Before-compaction hook

Before the LLM summarization call, extensions may:

- cancel compaction,
- provide a replacement summary,
- append additional summary instructions,
- attach metadata to the compaction event.

Conceptual contract:

```php
interface CompactionHookInterface
{
    public function beforeCompaction(CompactionPreparationDTO $preparation): CompactionHookResultDTO;
}
```

Result:

```php
CompactionHookResultDTO
  - cancelReason: ?string
  - replacementSummary: ?string
  - additionalInstructions: ?string
  - metadata: array
```

If `replacementSummary` is provided, skip the LLM call and build compacted messages from that summary.

### 16.2 After-compaction observation

Existing after-turn/event hooks can observe the committed `context_compacted` event. If a dedicated after-compaction hook is needed, add it later after the event shape stabilizes.

### 16.3 Public extension API

Do not expose new public `ExtensionApi` methods in the first implementation. Keep initial hook contracts internal until behavior stabilizes.

## 17. Auto-compaction phase

Manual compaction comes first. Auto-compaction reuses the same service and event model.

### 17.1 Trigger policy

```php
shouldCompact(estimatedContextTokens, compactAfterTokens): bool
{
    return $estimatedContextTokens > $compactAfterTokens;
}
```

The `compactAfterTokens` value comes from `CompactionConfig::resolveRuntimeSettings()`, which supports per-provider and per-model overrides. Global default: 120000.

### 17.2 Trigger points

Add auto checks at two points:

1. **After turn commit**
   - Use hot prompt token estimate.
   - If over threshold, schedule compaction.

2. **Before LLM call**
   - Last guard before model invocation.
   - If over threshold, compact first, then continue the turn.

Optional overflow recovery:

- If provider returns context-window exceeded, attempt one compaction retry.
- Use a guard to prevent infinite overflow/compact loops.

### 17.3 Auto failure handling

Initial auto implementation can be simple:

- log structured failure,
- emit `context_compaction_failed`,
- do not retry repeatedly in the same turn.

A circuit breaker can be added later if auto failures become noisy.

## 18. Error handling and logging

Failure cases:

| Failure | Behavior |
|---|---|
| Not enough messages to compact | No state mutation; show status explaining nothing to compact. |
| No safe cut point | No state mutation; emit failure/no-op event. |
| LLM summarization error | Emit `context_compaction_failed`; preserve original messages. |
| Empty summary | Always a failure. Emit `context_compaction_failed` with reason `empty_summary`. Preserve original messages unchanged. Display a user-visible error in the TUI. |
| Run cancelled during compaction | Abort; no state mutation. |
| State changed before result commit | Re-run preparation or fail safely; do not apply stale compacted messages. |

Logging requirements:

- Use structured event-style logs.
- Include `run_id`, `session_id`, `component`, `event_type`.
- Do not log raw full prompts, full session content, API keys, environment values, or full tool outputs.

## 19. Tests

Implementation touches runtime/TUI/Messenger/LLM-visible flow, so load the `testing` skill before implementation validation.

All QA/test commands must use Castor.

Required tests:

### 19.1 Unit tests

`tests/CodingAgent/Compaction/SessionCompactorTest.php`

Cover:

- preparation no-op for short sessions,
- preparation with long session,
- `prepare()` skip reasons (Disabled, BelowThreshold, NoSafeBoundary, etc.),
- summary message prefix exact text,
- custom instruction prompt exact text,
- prior compact summary included in summarization input,
- token estimate before/after.

`tests/CodingAgent/Compaction/CompactionTokenEstimatorTest.php`

`tests/CodingAgent/Compaction/ToolResultDigestServiceTest.php`

`tests/CodingAgent/Compaction/CutPointAlgorithmTest.php` (if split from SessionCompactorTest)

Cover:

- cut before user boundary,
- assistant text boundary,
- assistant tool call + tool result grouping,
- no retained orphan tool result,
- no summarized assistant tool call with retained tool result,
- no safe boundary -> no compaction.

### 19.2 Replay tests

`tests/AgentCore/Application/Handler/ContextCompactedReplayTest.php`

Cover:

- `context_compacted.payload.messages` replaces prior messages,
- later user/assistant messages append after compaction,
- compact summary metadata survives replay.

Also add or update replay tests for the existing `assistant_message` event replay issue.

### 19.3 Runtime/TUI tests

Cover:

- `/compact` slash command parsing,
- custom instructions captured,
- runtime `compact` command reaches runner,
- process JSONL `compact` command if implemented in Phase 1,
- TUI status/working message during compaction,
- success transcript block.

### 19.4 LLM smoke test

Use the llama.cpp test model/server required by project testing rules.

Cover:

- long synthetic conversation compacts into shorter context,
- summary is not empty,
- second compaction succeeds,
- subsequent LLM call can use the compacted context.

## 20. Validation commands

Use Castor only:

```bash
castor test --filter=Compaction
castor test --filter=Compact
castor deptrac
castor phpstan
castor check
```

For runtime/TUI/LLM-visible changes, `castor check` is required. If required prerequisites are unavailable, leave the task IN-PROGRESS and record the blocker.

## 21. Task Breakdown and Execution Order

Each task is a medium-sized implementation slice with its own task file, acceptance criteria, and Castor validation.

### 21.1 Task summary table

| Task | File | Scope | Dependencies | Parallel OK? |
|---|---|---|---|---|
| **COMP-00** | `tasks/TODO/comp-00-replay-foundation.md` | Fix replay `assistant_message` mismatch; add replay coverage for normal and full-message-list replacement semantics. | None | With COMP-01 |
| **COMP-01** | `tasks/TODO/comp-01-compactor-service-settings-prompt.md` | ✅ DONE. Compaction settings (`CompactionConfig` with `auto_enabled`, `compact_after_tokens`, `keep_recent_tokens`, `model`, `thinking_level`, provider/model overrides). `SessionCompactor` split into `CompactionTokenEstimator`, `CompactionBoundarySelector`, `ToolResultDigestService`, `CompactionPromptBuilder`. File-backed `COMPACTION.md` prompt. All under `Ineersa\CodingAgent\Compaction`. | None | With COMP-00 |
| **COMP-02** | `tasks/TODO/comp-02-core-compaction-pipeline-events.md` | Core pipeline handler, all three mandatory events, no-tools model invocation, `RunState.messages` replacement, replay integration. | COMP-00, COMP-01 | After both land |
| **COMP-03** | `tasks/TODO/comp-03-runtime-transports-and-tui-compact-command.md` | `AgentSessionClient::compact()` for both runtimes, JSONL protocol, HeadlessController routing, TUI `/compact` slash command, active-run queuing. | COMP-02 | With COMP-04 |
| **COMP-04** | `tasks/TODO/comp-04-compaction-hooks-and-observability.md` | Before-compaction hook contracts, hook result DTOs, after-compaction event observation, structured logging, TUI event projection. | COMP-02 | With COMP-03 |
| **COMP-05** | `tasks/TODO/comp-05-manual-compaction-e2e-validation-docs.md` | LLM smoke test, settings/user docs, E2E validation, Phase 1 acceptance checklist sign-off. | COMP-02, COMP-03, COMP-04 | After all three land |
| **COMP-06** | `tasks/TODO/comp-06-auto-compaction-reserve-token-policy.md` | Auto-compaction using `compact_after_tokens` threshold (default 120000) with per-provider/per-model overrides. After-turn and pre-LLM-call checks, overflow recovery, circuit breaker. Phase 2. | COMP-05 | After Phase 1 ships |

### 21.2 Execution graph

```text
Wave 0 (parallel)
  COMP-00 ─┬─┐
  COMP-01 ─┘ │
             │
Wave 1       │
  COMP-02 ◄──┘  (depends on COMP-00 + COMP-01)
       │
       ├──────────────┐
       │              │
Wave 2 (parallel)     │
  COMP-03 ◄───────────┤  (depends on COMP-02)
  COMP-04 ◄───────────┘  (depends on COMP-02, coordinate event/runtime names with COMP-03)
       │
Wave 3 │
  COMP-05 ◄── COMP-02 + COMP-03 + COMP-04  (integration, E2E, docs, smoke)
       │
Wave 4 (Phase 2)
  COMP-06 ◄── COMP-05  (auto-compaction on stable manual foundation)
```

### 21.3 Coordination notes

- COMP-03 and COMP-04 run in parallel after COMP-02 lands. Both must agree on runtime event names (`context_compaction_started`, `context_compacted`, `context_compaction_failed`), the `AgentSessionClient::compact()` signature, and JSONL `RuntimeCommand` wire format. Implementors should coordinate these before starting.
- COMP-05 depends on COMP-04 only if hooks emit events that runtime/TUI projection must surface in the final E2E validation. If hooks are deferred to a later Phase 1 follow-up, COMP-05 can gate on COMP-02 + COMP-03 only.
- All tasks must load the `testing` skill before validation because compaction touches runtime, TUI, Messenger, and LLM-visible flow.
- Each task's acceptance criteria are authoritative for its scope. This breakdown references them; it does not duplicate them.

## 22. Phased implementation

### Phase 0: Replay prerequisite

- ✅ COMP-00 DONE — Fixed `assistant_message` replay mismatch. Replay now consumes canonical `payload.assistant_message` and supports full `payload.messages` replacement for `context_compacted` checkpoints.

Acceptance:

- Replay from `events.jsonl` reconstructs normal run messages correctly.

### Phase 1: Manual `/compact`

Implement:

- compaction settings including configurable `model` override, `thinking_level`, per-provider/per-model overrides (✅ COMP-01 already done — `CompactionConfig` on `AppConfig::$compaction`),
- `SessionCompactor` and sibling services: `CompactionTokenEstimator`, `CompactionBoundarySelector`, `ToolResultDigestService`, `CompactionPromptBuilder` (✅ COMP-01 already done),
- summarization prompt via file-backed `config/COMPACTION.md` with precedence (✅ COMP-01 already done),
- no-tools model invocation with model resolution including `thinking_level` from `CompactionConfig::resolveRuntimeSettings()`,
- core compaction command/pipeline,
- all three mandatory events: `context_compaction_started`, `context_compacted`, `context_compaction_failed`,
- `RunState.messages` replacement,
- runtime `compact()` API in both `InProcessAgentSessionClient` and `JsonlProcessAgentSessionClient`,
- JSONL `compact` command and `HeadlessController` routing,
- active-run queuing to next safe turn boundary,
- TUI `/compact [instructions]` with user-visible errors on failure,
- replay tests,
- unit tests,
- LLM smoke test.

Acceptance:

- `/compact` reduces token estimate.
- `/compact <instructions>` passes custom instructions to summarizer.
- Next LLM call uses compacted context.
- Second `/compact` works naturally.
- Resume/replay preserves compacted context.
- Tool calls/results are not split.
- Both in-process and process/JSONL runtime paths work.
- Compaction during an active run queues until the next safe boundary.
- Configurable `model` override works when set.
- All three events are emitted in correct order.
- Empty summaries fail and show a TUI error.
- `castor check` passes.

### Phase 2: Auto-compaction

Implement:

- after-turn threshold check using `compact_after_tokens` (default 120000) with per-provider/per-model overrides,
- pre-LLM-call threshold check,
- optional overflow recovery with one-attempt guard,
- auto compaction events and TUI feedback.

Acceptance:

- Long sessions compact automatically when estimated tokens exceed `compact_after_tokens`.
- Auto-compaction does not loop indefinitely.
- Manual `/compact` still works.

### Phase 3: Enhancements

Potential future work:

- split-turn prefix summarization,
- file-operation metadata,
- public extension API hooks,
- auto failure circuit breaker,
- dedicated summarization model override,
- compact summary debug viewer.

## 23. Resolved decisions

These decisions are definitive for Phase 1 implementation.

### 23.1 Phase 1 runtime scope

Phase 1 MUST support both runtime paths:

- `InProcessAgentSessionClient::compact()` calls `AgentRunnerInterface::compact()` directly.
- `JsonlProcessAgentSessionClient::compact()` sends a JSONL `compact` command.
- `HeadlessController` must route the `compact` command type.

### 23.2 Compaction model selection

The summarization model defaults to the active session model.

The model is configurable via the `compaction.model` setting:

```yaml
compaction:
    model: null           # null = use session model
    # model: llama_cpp/flash  # override: provider/model
```

Model resolution in code:
1. If `compaction.model` is a non-null string, parse as `provider/model`.
2. If null, use the current session provider and model.

### 23.3 Manual compaction during active run

Compaction requested during an active run (streaming, tool-running, mid-turn) must queue until the next safe turn boundary. Do not reject the request.

The existing PendingCommand mechanism in the command mailbox is the natural queuing approach.

### 23.4 Event granularity

All three event types are mandatory:

- `context_compaction_started` — emitted before the LLM summarization call.
- `context_compacted` — emitted after successful state replacement.
- `context_compaction_failed` — emitted on any compaction failure.

### 23.5 Empty summary behavior

An empty or whitespace-only summary from the model is always a failure.

- Emit `context_compaction_failed` with reason `empty_summary`.
- Preserve original `RunState.messages` unchanged.
- Display a user-visible error in the TUI: `Compaction failed: The model returned an empty summary.`
- Do not use fallback text.
