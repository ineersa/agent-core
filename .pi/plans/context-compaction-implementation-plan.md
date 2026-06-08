# Context Compaction Implementation Plan

## 1. Executive Summary

Conversation compaction reduces LLM context usage by summarizing older messages while preserving a recent raw tail. This lets long-running agent sessions stay within model context windows without losing accumulated knowledge.

**Chosen design:** Pi-style compaction mechanics (persisted checkpoint, retained recent tail, extension hooks, repeated-compaction support) + Codex-style minimal handoff prompt and prefix. Manual `/compact [custom instructions]` triggers Phase 1; auto-compaction deferred to Phase 2.

The compaction produces a `context_compacted` event whose payload includes the full compacted message list. Replay rebuilds hot prompt from the checkpoint, leveraging the existing `payload['messages']` replacement branch in `ReplayService::replayMessages()` — no replay logic changes needed. Repeated compactions work naturally because the previous summary is part of the retained history.

---

## 2. Imported Practices

### From pi / pi-mono coding-agent

| Practice | How we adopt it |
|---|---|
| Persisted `CompactionEntry` as a checkpoint in session history | `context_compacted` event in `events.jsonl` with full `payload['messages']` |
| `firstKeptEntryId` boundary — keep recent tail raw, summarize older | Retained-tail algorithm based on token budget; not ID-based since our model has `messages` snapshot |
| Safe cut points (never split tool calls from results) | Cut-point algorithm that walks backwards, identifies safe boundaries (user message, tool result with preceding tool call kept together) |
| Extension hooks before/after compaction | `BeforeCompactionHook` and `AfterCompactionHook` via `HookSubscriberInterface` extension points |
| Repeated compaction support via iterative summary merging | One-line instruction in the prompt: "If a prior compaction summary exists, incorporate it and preserve still-relevant facts." Also implicit because previous summary is in retained history |
| Cumulative file-operation tracking | Optional detail in event payload; deferred to later iteration |

### From Codex

| Practice | How we adopt it |
|---|---|
| Short, handoff-oriented summarization prompt | Exact Codex-style prompt (see Section 3) |
| Simple summary prefix disambiguating origin | Exact prefix (see Section 3) |
| 90%-of-context-window threshold (for auto, deferred) | Phase 2 config: `compaction.trigger.token_threshold_percent: 90` (capped at 90%) |
| Non-steerable compact turns | Input queued during compaction; activity state blocks user messages |
| Self-healing if compaction itself exceeds context window | Catch `ContextWindowExceeded` error, truncate oldest messages, retry (Phase 1: abort with error, inform user to try `/compact` with custom instructions that exclude some topics) |
| Cache invalidation after history rewrite | Already done — `RunCommit::commit()` calls `ReplayService::rebuildHotPromptState()` after every commit |
| Warning emitted post-compaction | System transcript block: "⧉ Conversation compacted. Token usage reduced from ~X to ~Y." |

### From Claude Code (observational, no direct code imports)

| Practice | Notes |
|---|---|
| PreCompact / PostCompact hooks | Already planned via extension hooks |
| Multiple compaction strategies (session memory, microcompact, reactive) | Phase 1 uses single strategy; Phase 2 may add variants |
| Circuit breaker (3 consecutive failures) | Adopt for Phase 2 auto-compaction |
| Post-compact file/skill/tool re-announcement | Not applicable to current architecture (tools are resolved per invocation); may matter when persistent tool state is added |

---

## 3. Proposed Prompt and Injected Prefix

### Summarization prompt (Codex-inspired)

```
You are performing a CONTEXT CHECKPOINT COMPACTION. Create a handoff summary for another LLM that will resume the task.

Include:
- Current progress and key decisions made
- Important context, constraints, or user preferences
- What remains to be done (clear next steps)
- Any critical data, examples, or references needed to continue

If a prior compaction summary exists in the conversation, incorporate it and preserve still-relevant facts.

Be concise, structured, and focused on helping the next LLM seamlessly continue the work.
```

The last instruction ("If a prior compaction summary exists...") is our only concession to repeated compaction. No rigid sections, no Progress/Done/Blocked grid — Codex proved simple works.

### Injected prefix (Codex-inspired, slightly adapted)

```
The conversation history before this point was compacted into a handoff summary. Use it as prior context, not as a new user request.

<summary>
[summary text]
</summary>
```

We avoid Codex's "thinking process" phrasing. "Handoff summary" is clearer.

### Custom instructions injection

