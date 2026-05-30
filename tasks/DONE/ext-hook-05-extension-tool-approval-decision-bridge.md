# EXT-HOOK-05 Extension tool approval via existing HITL interrupt flow

## Goal
Plan: `.pi/plans/extension-tool-hooks-safeguard-plan.md`
Related task: `SAFE-04 SafeGuard approval flow`

Add a `RequireApproval` decision kind to the ExtensionApi tool-call hook contract. When a hook returns `RequireApproval`, the tool execution is replaced with an interrupt payload that reuses Hatfield's existing HITL `waiting_human` flow — no new runtime state machine, Messenger commands, or TUI polling needed.

Depends on: `EXT-HOOK-04` (hook registry + Symfony AI event subscriber already in place).

## Key architectural discovery: existing HITL flow handles everything

Hatfield already has a complete interrupt/HITL pipeline built on top of Symfony AI primitives (NOT a Symfony AI feature — 100% Hatfield's own implementation). The full flow:

```
1. TOOL EXECUTION
   ToolExecutor returns ToolResult with details: {kind: 'interrupt', question_id, prompt, schema}

2. TOOL BATCH COMPLETION
   ToolCallResultHandler::handle() — when all pending tool calls complete:
     $interruptPayload = $this->stateTools->interruptPayloadFromToolResult($result)
   ToolCallExtractor::interruptPayloadFromToolResult() detects:
     - $result->result['kind'] === 'interrupt' (top-level in raw result), OR
     - $result->result['details']['kind'] === 'interrupt' (in details sub-array)
   If found:
     - Sets status = RunStatus::WaitingHuman
     - Emits event: {type: 'waiting_human', payload: $interruptPayload}
     - Does NOT dispatch AdvanceRun (run pauses)

3. RUNTIME MAPPING
   HitlMappingSubscriber listens to 'waiting_human' AgentCore events
   Maps to RuntimeEvent(type: 'human_input.requested', payload: {question_id, prompt, schema, tool_call_id, tool_name})

4. TUI PROJECTION
   HitlProjectionSubscriber creates a Question transcript block (TranscriptBlockKindEnum::Question)
   RuntimeEventPoller sets RunActivityStateEnum::WaitingHuman
   TUI shows prompt widget, waits for user input

5. USER ANSWERS
   TUI sends UserCommand(type: 'answer_human', payload: {question_id, answer})
   AgentSessionClient::send() → AgentRunner::answerHuman()
   AgentRunner dispatches ApplyCommand(kind: 'human_response', payload: {question_id, answer})

6. RUNTIME RESUME
   ApplyCommandHandler::applyHumanResponseCommand():
     - Verifies status == WaitingHuman
     - Builds humanResponseMessage (user role, JSON: {question_id, answer})
     - Appends message to state.messages
     - Sets status = RunStatus::Running
     - Dispatches AdvanceRun → LLM resumes with the human answer in context

7. TUI ACKNOWLEDGMENT
   RuntimeEvent 'human_input.answered' emitted
   HitlProjectionSubscriber updates Question block status to 'answered'
   RuntimeEventPoller transitions back to Running
```

**The critical insight**: `ExtensionToolHookEventSubscriber::onToolCallRequested()` already calls `$event->setResult(new ToolResult($toolCall, ...))` for `ReplaceResult` decisions. This result flows through `RegistryBackedToolbox::execute()` → `ToolExecutor::toDomainResult()` → `ToolCallResultHandler`. If the replacement result contains `{kind: 'interrupt', ...}`, the entire HITL flow activates.

**So: `RequireApproval` = `ReplaceResult` with an interrupt payload shape.** No new runtime plumbing needed.

### Why this works for SafeGuard

When SafeGuard blocks `rm -rf /tmp/build` and requires approval:
1. Hook returns `RequireApproval` with prompt "Allow destructive command: rm -rf /tmp/build?"
2. Tool NEVER executes (replaced by interrupt result before handler runs)
3. Run pauses at `WaitingHuman`, TUI shows approval prompt
4. User answers "Allow once" → run resumes → LLM sees the answer, retries the tool call
5. On retry, SafeGuard checks an in-memory "just approved" allowlist → allows it → tool executes normally
6. For "Always allow" → SafeGuard persists the pattern to policy file AND adds to in-memory allowlist

### What the LLM sees

The LLM receives the human's answer as a user message: `{question_id: '...', answer: 'Allow once'}`. The LLM then decides to retry the tool call. On retry, SafeGuard's hook sees the command is in the approved list and returns `Allow`. The tool executes normally.

This is one LLM round-trip slower than a transparent pause/resume, but it keeps the approval flow model-visible (the LLM knows it was blocked and approved), which is the correct behavior for safety-critical operations.

### Noninteractive/controller mode

When no TUI is attached (controller mode, headless), the interrupt still fires. The runtime enters `WaitingHuman`. The controller must have a policy for this:
- **Default: deny** — the answer_human command is never sent, and the run eventually times out or the user sees it in the transcript
- **Alternative**: a config knob `safe_guard.auto_deny_in_noninteractive: true` that returns `Block` instead of `RequireApproval` when no TUI is detected

This should be handled in SAFE-04, not in EXT-HOOK-05. The generic hook contract just adds the decision kind; SafeGuard decides when to use it.

## Scope

### Public ExtensionApi changes (src/CodingAgent/ExtensionApi/)

1. **`ToolCallDecisionKindEnum`** — add `RequireApproval` case:
   ```php
   case RequireApproval = 'require_approval';
   ```

2. **`ToolCallDecisionDTO`** — add `requireApproval()` factory:
   ```php
   public static function requireApproval(
       string $prompt,
       ?string $questionId = null,
       array $schema = ['type' => 'string'],
       array $details = [],
   ): self
   ```
   Properties needed: `prompt` (maps to interrupt prompt), `questionId` (maps to question_id), stored in `$details` or new typed properties.

3. **No new interfaces** — `ToolCallHookInterface` signature unchanged (`onToolCall` returns `ToolCallDecisionDTO` which already covers the new kind).

### Internal bridge changes (src/CodingAgent/Extension/)

4. **`ExtensionToolHookEventSubscriber::onToolCallRequested()`** — add `RequireApproval` handling:
   ```php
   if (ToolCallDecisionKindEnum::RequireApproval === $decision->kind) {
       $questionId = $decision->details['question_id']
           ?? hash('sha256', sprintf('%s|%s', $toolCall->getName(), microtime(true)));
       $event->setResult(new ToolResult($toolCall, [
           'kind' => 'interrupt',
           'question_id' => $questionId,
           'prompt' => $decision->details['prompt'] ?? 'Approval required.',
           'schema' => $decision->details['schema'] ?? ['type' => 'string'],
           'tool_name' => $toolCall->getName(),
           'tool_call_id' => $toolCall->getId(),
           'approval_context' => $decision->details,  // extension-specific metadata
       ]));
       return;
   }
   ```

   This is placed after the existing `Block` and `ReplaceResult` handlers, before the end of the foreach loop.

### Test coverage

5. **`ExtensionApiContractsTest`** — test that `ToolCallDecisionDTO::requireApproval()` creates correct decision kind and properties.

6. **`ExtensionToolHookEventSubscriberTest`** — test that `RequireApproval` decision:
   - Calls `$event->setResult()` with interrupt-shaped payload
   - Contains `kind: 'interrupt'`, `question_id`, `prompt`, `schema`
   - Preserves extension-specific `approval_context` in the result
   - Does NOT call the tool handler (verify via spy/mock)

7. **`ToolCallExtractorTest`** or equivalent — verify that the interrupt payload produced by `RequireApproval` is correctly detected by `interruptPayloadFromToolResult()`. The result flows as:
   ```
   ExtensionToolHookEventSubscriber → setResult(ToolResult with interrupt payload)
   → RegistryBackedToolbox returns that ToolResult
   → ToolExecutor::toDomainResult() detects $rawResult['kind'] === 'interrupt'
   → ToolCallResultHandler::interruptPayloadFromToolResult() detects it
   → RunStatus::WaitingHuman
   ```

## Out of scope

- **SafeGuard-specific logic** — which operations require approval, "Allow once" vs "Always allow" persistence, in-memory allowlists → SAFE-04
- **Noninteractive mode detection** — how to detect headless/controller mode and auto-deny → SAFE-04
- **TUI approval widget changes** — the existing Question transcript block and answer_human flow work as-is. If we want a different visual treatment (approval badges, choices), that's SAFE-04 or a future TUI enhancement.
- **New runtime states or Messenger commands** — not needed, reuses existing `WaitingHuman` → `human_response` → `AdvanceRun` flow.
- **Approval-specific RuntimeEventTypeEnum** — `ApprovalRequested`/`Approved`/`Rejected` already exist in the enum but are not wired. SAFE-04 may choose to use them for transcript rendering, or may stick with the generic `HumanInputRequested`/`Answered` flow.

## Acceptance criteria

- `ToolCallDecisionKindEnum::RequireApproval` exists as a public ExtensionApi enum case.
- `ToolCallDecisionDTO::requireApproval()` factory creates a decision with prompt, questionId, schema, and details.
- `ExtensionToolHookEventSubscriber` converts `RequireApproval` to `setResult(ToolResult)` with interrupt payload shape `{kind: 'interrupt', question_id, prompt, schema}`.
- The interrupt payload flows through the existing HITL pipeline: `ToolExecutor::toDomainResult()` → `ToolCallExtractor::interruptPayloadFromToolResult()` → `ToolCallResultHandler` sets `WaitingHuman`.
- Extension-specific metadata (category, command, path) is preserved in the result's `approval_context` field.
- No new runtime states, Messenger commands, or TUI polling mechanisms are introduced.
- Deptrac clean, cs-check clean, all existing tests continue to pass.

## Workflow metadata
Status: DONE
Branch: task/ext-hook-05-extension-tool-approval-decision-bridge
Worktree: /home/ineersa/projects/agent-core-worktrees/ext-hook-05-extension-tool-approval-decision-bridge
Fork run:
PR URL: https://github.com/ineersa/agent-core/pull/69
PR Status: merged
Started: 2026-05-30T01:22:25.264Z
Completed: 2026-05-30T03:05:13.755Z

## Work log
- Created: 2026-05-29T20:59:55.419Z
- Updated: 2026-05-30 — Rewrote task based on architectural discovery that existing HITL interrupt flow handles the entire approval lifecycle. Eliminated TUI-APPROVAL-01 dependency and removed new-runtime-plumbing scope.

## Task workflow update - 2026-05-30T01:22:25.264Z
- Moved TODO → IN-PROGRESS.
- Created branch task/ext-hook-05-extension-tool-approval-decision-bridge.
- Created worktree /home/ineersa/projects/agent-core-worktrees/ext-hook-05-extension-tool-approval-decision-bridge.
- Copied vendor directory into /home/ineersa/projects/agent-core-worktrees/ext-hook-05-extension-tool-approval-decision-bridge.
- Copied .vera index into /home/ineersa/projects/agent-core-worktrees/ext-hook-05-extension-tool-approval-decision-bridge.

## Task workflow update - 2026-05-30T01:39:22.291Z
- Moved IN-PROGRESS → CODE-REVIEW.
- Pushed task/ext-hook-05-extension-tool-approval-decision-bridge to origin.
- branch 'task/ext-hook-05-extension-tool-approval-decision-bridge' set up to track 'origin/task/ext-hook-05-extension-tool-approval-decision-bridge'.
- Created PR: https://github.com/ineersa/agent-core/pull/69

## Task workflow update - 2026-05-30T03:05:13.756Z
- Moved CODE-REVIEW → DONE.
- Merged task/ext-hook-05-extension-tool-approval-decision-bridge into integration checkout.
- Merge made by the 'ort' strategy.
 .../Extension/ExtensionToolHookEventSubscriber.php |  19 ++++
 .../ExtensionApi/ToolCallDecisionDTO.php           |  28 +++++
 .../ExtensionApi/ToolCallDecisionKindEnum.php      |  11 +-
 .../ExtensionToolHookEventSubscriberTest.php       | 119 +++++++++++++++++++++
 .../ExtensionApi/ExtensionApiContractsTest.php     |  42 ++++++++
 5 files changed, 216 insertions(+), 3 deletions(-)
- Removed worktree /home/ineersa/projects/agent-core-worktrees/ext-hook-05-extension-tool-approval-decision-bridge.
- Pulled integration checkout: Merge made by the 'ort' strategy..
