# SAFE-04 SafeGuard approval flow over existing HITL interrupt

## Goal
Plan: `.pi/plans/extension-tool-hooks-safeguard-plan.md`
Related tasks: `EXT-HOOK-05` (RequireApproval decision kind), `SAFE-02` (SafeGuard extension MVP)

Wire SafeGuard's policy-relaxable classifications (destructive commands, writes outside CWD, protected reads, dangerous git, sensitive info) through the `RequireApproval` decision from EXT-HOOK-05. The approval reuses Hatfield's existing HITL interrupt flow — the tool is never executed until the user approves.

Depends on: `EXT-HOOK-05`, `SAFE-02`.

## Key architectural understanding

### How the approval flow works end-to-end

```
┌─────────────────────────────────────────────────────────────────────────────┐
│ STEP 1: LLM emits tool call for "rm -rf /tmp/build"                         │
│                                                                               │
│ STEP 2: RegistryBackedToolbox dispatches ToolCallRequested event             │
│                                                                               │
│ STEP 3: ExtensionToolHookEventSubscriber::onToolCallRequested()              │
│   → Iterates ExtensionHookRegistry hooks                                      │
│   → SafeGuardToolCallHook::onToolCall() classifies the command               │
│   → Classification: DestructiveCommand (policy-relaxable)                    │
│   → Returns ToolCallDecisionDTO::requireApproval(                             │
│       prompt: 'Allow destructive command: rm -rf /tmp/build?',              │
│       details: {                                                             │
│         category: 'destructive',                                             │
│         command: 'rm -rf /tmp/build',                                        │
│         tool_name: 'bash',                                                   │
│         choices: ['Allow once', 'Always allow', 'Deny'],                     │
│       }                                                                      │
│     )                                                                        │
│                                                                               │
│ STEP 4: ExtensionToolHookEventSubscriber converts RequireApproval to          │
│   $event->setResult(new ToolResult($toolCall, [                              │
│     'kind' => 'interrupt',                                                   │
│     'question_id' => 'sg_abc123...',                                         │
│     'prompt' => 'Allow destructive command: rm -rf /tmp/build?',            │
│     'schema' => {type: 'string', enum: ['Allow once', 'Always allow', 'Deny']}, │
│     'approval_context' => {category, command, tool_name, choices},           │
│   ]))                                                                        │
│   → Tool handler NEVER runs                                                  │
│                                                                               │
│ STEP 5: ToolExecutor::toDomainResult() detects kind === 'interrupt'           │
│                                                                               │
│ STEP 6: ToolCallResultHandler detects interruptPayload                        │
│   → Sets RunStatus::WaitingHuman                                             │
│   → Emits 'waiting_human' event                                              │
│   → Does NOT dispatch AdvanceRun                                             │
│                                                                               │
│ STEP 7: HitlMappingSubscriber maps to RuntimeEvent(human_input.requested)     │
│   → HitlProjectionSubscriber creates Question transcript block               │
│   → TUI shows approval prompt with choices                                   │
│                                                                               │
│ STEP 8: User selects "Allow once"                                             │
│   → TUI sends answer_human with answer = "Allow once"                        │
│   → AgentRunner::answerHuman() dispatches ApplyCommand(human_response)       │
│   → ApplyCommandHandler appends user message, sets Running, dispatches AdvanceRun │
│                                                                               │
│ STEP 9: LLM resumes, sees the answer "Allow once" in context                  │
│   → LLM decides to retry the tool call for "rm -rf /tmp/build"              │
│                                                                               │
│ STEP 10: SafeGuardToolCallHook::onToolCall() classifies again                 │
│   → Checks in-memory approved-commands allowlist                              │
│   → Finds "rm -rf /tmp/build" was just approved                              │
│   → Returns ToolCallDecisionDTO::allow()                                     │
│   → Tool executes normally                                                   │
│                                                                               │
│ STEP 11: Tool result returned to LLM, run continues                           │
└─────────────────────────────────────────────────────────────────────────────┘
```

### In-memory approval tracking (the "just approved" allowlist)

When the user approves a tool call, SafeGuard needs to allow it on retry. This requires:

1. **ApprovalSessionTracker** — a simple in-memory service (not persisted across runs) that tracks recently approved operations:
   ```php
   final class ApprovalSessionTracker {
       /** @var array<string, bool> approved operation keys */
       private array $approved = [];
       
       public function approve(string $key): void { $this->approved[$key] = true; }
       public function isApproved(string $key): bool { isset($this->approved[$key]); }
       public function remove(string $key): void { unset($this->approved[$key]); }
   }
   ```