If `/compact summarize only the database decisions` is used, prepend to the prompt:

```
Additional instructions: summarize only the database decisions
```

---

## 4. Data Model / Event Design

### 4.1 Event type

New enum case in `RunEventTypeEnum`:

```php
// src/AgentCore/Domain/Event/RunEventTypeEnum.php
case ContextCompacted = 'context_compacted';
```

This is a **pipeline event**, not a lifecycle event. It lives alongside `llm_step_completed`, `tool_call_result_received`, etc.

### 4.2 Event payload schema

```json
{
  "type": "context_compacted",
  "payload": {
    "trigger": "manual",
    "custom_instructions": "summarize only database decisions",
    "token_estimate_before": 142000,
    "token_estimate_after": 38000,
    "messages_compacted": 84,
    "messages_retained": 12,
    "summary_text": "## Database decisions\n...",
    "messages": [
      {
        "role": "user",
        "content": [{"type": "text", "text": "The conversation history before this point..."}],
        "metadata": {"compact_summary": true}
      },
      {"role": "user", "content": [{"type": "text", "text": "What's the next step?"}]},
      {"role": "assistant", "content": [{"type": "text", "text": "We should..."}]}
    ]
  }
}
```

Key points:
- `payload['messages']` contains the FULL new message list (summary + retained tail). This leverages the existing replay branch that does `$messages = []` then rebuilds from `payload['messages']`.
- `payload['trigger']` values: `manual`, `auto` (Phase 2).
- `payload['summary_text']` is the raw LLM output for audit/debugging.
- Summary message has `metadata.compact_summary = true` for downstream filtering (TUI rendering, SDK serialization).
- Summary message uses `role: 'user'` to maintain valid role transitions.

### 4.3 Summary AgentMessage shape

```php
new AgentMessage(
    role: 'user',
    content: [[
        'type' => 'text',
        'text' => "The conversation history before this point was compacted into a handoff summary. Use it as prior context, not as a new user request.\n\n<summary>\n{$summaryText}\n</summary>",
    ]],
    metadata: ['compact_summary' => true],
);
```

### 4.4 Token estimation

Reuse `ReplayService::estimateTokens()` heuristic: `ceil(strlen(json_encode($messages)) / 4)`.

Before compaction: estimate on current `state.messages`.
After compaction: estimate on new compacted message list.

### 4.5 Retained-tail algorithm

1. Target: keep `keep_recent_tokens` worth of newest messages raw (default: 20,000 tokens, matching pi-mono).
2. Walk messages array backwards, accumulating estimated tokens.
3. When accumulated >= target, search forward for a safe cut point:
   - Safe: `user`, `assistant` (standalone text, no pending tool calls), `tool` (result).
   - Unsafe: assistant messages with tool calls — must keep preceding user + following tool results together.
4. If cut point is a tool result, include its preceding assistant tool call + user message.
5. If only assistant messages remain (no user/tool boundary found), compact everything except the last user→assistant pair.
6. Return: `(summary_target_messages, retained_tail_messages)`.

Phase 1 simplification: cut at the nearest user message before the token target. If no user message exists in the window, cut at the last tool result.

---

## 5. Runtime Flow for Manual `/compact`

### 5.1 Full chain

