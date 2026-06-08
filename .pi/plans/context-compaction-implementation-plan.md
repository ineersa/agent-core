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

5. **Auto-compaction uses a reserve-token policy.**
   - Auto trigger condition:

```text
estimatedContextTokens > contextWindow - reserveTokens
```

   - Initial defaults:

```yaml
compaction:
    enabled: true
    reserve_tokens: 16384
    keep_recent_tokens: 20000
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

### 4.8 Existing compactor stub

`src/CodingAgent/Session/SessionCompactor.php`

This file already exists as a stub and should become the main service for compaction preparation, cut-point selection, prompt construction, and compacted message construction.

## 5. Prompt specification

### 5.1 Summarization system message

```text
You are a context summarization assistant. Read the conversation and produce only a handoff summary.

Do not continue the conversation. Do not answer questions from the conversation. Do not call tools. Output only the summary text.
```

### 5.2 Summarization user prompt

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

### 5.3 Custom instruction handling

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

### 5.4 Injected summary prefix

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

Initial settings:

```yaml
compaction:
    enabled: true
    reserve_tokens: 16384
    keep_recent_tokens: 20000
    max_summary_tokens: null
```

Field meanings:

| Setting | Meaning |
|---|---|
| `enabled` | Enables manual compaction and, later, auto-compaction. |
| `reserve_tokens` | Tokens reserved for the next model response. Used by auto trigger policy. |
| `keep_recent_tokens` | Approximate number of newest tokens to retain raw after compaction. |
| `max_summary_tokens` | Optional cap for summary generation. If null, use `floor(reserve_tokens * 0.8)`. |

Auto-compaction phase may later add:

```yaml
compaction:
    auto_enabled: true
```

Auto trigger policy remains:

```text
estimatedContextTokens > contextWindow - reserveTokens
```

Do not introduce percentage-threshold, minimum-turn, or cooldown settings in the initial implementation.

## 7. Event specification

### 7.1 Event types

Add these core event types in `RunEventTypeEnum`:

```php
case ContextCompactionStarted = 'context_compaction_started';
case ContextCompacted = 'context_compacted';
case ContextCompactionFailed = 'context_compaction_failed';
```

If implementation scope needs to be smaller, `context_compacted` is the required event; start/failure events are recommended for observability and TUI feedback.

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
6. Return:

```text
messagesToSummarize = messages[0 .. boundary-1]
retainedTailMessages = messages[boundary .. end]
```

If all messages fit inside `keep_recent_tokens`, return no preparation and do not compact.

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

## 11. `SessionCompactor` service

Implement `src/CodingAgent/Session/SessionCompactor.php` as the algorithm and prompt-construction service.

Suggested responsibilities:

```php
final class SessionCompactor
{
    public function prepare(
        array $messages,
        CompactionSettingsDTO $settings,
    ): ?CompactionPreparationDTO;

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

Keep algorithmic code unit-testable without a real LLM.

Suggested DTOs:

```php
CompactionSettingsDTO
  - enabled: bool
  - reserveTokens: int
  - keepRecentTokens: int
  - maxSummaryTokens: ?int

CompactionPreparationDTO
  - messagesToSummarize: list<AgentMessage>
  - retainedTailMessages: list<AgentMessage>
  - tokenEstimateBefore: int
  - messagesCompacted: int
  - messagesRetained: int
  - firstRetainedIndex: int
  - priorSummaryPresent: bool

CompactResultDTO
  - summaryText: string
  - summaryMessage: AgentMessage
  - compactedMessages: list<AgentMessage>
  - tokenEstimateBefore: int
  - tokenEstimateAfter: int
  - messagesCompacted: int
  - messagesRetained: int
  - firstRetainedIndex: int
```

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

Implement in:

- `InProcessAgentSessionClient`
- `JsonlProcessAgentSessionClient`

Add JSONL runtime command type:

```json
{"type":"compact","run_id":"...","custom_instructions":"..."}
```

The headless controller must route this to `AgentRunnerInterface::compact()`.

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
- Otherwise call `AgentSessionClient::compact()`.

During compaction:

```text
Working message: Compacting conversation...
```

After success:

```text
⧉ Conversation compacted. Token estimate: 142k -> 38k.
```

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
shouldCompact(contextTokens, contextWindow, reserveTokens): bool
{
    return $contextTokens > ($contextWindow - $reserveTokens);
}
```

### 17.2 Trigger points

Add auto checks at two points:

1. **After agent end / after turn commit**
   - Use hot prompt token estimate.
   - If over threshold, schedule compaction.

2. **Before LLM call / before prompt submission**
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
| Empty summary | Use fallback text `(The model did not produce a summary.)` or treat as failure. Prefer failure for Phase 1. |
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

`tests/CodingAgent/Session/SessionCompactorTest.php`

Cover:

- preparation no-op for short sessions,
- preparation with long session,
- summary message prefix exact text,
- custom instruction prompt exact text,
- prior compact summary included in summarization input,
- token estimate before/after.

`tests/CodingAgent/Session/CutPointAlgorithmTest.php`

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

## 21. Phased implementation

### Phase 0: Replay prerequisite

- Fix or account for `assistant_message` replay mismatch.
- Add replay tests proving normal assistant messages are reconstructed from events.

Acceptance:

- Replay from `events.jsonl` reconstructs normal run messages correctly.

### Phase 1: Manual `/compact`

Implement:

- compaction settings,
- `SessionCompactor` preparation and message construction,
- summarization prompt construction,
- no-tools model invocation,
- core compaction command/pipeline,
- `context_compaction_started`, `context_compacted`, `context_compaction_failed`,
- `RunState.messages` replacement,
- runtime `compact()` API,
- TUI `/compact [instructions]`,
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
- `castor check` passes.

### Phase 2: Auto-compaction

Implement:

- after-turn threshold check,
- pre-LLM-call threshold check,
- optional overflow recovery with one-attempt guard,
- auto compaction events and TUI feedback.

Acceptance:

- Long sessions compact automatically when estimated tokens exceed `contextWindow - reserveTokens`.
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

## 22. Open questions

1. **Phase 1 runtime scope**
   - Should Phase 1 support both in-process and process/JSONL runtime, or is in-process enough for the first slice?
   - Recommendation: support both if feasible because runtime/TUI validation may exercise process mode.

Must support both.

2. **Compaction model selection**
   - Should compaction use the same model as the session, or a configurable model?
   - Recommendation: same model for Phase 1; add configurable override later.
By default session model, must be configurable in settings, e.g. model: <provider>/<model>

3. **Manual compaction during active run**
   - Queue until a safe boundary or reject while active?
   - Recommendation: queue if the core command mailbox supports it cleanly; otherwise reject in Phase 1 with clear status.
Queue until a safe boundary

4. **Event granularity**
   - Add start/compacted/failed events, or only compacted?
   - Recommendation: add all three for TUI feedback and observability.
add all three for TUI feedback and observability

5. **Empty summary behavior**
   - Treat as failure or use fallback text?
   - Recommendation: treat as failure in Phase 1 to avoid silently degrading context.
Treat as failure always, show error to user in TUI