2. **Key generation** — For each category, the key is what makes the approval unique:
   - Destructive command: normalized command string (e.g., `"rm -rf /tmp/build"`)
   - Write outside CWD: normalized target path (e.g., `"/etc/config.yaml"`)
   - Protected read: normalized file path (e.g., `".env.local"`)
   - Dangerous git: normalized command string
   - Sensitive info: `"env"` or `"printenv"` (env commands)

3. **Approval lifecycle**:
   - When the hook classifies as policy-relaxable AND the operation is NOT already in the approved list → return `RequireApproval`
   - When the user answers "Allow once" → add to approved list
   - On retry, hook finds the key → returns `Allow` → removes from approved list (one-time use)
   - When the user answers "Always allow" → add to policy file AND add to approved list

4. **Hooking into the answer** — This is the tricky part. The hook runs BEFORE tool execution, but the answer comes AFTER (via the HITL flow). SafeGuard needs to detect when a human answer contains an approval and update its tracker. Two options:

   **Option A: ToolResultHook** — Register a `ToolResultHookInterface` that watches for interrupt results with SafeGuard question_ids and parses the human answer. But tool result hooks are observational (can't mutate) and the answer doesn't flow through a tool result — it flows through `ApplyCommandHandler`.

   **Option B: Answer message inspection** — On the next `onToolCall()` invocation, check if the previous turn's messages contain a SafeGuard approval answer. But the hook only receives `ToolCallContextDTO` which has no access to message history.

   **Option C (recommended): Pre-check allowlist in classifier, populate allowlist from extension's own listener** — The SafeGuard extension registers both:
   - A `ToolCallHookInterface` that checks the allowlist before classification
   - A Symfony EventSubscriber that listens to the `waiting_human` → `human_input.answered` flow and updates the allowlist when it detects SafeGuard question_ids
   
   Actually, even simpler: the ExtensionToolHookEventSubscriber already has access to the approval_context in the interrupt payload. When the run resumes after human answer, the LLM retries the same tool call. The hook sees the same command again. But the hook needs to know it was just approved.

   **Simplest approach: pattern-based pre-approval**. When `RequireApproval` is returned, the hook also stores the approval key in `ApprovalSessionTracker`. But this creates a chicken-and-egg problem — the hook blocks the tool AND needs to pre-approve it for retry. The answer: **don't pre-approve**. Instead, include a cryptographic nonce in the question_id (e.g., `sg_{hash(command+runId+nonce)}`). When the LLM retries after getting the approval answer, the hook sees the same command but generates a DIFFERENT question_id (different nonce). We need a different approach.

   **Actual simplest approach**: Use the `approval_context` to carry a one-time approval token. The interrupt payload includes `approval_token: 'tok_abc123'`. The SafeGuard hook stores `{command: 'rm -rf /tmp/build', token: 'tok_abc123', approved: false}` in `ApprovalSessionTracker`. When the human answers (answer_human), SafeGuard doesn't directly see this — but when the LLM retries the tool call, the hook can match the command and check if there's a pending approval for it.

   Wait — the fundamental issue is that SafeGuard's hook runs in the tool execution worker, and the human answer flows through the AgentCore pipeline. These are different processes (or at least different handler invocations). The `ApprovalSessionTracker` needs to be a shared service that persists across handler calls within the same run.

   Since Hatfield runs tool execution within the same PHP process (Messenger consumer), a singleton-scoped `ApprovalSessionTracker` service works. It's populated by the `RequireApproval` return (storing the pending key) and checked on subsequent `onToolCall()` invocations. The tracker doesn't need to know about the human answer — it just needs to know that it previously required approval for this exact operation, and on the next call with the same operation, it should allow it once.

   **Final approach**: 
   - `RequireApproval` stores `{command_key: true}` in `ApprovalSessionTracker` 
   - Next time the same command_key appears in `onToolCall()`, the hook checks the tracker
   - If found: allow, then remove from tracker (one-time)
   - For "Always allow": the hook checks the answer content via a separate mechanism

   Actually, the problem remains: how does the hook distinguish between "the user just approved this" and "the LLM is retrying without approval"? The hook doesn't see the human answer.

   **Real solution**: The LLM's retry will include the human answer in the conversation context. But the hook only sees `ToolCallContextDTO` — it doesn't see the message history.

   **Pragmatic solution**: Use a time-bounded approval window. When `RequireApproval` is returned, store `{command_key: timestamp}` in the tracker. On next call for the same key within N seconds (e.g., 30s), auto-allow. This is how Pi safe-guard effectively works — the user approves, the tool retries, and the approval is in-memory. The difference is Pi runs single-process so it can directly intercept the approval answer.

   **Even more pragmatic**: Don't track approvals in the hook at all. Instead, make the interrupt result include the approval choices in the schema, and make the human answer part of the conversation. The LLM sees "Allow once" and retries the tool. The SafeGuard hook on the retry still classifies it as dangerous — but this time it returns `RequireApproval` again with the same prompt. This creates an infinite loop unless...

   **The correct solution (matching Pi's behavior)**: Pi's safe-guard allows the command after approval because the approval updates the in-memory policy allowlist (`ctx.state.sessionAllowed`). In Hatfield, we need:

   1. When the hook returns `RequireApproval`, it stores the command key in `ApprovalSessionTracker::pending`
   2. A separate listener subscribes to the `human_input.answered` runtime event (or the `ApplyCommand(human_response)` message flow)
   3. When it detects an answer to a SafeGuard question (identified by question_id prefix `sg_`), it moves the key from `pending` to `approved` in the tracker
   4. On retry, the hook checks `approved` and returns `Allow`

   This requires a Symfony event subscriber in the SafeGuard extension that listens for the approval answer. The subscriber needs access to the question_id → command_key mapping.

   **Implementation**:
   ```php
   // In SafeGuardToolCallHook::onToolCall():
   $key = $this->trackerKey($category, $command);
   if ($this->approvalTracker->isApproved($key)) {
       $this->approvalTracker->consumeApproval($key); // one-time
       return ToolCallDecisionDTO::allow();
   }
   // ... classify ...
   if ($decision->kind === SafeGuardDecisionKind::DestructiveCommand /* relaxable */) {
       $questionId = 'sg_' . hash('sha256', $key . '|' . microtime(true));
       $this->approvalTracker->markPending($questionId, $key);
       return ToolCallDecisionDTO::requireApproval(
           prompt: "Allow {$category}: {$command}?",
           questionId: $questionId,
           schema: ['type' => 'string', 'enum' => ['Allow once', 'Always allow', 'Deny']],
           details: ['category' => $category, 'command' => $command, 'tool_name' => $context->toolName],
       );
   }
   ```

   For the answer listener, SafeGuard needs to register a subscriber that listens for `waiting_human` → `agent_command_applied` events with `kind: human_response`. But extensions currently can only register tools, hooks, and commands — they can't subscribe to AgentCore pipeline events.

   **This is the remaining gap**: We need either:
   - (a) A way for extensions to subscribe to runtime events (new ExtensionApi method), OR
   - (b) The `RequireApproval` decision to carry enough context that `ExtensionToolHookEventSubscriber` can auto-approve on retry, OR
   - (c) A simpler time-based approach where pending approvals auto-expire and are consumed on next call

   **Recommended approach for MVP: (c) Time-bounded pending approval with consume-on-use**
   
   When `RequireApproval` is returned, the tracker stores `{key: {pending: true, timestamp: now}}`. On the next `onToolCall()` with the same key, if pending and within 60 seconds, the hook assumes the LLM is retrying after approval and returns `Allow` + removes the entry. If the user denied, the LLM won't retry, so the entry just expires.

   This isn't perfectly secure (a malicious LLM could retry without approval within the window), but:
   - The tool was already blocked the first time
   - The only way the LLM retries is if the human answer triggered an AdvanceRun
   - The human answer is model-visible, so the LLM context includes the approval
   - This matches Pi's behavior where approval is in-memory and session-scoped

   For "Always allow", SafeGuard writes the pattern to the policy file. The next time the command appears (in this or any future run), the classifier finds it in the policy allowlist and returns `Allow` directly — no `RequireApproval` needed.

## Scope

### SafeGuard extension changes

1. **`ApprovalSessionTracker`** — new class in `Extension/Builtin/SafeGuard/`:
   ```php
   final class ApprovalSessionTracker {
       private array $pending = [];  // key => timestamp
       private int $ttlSeconds = 60;
       
       public function markPending(string $key): void;
       public function isPending(string $key): bool;  // checks TTL
       public function consumePending(string $key): bool;  // removes if within TTL
   }
   ```

2. **`SafeGuardToolCallHook`** (created in SAFE-02, extended here) — add approval flow:
   - Before classification: check `ApprovalSessionTracker::isPending($key)` → if yes, consume and return `Allow`
   - After classification: for policy-relaxable decisions (Destructive, DangerousGit, SensitiveInfo, WriteOutsideCwd, ProtectedRead):
     - If in policy allowlist → `Allow` (existing behavior from SAFE-02)
     - If NOT in allowlist → `markPending($key)`, return `RequireApproval`
   - Hard-blocked decisions (HardBlock: sudo, su) → `Block` (never approvable, existing behavior)
   - Generate tracker key from classification: `{category}:{normalized_command_or_path}`

3. **"Always allow" persistence** — When the user's answer is "Always allow":
   - This requires detecting the answer content, which the hook can't do directly
   - **Approach**: The interrupt `schema` includes `enum: ['Allow once', 'Always allow', 'Deny']`. The human answer becomes a user message in the conversation. When the LLM retries after "Always allow", the hook allows it (via pending tracker). But the "always" part needs a listener.
   - **For MVP**: Skip "Always allow" persistence. Only support "Allow once" (via pending tracker) and "Deny" (LLM sees denial, doesn't retry). Add "Always allow" as a follow-up when extension event subscription is available.
   - **Alternative**: SafeGuard could provide a slash command `/safe-guard allow <pattern>` for manual policy edits, deferring automatic "Always allow" persistence.

4. **Noninteractive/headless behavior**:
   - When no TUI is attached, `RequireApproval` would hang forever at `WaitingHuman`
   - SafeGuard should detect headless mode and return `Block` instead of `RequireApproval`
   - Detection: check if `AppConfig` has a mode flag, or check if the runtime is in controller mode
   - Config knob: `safe_guard.auto_deny_in_noninteractive: true` (default: true)

### Transcript rendering

5. The existing `HitlProjectionSubscriber` creates a `Question` transcript block for all interrupts. SafeGuard approvals will appear as questions in the transcript. This is acceptable for MVP — the transcript shows "Allow destructive command: rm -rf /tmp/build?" with the answer.

6. `RuntimeEventTypeEnum` already has `ApprovalRequested`, `ApprovalApproved`, `ApprovalRejected` — these could be used for a richer transcript experience (separate `Approval` block kind with badge), but for MVP, the generic `HumanInputRequested`/`Answered` flow is sufficient.

## Classification → decision mapping

| SafeGuardDecisionKind | Current (SAFE-02) | With approval (SAFE-04) |
|---|---|---|
| Allow | `ToolCallDecisionDTO::allow()` | Same |
| HardBlock (sudo, su) | `ToolCallDecisionDTO::block()` | Same — never approvable |
| DestructiveCommand | `ToolCallDecisionDTO::block()` | `RequireApproval` (if not in pending tracker or policy allowlist) |
| DangerousGit | `ToolCallDecisionDTO::block()` | `RequireApproval` |
| SensitiveInfo (env, printenv) | `ToolCallDecisionDTO::block()` | `RequireApproval` |
| WriteOutsideCwd | `ToolCallDecisionDTO::block()` | `RequireApproval` |
| ProtectedRead | `ToolCallDecisionDTO::block()` | `RequireApproval` |

## Tracker key examples

```
destructive:rm -rf /tmp/build
destructive:git reset --hard HEAD
dangerous_git:git push --force origin main
sensitive_info:env
write_outside:/etc/config.yaml
protected_read:.env.local
```

## Acceptance criteria

- SafeGuard classifies dangerous operations and returns `RequireApproval` for policy-relaxable categories.
- Hard-blocked operations (sudo, su) remain `Block` — never approvable.
- The interrupt payload flows through the existing HITL pipeline: `WaitingHuman` → TUI approval prompt → user answer → `AdvanceRun` → LLM retry → hook allows (via pending tracker) → tool executes.
- "Allow once" allows exactly one retry of the same operation within the session.
- "Deny" results in the LLM seeing the denial and not retrying.
- Noninteractive/headless mode returns `Block` instead of `RequireApproval` (configurable via `auto_deny_in_noninteractive`).
- Existing SafeGuard classifier tests continue to pass; new tests cover the approval decision path.
- Product-level validation: `castor run:agent-test` or `castor test:tui` to verify the full approval flow end-to-end.

## Out of scope

- "Always allow" automatic policy persistence — requires extension event subscription API (future work). Manual alternative: `/safe-guard allow` slash command.
- Approval-specific transcript rendering (badges, icons) — MVP uses the generic Question block.
- Per-tool-call approval (batch context) — when multiple tool calls run in parallel and one requires approval, the whole batch may or may not be interruptible. This depends on Hatfield's batch execution semantics.
- Approval audit log — separate from transcript, future work.

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
- Created: 2026-05-29T20:50:28.944Z
- Updated: 2026-05-30 — Rewrote task based on architectural discovery that existing HITL interrupt flow handles the entire approval lifecycle. Removed TUI-APPROVAL-01 dependency. Detailed the pending-approval tracker approach for "Allow once" behavior and the gap analysis for "Always allow" persistence.