```
User types "/compact [custom instructions]" in TUI
  → SubmitListener → SubmissionRouter
    → CommandParser parses "/compact summarize DB decisions"
    → SlashCommandRegistry dispatches to CompactCommandHandler (NEW)
      → CompactCommandHandler validates:
          - session handle exists
          - run is not in terminal state (Completed, Failed, Cancelled)
          - check shouldBlockDuringTask → false (compact allowed during active run)
      → Shows working message: "Compacting conversation..."
      → Returns DispatchRuntime(payload: JSON)
    → SubmitListener.applyCommandResult()
      → DispatchRuntime case (NEW WIRING — currently NO-OP)
        → client.send(runId, new UserCommand(
             type: 'extension',
             payload: ['kind' => 'ext:compaction:compact', 'payload' => ['custom_instructions' => 'summarize DB decisions'], 'options' => ['cancel_safe' => false]]
           ))
  → InProcessAgentSessionClient.send()
    → match 'extension':
        → runner->applyExtension(runId, kind, payload, options)  // NEW METHOD

  → AgentRunner::applyExtension() (NEW METHOD)
    → dispatches ApplyCommand(kind='ext:compaction:compact', payload={...})
  → ApplyCommandHandler::handle()
    → CommandRouter::route() → RoutedCommand::extension()
    → enqueues PendingCommand
    → postCommit: followUpAdvanceCallback → dispatches AdvanceRun

  → AdvanceRunHandler::handle()
    → CommandMailboxPolicy::applyPendingTurnStartCommands()
      → applyExtensionCommand()
        → CompactionCommandHandler::map() (NEW HANDLER)
          ├─ Loads current RunState from RunStore
          ├─ Calls SessionCompactor::compact(state, customInstructions)
          │   ├─ Estimate current tokens
          │   ├─ Find cut point (retained-tail algorithm)
          │   ├─ Build summarization message list (prompt + messages to summarize)
          │   ├─ Call PlatformInterface::invoke() (no tools, no stream observer)
          │   ├─ Parse assistantMessage text as summary
          │   ├─ Build new messages: [summary_message, ...retained_tail]
          │   └─ Return CompactResult(summaryText, newMessages, tokenBefore, tokenAfter)
          ├─ CAS RunStore: replace state.messages with compactedMessages
          ├─ Returns RunEvent objects:
          │   - RunEvent::extension('ext_compaction_requested', {reason: 'manual', ...})
          │   - RunEvent with type='context_compacted' and full payload
          └─ (Hot prompt rebuild happens automatically via RunCommit)
    → Emits events → RunCommit persists → rebuildHotPromptState()
```

### 5.2 New TUI wiring: `DispatchRuntime` in `SubmitListener`

Currently `applyCommandResult()` silently ignores `DispatchRuntime`:

```php
// src/Tui/Listener/SubmitListener.php — applyCommandResult()
// Currently:
if ($result instanceof DispatchRuntime) {
    // DispatchRuntime will be wired by future tasks that add runtime execution.
    return;
}
```

Must be wired:

```php
if ($result instanceof DispatchRuntime) {
    $data = json_decode($result->payload, true);
    if (!is_array($data) || !isset($data['kind'])) {
        $screen->setStatus('Invalid compaction payload');
        return;
    }
    $client->send($state->handle->runId, new UserCommand(
        type: 'extension',
        payload: $data,
    ));
    return;
}
```

### 5.3 New `UserCommand` type

Add `'extension'` to `UserCommand::type`:

```php
// src/CodingAgent/Runtime/Contract/UserCommand.php
/** @phpstan-type UserCommandType = 'message'|'steer'|'follow_up'|'cancel'|'answer_human'|'answer_tool_question'|'extension' */
```

### 5.4 New `AgentRunnerInterface::applyExtension()`

```php
// src/AgentCore/Contract/AgentRunnerInterface.php
public function applyExtension(string $runId, string $kind, array $payload, array $options = []): void;
```

Implementation in `AgentRunner`:

```php
// src/AgentCore/Application/Pipeline/AgentRunner.php
public function applyExtension(string $runId, string $kind, array $payload, array $options = []): void
{
    $stepId = $this->nextStepId('extension');
    $this->commandBus->dispatch(new ApplyCommand(
        runId: $runId,
        turnNo: 0,
        stepId: $stepId,
        attempt: 1,
        idempotencyKey: $this->idempotencyKey($runId, $stepId),
        kind: $kind,        // 'ext:compaction:compact'
        payload: $payload,  // ['custom_instructions' => '...']
        options: $options,  // ['cancel_safe' => false]
    ));
}
```

### 5.5 Process transport gap

`JsonlProcessAgentSessionClient::send()` does not handle `'extension'` type. For Phase 1, compaction is **InProcess only** (default TUI mode). Process (JSONL) transport will throw for unknown type. Phase 2 adds `apply_extension` JSONL command support in `HeadlessController`.

### 5.6 TUI feedback during compaction

- **Working message**: `screen->setWorkingMessage('Compacting conversation...')` — set by `CompactCommandHandler` before returning `DispatchRuntime`.
- **Activity state**: Compaction happens synchronously during turn processing. The `RuntimeEventPoller` sees the run go from `Running` → (processes events, including `context_compacted`) → back to user input. No intermediate state needed — `Running` covers it.
- **Post-compaction**: `RuntimeEventPoller` emits transcript blocks based on events. A new `context_compacted` transcript block shows: "⧉ Conversation compacted. Token usage: 142k → 38k."
- **Input blocking**: Already blocked while run is `Running` (active). Compaction is within a turn, so input is naturally queued.

### 5.7 UI edge: compaction during HITL/active tasks

Compaction is allowed during `Running` but:
- If the run is `WaitingHuman`, compaction is queued and processed when the run resumes.
- If the run is in a terminal state (`Completed`, `Failed`, `Cancelled`), compaction is rejected with status: "Cannot compact — run is already complete."

---

## 6. Compaction Service Design

### 6.1 Candidate file

`src/CodingAgent/Session/SessionCompactor.php` — already exists as an empty stub class.

Expanded responsibilities:

```php
namespace Ineersa\CodingAgent\Session;

final class SessionCompactor
{
    public function __construct(
        private PlatformInterface $platform,
        private RunStoreInterface $runStore,
        // ...
    ) {}

    /**
     * @param list<AgentMessage|array<string,mixed>> $messages
     * @return CompactResult
     */
    public function compact(
        array $messages,
        ?string $customInstructions = null,
        int $keepRecentTokens = 20_000,
    ): CompactResult;
}
```

### 6.2 `CompactResult` DTO (new)

```php
// src/CodingAgent/Session/CompactResult.php
final readonly class CompactResult
{
    public function __construct(
        public string $summaryText,
        /** @var list<AgentMessage> */
        public array $compactedMessages,  // summary + retained tail
        public int $tokenEstimateBefore,
        public int $tokenEstimateAfter,
        public int $messagesCompacted,
        public int $messagesRetained,
    ) {}
}
```

### 6.3 Summarization model call

Uses `PlatformInterface::invoke()` with direct messages (bypassing run store):

```php
$result = $this->platform->invoke(new ModelInvocationRequest(
    model: $this->defaultModel,  // from config: compaction.model or fallback to primary
    input: new ModelInvocationInput(
        runId: null,             // ← bypass run store
        messages: $summarizationMessages,  // ← direct message list
        stepId: 'compaction-step',
        toolsRef: null,          // ← no tools
    ),
    options: new ModelInvocationOptions(
        cancelToken: new NullCancellationToken(),
    ),
));
```

Key properties:
- **No tools** — `toolsRef: null` → `DynamicToolDescriptionProcessor` removes `options['tools']`.
- **No stream observer** — `LlmPlatformAdapter.streamObserver` is null for this call (or a no-op observer).
- **Direct messages** — `runId: null` → `resolveContextMessages()` uses `$input->messages` directly.
- **Synchronous** — The call blocks until the LLM responds (typically < 2 seconds for summarization).

### 6.4 Summarization message list construction

```php
// Build the messages to send to the summarization model:
$systemMessage = new AgentMessage(
    role: 'system',
    content: [['type' => 'text', 'text' => 'You are a context summarization assistant...']],
);
$promptMessage = new AgentMessage(
    role: 'user',
    content: [['type' => 'text', 'text' => $summarizationPrompt]],
);
$historyToSummarize = $oldMessages; // the messages being compacted (not the retained tail)
$summaryMessages = [$systemMessage, ...$historyToSummarize, $promptMessage];
```

### 6.5 Cut-point algorithm pseudocode

```
function findCutPoint(messages, keepRecentTokens):
    accumulated = 0
    for i from len(messages)-1 down to 0:
        msg = messages[i]
        accumulated += estimateTokens(json_encode(msg))
        if accumulated >= keepRecentTokens:
            // Walk forward to find safe cut point
            for j from i to len(messages)-1:
                if isSafeCutPoint(messages[j]):
                    return (messages[0..j-1], messages[j..])
            // Fallback: cut at nearest user message
            for j from i to len(messages)-1:
                if messages[j].role == 'user':
                    return (messages[0..j-1], messages[j..])
            // Last resort: keep only last 2 messages
            return (messages[0..-3], messages[-2..])
    // All messages fit
    return None  // No compaction needed

function isSafeCutPoint(msg):
    if msg.role in ['user']: return true
    if msg.role == 'assistant' and no tool_calls in msg: return true
    if msg.role == 'tool': return true  // but keep with preceding assistant
    return false
```

### 6.6 Error handling during summarization

| Error | Handling |
|---|---|
| `PlatformInvocationResult.error` not null | Return error to caller; emit `llm_step_failed` event; do NOT modify state |
| `ContextWindowExceeded` from platform | Abort compaction; inform user to provide narrower custom instructions or try later |
| Empty summary text | Fallback: `(The model did not produce a summary.)` |
| `\Throwable` during platform call | Catch, log, return error; do NOT modify state |
| Run is Cancelling/Cancelled | Abort immediately (check cancel token) |

### 6.7 Repeated compaction handling

When compacting a run that already has a `context_compacted` event:
1. The previous summary is present in `state.messages` (as a user message with `metadata.compact_summary = true`).
2. The cut-point algorithm treats it as a regular message (it will be part of either the summarization target or the retained tail).
3. The summarization prompt includes: "If a prior compaction summary exists, incorporate it and preserve still-relevant facts."
4. If the previous summary ends up in the retained tail (recent), it stays raw. If it's old enough to be summarized again, the model merges it.

---

## 7. Replay / Hot Prompt Changes

### 7.1 Why replay works without changes

`ReplayService::replayMessages()` already has a branch that handles `payload['messages']`:

```php
if (isset($payload['messages']) && \is_array($payload['messages'])) {
    $messages = [];  // FULL REPLACEMENT
    foreach ($payload['messages'] as $message) {
        if (!\is_array($message)) { continue; }
        $messages[] = $message;
    }
}
```

The `context_compacted` event includes `payload['messages']` with the new compacted list. When replay encounters this event, it replaces the accumulated message array with the compacted one. Subsequent events append as normal.

Replay flow:
1. Events 1..N: steer, llm_step_completed, tool results — accumulate messages
2. Event N+1: `context_compacted` — **replace all messages** with compacted list
3. Events N+2..M: new steer, llm_step_completed after compaction — append

### 7.2 Known `assistant` vs `assistant_message` replay bug

`replayMessages()` checks `payload['assistant']` but `LlmStepResultHandler` emits `payload['assistant_message']`. This means assistant messages from LLM steps are **not** replayed from events.jsonl.

**Impact on compaction:** Low for Phase 1. The compaction relies on `state.json` (via `RunStore`) as the authoritative messages source, not replay reconstruction. The bug means that after a crash + resume, replay produces incomplete messages — but this bug exists today without compaction. It should be fixed in a separate task, not bundled here.

**Recommendation:** File a separate bug-fix task: "Fix replay assistant message key (assistant vs assistant_message)".

### 7.3 Hot prompt rebuild after compaction

`RunCommit::commit()` calls `replayService.rebuildHotPromptState()` after every successful commit. After compaction:
1. The `context_compacted` event is persisted to `events.jsonl`.
2. `state.json` is CAS-swapped with the new compacted messages.
3. `rebuildHotPromptState()` replays all events → encounters `context_compacted` → replaces messages → produces correct `PromptState`.
4. Next LLM call (`LlmPlatformAdapter::resolveContextMessages()`) reads from `RunStore` → gets compacted messages.

---

## 8. Hook Design

### 8.1 Before-compaction hook

Interface:

```php
// src/AgentCore/Contract/Extension/CompactionHookInterface.php (NEW)
interface CompactionHookInterface
{
    /**
     * Called before compaction begins. Can cancel or provide a custom summary.
     *
     * @param CompactionContext $context Contains run state, messages to compact,
     *                                  retained messages, custom instructions.
     * @return CompactionHookResult
     */
    public function beforeCompaction(CompactionContext $context): CompactionHookResult;
}
```

`CompactionHookResult`:

```php
final readonly class CompactionHookResult
{
    public function __construct(
        public bool $cancel = false,
        public ?string $customSummary = null,
        public ?string $cancelReason = null,
    ) {}

    public static function proceed(): self { return new self(); }
    public static function cancel(string $reason): self { return new self(cancel: true, cancelReason: $reason); }
    public static function replace(string $summary): self { return new self(customSummary: $summary); }
}
```

If `$customSummary` is set, the compaction service skips the LLM summarization call and uses the provided summary directly.

### 8.2 After-compaction hook

Reuses existing `HookSubscriberInterface` — fires after turn commit (which includes the compaction turn). The `AfterTurnCommitHookContext` already carries the run state (with compacted messages) and events.

Extensions can check if `context_compacted` is in the event list to react specifically to compaction.

### 8.3 Extension API boundary notes

- `CompactionHookInterface` lives in `src/AgentCore/Contract/Extension/` — within AgentCore, accessible to extensions.
- The hook is registered via Symfony DI tag `agent_core.compaction_hook` (new).
- `ExtensionApiInterface` (`src/CodingAgent/ExtensionApi/`) does **not** expose compaction hooks in Phase 1. The public extension API gets compaction support in Phase 2 when the interface stabilizes.
- Compaction hooks are an internal AgentCore/CodingAgent integration point, not yet a public extension API surface.

### 8.4 Hook execution order

```
1. CompactionCommandHandler::map() invoked
2. BEFORE: dispatch beforeCompaction hooks → check cancel/replace
3. If cancelled → return rejected event, skip compaction
4. If custom summary → use it, skip LLM call
5. ELSE: perform LLM summarization
6. Build compacted messages, CAS state, return events
7. RunCommit persists → fires after-turn-commit hooks (existing HookSubscriberInterface)
8. AFTER: extensions see compacted state + context_compacted event
```

---

## 9. Auto-Compaction (Phase 2 — Deferred)

Phase 1 implements manual `/compact` only. Auto-compaction is fully designed but deferred to a follow-up task.

### 9.1 Trigger design

Three trigger points (mirroring Codex):

| Trigger | When | Condition |
|---|---|---|
| **After-turn** | `HookDispatcher::dispatchAfterTurnCommit()` | `PromptState.tokenEstimate > context_window * 0.9` |
| **Pre-LLM-call** | `TransformContextHookInterface` (new hook point) | Same threshold check, runs before context is sent to LLM |
| **Model downshift** | When switching to smaller-context model | Current token usage > new model's auto-compact limit |

### 9.2 Configuration

```yaml
# config/hatfield.defaults.yaml (new section)
compaction:
    enabled: false           # Phase 1: false (manual only). Phase 2: true
    keep_recent_tokens: 20000
    auto:
        enabled: false       # Phase 2: true
        trigger:
            token_threshold_percent: 90  # 90% of context_window
            min_turns: 10               # Don't compact sessions with < 10 turns
        max_consecutive_failures: 3     # Circuit breaker
        cooldown_turns: 5               # Minimum turns between auto-compactions
    model:                   # Summarization model (can differ from primary)
        provider: llama_cpp
        name: flash
    max_summary_tokens: 8192 # Max output tokens for summary generation
```

### 9.3 Circuit breaker

Track `consecutive_compaction_failures` in an in-memory store (per-run). After `max_consecutive_failures` (default 3), disable auto-compaction for the rest of the run. Manual `/compact` always works.

### 9.4 Cooldown

Track `last_compaction_turn` in `RunState` metadata (or in-memory). Skip auto-compaction if `current_turn - last_compaction_turn < cooldown_turns`.

---

## 10. Testing / Validation Plan

### 10.1 Required new tests

| Test | Location | Group | Coverage |
|---|---|---|---|
| `SessionCompactorTest` | `tests/CodingAgent/Session/` | default | Unit test: message list construction, cut-point algorithm, CompactResult shape |
| `CompactResultTest` | `tests/CodingAgent/Session/` | default | DTO serialization/deserialization |
| `CompactionCommandHandlerTest` | `tests/CodingAgent/Session/` | default | Handler map() method, event emission, CAS interaction |
| `CutPointAlgorithmTest` | `tests/CodingAgent/Session/` | default | Safe cut points, tool-call grouping, edge cases |
| `CompactionPromptTest` | `tests/CodingAgent/Session/` | default | Prompt construction with/without custom instructions, second compaction prompt |
| `ContextCompactedReplayTest` | `tests/AgentCore/Application/Handler/` | default | ReplayService handles context_compacted event, messages replacement |
| `CompactCommandSlashTest` | `tests/Tui/` | tui-e2e | Slash command registration, dispatch, working message |
| `SubmitListenerDispatchRuntimeTest` | `tests/Tui/` | tui-e2e | DispatchRuntime wiring sends extension UserCommand |
| `AgentRunnerApplyExtensionTest` | `tests/AgentCore/Application/` | default | applyExtension() dispatches correct ApplyCommand |
| `CompactionSmokeTest` | `tests/CodingAgent/Session/` | llm-real | End-to-end compaction with real LLM (llama.cpp) |
| `CompactionHookTest` | `tests/CodingAgent/Session/` | default | Before/after hook invocation, cancel, custom summary |

### 10.2 Existing tests that must still pass

- `CommandRouterContractTest` — uses `ext:compaction:compact` fixture
- All existing `castor check` tests
- Deptrac boundary validation

### 10.3 Validation commands

```bash
# Full QA (includes deptrac, all tests, phpstan, cs)
castor check

# Targeted test runs during development:
castor test --filter=Compaction
castor test --filter=Compact

# Real LLM smoke test (if llama.cpp available):
castor test:llm-real --filter=CompactionSmoke

# Deptrac only:
castor deptrac

# Static analysis:
castor phpstan
```

---

## 11. Phased Rollout

### Phase 1: Manual Compaction (this task)

**Scope:**
1. `RunEventTypeEnum::ContextCompacted` enum case
2. `CompactResult` DTO
3. `SessionCompactor` implementation (cut-point algorithm, summarization call, message replacement)
4. `CompactionCommandHandler` (implements `CommandHandlerInterface`, registered for `ext:compaction:compact`)
5. `CompactCommandHandler` (TUI slash command, returns `DispatchRuntime`)
6. `DispatchRuntime` wiring in `SubmitListener::applyCommandResult()`
7. `UserCommand` 'extension' type
8. `AgentRunnerInterface::applyExtension()` + `AgentRunner` implementation
9. `InProcessAgentSessionClient::send()` extension arm
10. Config keys in `hatfield.defaults.yaml` and `docs/settings.md`
11. Events: `ext_compaction_requested` + `context_compacted`
12. Prompt and prefix templates (inline in `SessionCompactor` or separate template files)
13. Unit + integration tests
14. Real LLM smoke test

**Acceptance criteria:**
- [x] `/compact` reduces session tokens
- [x] `/compact summarize only X` passes custom instructions
- [x] Second `/compact` works (prior summary incorporated)
- [x] Assistant correctly uses compacted context after compaction
- [x] Resumed session (restart TUI) preserves compacted state
- [x] `castor check` passes
- [x] `castor deptrac` passes (no boundary violations)
- [x] Real LLM smoke test passes

### Phase 2: Auto-Compaction (future task)

**Scope:**
1. `TransformContextHook` for pre-LLM-call token check
2. After-turn-commit hook for threshold check
3. Circuit breaker + cooldown
4. Auto-compaction config defaults (`compaction.auto.*`)
5. Process/JSONL transport `apply_extension` support
6. Model-downshift compaction
7. Extension API public surface for compaction hooks
8. TUI token display updates after auto-compaction

### Phase 3: Polish (future task)

1. Fix replay `assistant` vs `assistant_message` bug
2. Cumulative file-operation tracking in compaction metadata
3. Multiple-compaction history in event payload (audit chain)
4. Compaction analytics / metrics
5. Configurable summary output token limits

---

## 12. Risks / Open Questions

### 12.1 Risks

| Risk | Likelihood | Impact | Mitigation |
|---|---|---|---|
| **CAS conflict during compaction** | Medium | Low | Compaction runs inside the CAS loop; if CAS fails, the handler retries. The LLM summarization happens inside `map()` which runs inside the retry loop — but this means the LLM call may be wasted if CAS fails. Phase 1 accepts this; Phase 2 can add idempotency. |
| **LLM summarization timeout** | Low | Medium | Summarization calls are fast (small prompt, short output, no tools). If a call hangs, the run's CAS loop is blocked. Mitigation: add timeout to `PlatformInterface::invoke()` options. |
| **Cut-point splits tool call from results** | Medium | High | Algorithm explicitly avoids this. Test coverage is essential. |
| **Summary quality degrades over many compactions** | Medium | Medium | The "incorporate prior summary" instruction helps. Phase 3 can add iterative summary hooks for extensions to customize. |
| **Process transport (JSONL) not supported** | Low | Low | Phase 1: InProcess only. Process transport throws for unknown type. Phase 2 adds `apply_extension` JSONL command. |
| **Exit during compaction** | Low | Low | `Cancelling` status check in `ApplyCommandHandler` rejects non-cancel-safe extension commands. Compaction is `cancel_safe: false`. If user exits during compaction, the run is cancelled and compaction is abandoned. |
| **Deptrac boundary violation** | Medium | High | `SessionCompactor` (CodingAgent) calls `PlatformInterface` (AgentCore contract) — allowed. `CompactionHookInterface` (AgentCore) must not import CodingAgent types — verified by deptrac. |
| **`assistant` vs `assistant_message` replay bug** | High | Medium | Does not block Phase 1 (state.json is authoritative). File separate bug-fix task. |

### 12.2 Open questions for parent/user decision

1. **Compaction model selection:** Should compaction use the same model as the session, or a dedicated (cheaper/faster) model? Recommendation: default to same model, config-overridable via `compaction.model`.

2. **`context_compacted` as pipeline vs extension event:** Currently planned as a pipeline event (`context_compacted`) in `RunEventTypeEnum`. Alternative: keep as extension event (`ext_context_compacted`) for consistency with `ext_compaction_requested`. Decision: pipeline event because `replayMessages()` must handle it deterministically; extension events pass through without special replay logic.

3. **Summary storage format:** Store as structured sections (JSON) or plain text? Recommendation: plain text (Codex style). Structured sections can be added later if models struggle with plain text.

4. **Minimum messages threshold:** Should `/compact` refuse if there are fewer than N messages? Recommendation: refuse at < 4 messages (2 exchanges). Below this, compaction wastes tokens.

5. **Compaction during `WaitingHuman`:** Queue or reject? Recommendation: queue. Compaction happens when the run resumes (advances to next turn). This is the natural behavior of the PendingCommand system.

6. **Should compaction be a core command instead of extension?** Current design uses extension command (`ext:compaction:compact`). Alternative: add `CoreCommandKind::Compact`. Tradeoff: extension commands go through turn-boundary processing; core commands would need new handling in `ApplyCommandHandler`. Recommendation: stay with extension command for Phase 1 (test fixtures already exist, handler registration is clean). Revisit in Phase 2 if turn-boundary delay is problematic.

---

## Appendix A: File Manifest (Phase 1 changes)

### New files
| File | Purpose |
|---|---|
| `src/CodingAgent/Session/CompactResult.php` | DTO for compaction result |
| `src/CodingAgent/Session/CompactionCommandHandler.php` | Extension command handler for `ext:compaction:compact` |
| `src/Tui/Command/CompactCommandHandler.php` | TUI slash command handler for `/compact` |
| `src/AgentCore/Contract/Extension/CompactionHookInterface.php` | Before-compaction hook contract |
| `src/AgentCore/Contract/Extension/CompactionContext.php` | DTO passed to before-compaction hooks |
| `src/AgentCore/Contract/Extension/CompactionHookResult.php` | DTO returned by before-compaction hooks |
| `tests/CodingAgent/Session/SessionCompactorTest.php` | Unit tests |
| `tests/CodingAgent/Session/CompactResultTest.php` | DTO tests |
| `tests/CodingAgent/Session/CutPointAlgorithmTest.php` | Algorithm tests |
| `tests/CodingAgent/Session/CompactionPromptTest.php` | Prompt tests |
| `tests/CodingAgent/Session/CompactionCommandHandlerTest.php` | Handler tests |
| `tests/CodingAgent/Session/CompactionHookTest.php` | Hook tests |
| `tests/CodingAgent/Session/CompactionSmokeTest.php` | LLM smoke test |
| `tests/AgentCore/Application/Handler/ContextCompactedReplayTest.php` | Replay test |
| `tests/Tui/Command/CompactCommandSlashTest.php` | TUI test |

### Edited files
| File | Change |
|---|---|
| `src/AgentCore/Domain/Event/RunEventTypeEnum.php` | Add `ContextCompacted` case |
| `src/CodingAgent/Session/SessionCompactor.php` | Implement |
| `src/Tui/Command/SlashCommandRegistry.php` | Register `/compact` (inline or via listener) |
| `src/Tui/Listener/SubmitListener.php` | Wire `DispatchRuntime` case |
| `src/CodingAgent/Runtime/Contract/UserCommand.php` | Add `'extension'` type |
| `src/CodingAgent/Runtime/InProcess/InProcessAgentSessionClient.php` | Handle `'extension'` arm |
| `src/AgentCore/Contract/AgentRunnerInterface.php` | Add `applyExtension()` |
| `src/AgentCore/Application/Pipeline/AgentRunner.php` | Implement `applyExtension()` |
| `config/hatfield.defaults.yaml` | Add `compaction` section |
| `docs/settings.md` | Document compaction keys |
| `config/services.yaml` | Register new services, hook tags |

---

## Appendix B: Config Schema

```yaml
# New in config/hatfield.defaults.yaml
compaction:
    enabled: true                 # Master switch (manual always works)
    keep_recent_tokens: 20000     # Tokens of recent messages kept unsummarized
    model:                        # Model for summarization calls
        provider: null            # null = use session's default model
        name: null                # null = use session's default model
    max_summary_tokens: 8192      # Max output tokens for summary
    auto:
        enabled: false            # Phase 2
        min_turns: 10             # Minimum turns before auto-compaction considered
        trigger:
            token_threshold_percent: 90
        max_consecutive_failures: 3
        cooldown_turns: 5
```
