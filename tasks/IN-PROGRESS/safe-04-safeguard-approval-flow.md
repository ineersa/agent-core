# SAFE-04 SafeGuard approval flow over existing HITL interrupt

## Goal
Plan: `.pi/plans/extension-tool-hooks-safeguard-plan.md`
Related tasks: `EXT-HOOK-05` (RequireApproval decision kind), `SAFE-02` (SafeGuard extension MVP)

Wire SafeGuard's policy-relaxable classifications (destructive commands, writes outside CWD, protected reads, dangerous git, sensitive info) through the `RequireApproval` decision from EXT-HOOK-05. The approval reuses Hatfield's existing HITL interrupt flow — the tool is never executed until the user approves.

This task also fixes the answer-routing gap in the HITL pipeline and extends the ExtensionApi so extensions receive human answers and can act on them (e.g., "Allow once", "Always allow", "Deny").

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
│       schema: {type: 'string', enum: ['Allow once', 'Always allow', 'Deny']},│
│       details: {                                                             │
│         category: 'destructive',                                             │
│         command: 'rm -rf /tmp/build',                                        │
│         tool_name: 'bash',                                                   │
│       }                                                                      │
│     )                                                                        │
│                                                                               │
│ STEP 4: ExtensionToolHookEventSubscriber converts RequireApproval to          │
│   interrupt payload AND registers pending approval in ExtensionHookRegistry   │
│   → $event->setResult(new ToolResult($toolCall, [                            │
│       'kind' => 'interrupt',                                                 │
│       'question_id' => 'sg_abc123...',                                       │
│       'prompt' => 'Allow destructive command: ...?',                         │
│       'schema' => {...},                                                     │
│       'approval_context' => {category, command, tool_name},                  │
│     ]))                                                                      │
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
│   → TUI shows approval prompt with choices (requires QH-07)                  │
│                                                                               │
│ STEP 8: User selects "Allow once"                                             │
│   → TUI sends answer_human with answer = "Allow once"                        │
│   → AgentRunner::answerHuman() dispatches ApplyCommand(human_response)       │
│   → ApplyCommandHandler appends user message, sets Running, dispatches       │
│     AdvanceRun                                                                │
│   → Emits 'agent_command_applied' with kind=human_response                   │
│                                                                               │
│ STEP 9: ExtensionApprovalAnswerSubscriber intercepts answer (NEW)             │
│   → Detects agent_command_applied with kind=human_response                   │
│   → Looks up question_id in ExtensionHookRegistry::resolveApproval()         │
│   → Calls SafeGuardToolCallHook::onApprovalAnswered(context)                 │
│   → SafeGuard updates ApprovalSessionTracker based on answer:                │
│     "Allow once" → mark approved (consumed on next tool call)                │
│     "Always allow" → mark approved + persist pattern to policy file           │
│     "Deny" → remove pending entry                                            │
│                                                                               │
│ STEP 10: LLM resumes, sees the answer "Allow once" in context                │
│   → LLM decides to retry the tool call for "rm -rf /tmp/build"              │
│                                                                               │
│ STEP 11: SafeGuardToolCallHook::onToolCall() classifies again                 │
│   → Checks ApprovalSessionTracker → found and approved                       │
│   → Consumes approval (one-time)                                             │
│   → Returns ToolCallDecisionDTO::allow()                                     │
│   → Tool executes normally                                                   │
│                                                                               │
│ STEP 12: Tool result returned to LLM, run continues                           │
└─────────────────────────────────────────────────────────────────────────────┘
```

### The answer-routing gap (three layers to fix)

The existing HITL pipeline handles the forward direction (RequireApproval → WaitingHuman → TUI prompt) but **the answer never reaches the extension that requested approval**. Three gaps:

**Gap 1: `agent_command_applied` with `kind=human_response` is mapped to generic `status.updated`**

`CancelAndFallbackMappingSubscriber::onAgentCommandApplied()` treats ALL non-cancel commands as `status.updated`. The human_response `kind` and `question_id`/`answer` payload are lost. `RuntimeEventTypeEnum::HumanInputAnswered` exists but is **never emitted**. So `HitlProjectionSubscriber::onHumanInputAnswered()` is dead code — the Question transcript block stays `status: 'pending'` forever.

**Fix**: Add answer mapping in `HitlMappingSubscriber` that intercepts `agent_command_applied` with `kind=human_response` before `CancelAndFallbackMappingSubscriber` and maps it to `RuntimeEventTypeEnum::HumanInputAnswered`.

**Gap 2: Extensions have no way to receive answers**

`ExtensionApiInterface` only offers `registerTool()`, `registerToolCallHook()`, `registerToolResultHook()`. No event subscription, no answer callback.

**Fix**: Add `ApprovalAnswerHookInterface` and `ApprovalAnswerContextDTO` to ExtensionApi. This is an optional interface — extensions that care about answers implement it. The base `ToolCallHookInterface` stays unchanged.

**Gap 3: No bridge routes answers from AgentCore events back to extension hooks**

Even with the above fixes, nothing connects the answer event to the originating hook.

**Fix**: `ExtensionToolHookEventSubscriber` registers pending approvals (question_id → hook + context) when it processes `RequireApproval`. A new `ExtensionApprovalAnswerSubscriber` listens for `agent_command_applied` with `kind=human_response` and routes the answer back to the originating hook's `onApprovalAnswered()`.

## Scope

### Layer 1: Fix answer event mapping (CodingAgent Runtime)

1. **`HitlMappingSubscriber`** — add subscription for `agent_command_applied` events:
   ```php
   public static function getSubscribedEvents(): array
   {
       return [
           'waiting_human' => 'onWaitingHuman',
           'agent_command_applied' => 'onAgentCommandApplied',
       ];
   }

   public function onAgentCommandApplied(RunEventMappingEvent $event): void
   {
       if ($event->handled) return;
       $p = $event->runEvent->payload;
       if ('human_response' !== ($p['kind'] ?? '')) return;

       $event->handled = true;
       $event->mappedRuntimeEvent = new RuntimeEvent(
           type: RuntimeEventTypeEnum::HumanInputAnswered->value,
           runId: $event->runEvent->runId,
           seq: $event->runEvent->seq,
           payload: [
               'question_id' => (string) ($p['question_id'] ?? ''),
               'answer' => (string) ($p['options']['answer'] ?? ''),
           ],
       );
   }
   ```

   This must run before `CancelAndFallbackMappingSubscriber`. Symfony EventDispatcher respects subscriber priority — set higher priority on HitlMappingSubscriber for `agent_command_applied`, or rely on registration order.

2. **Verify `HitlProjectionSubscriber::onHumanInputAnswered()`** works after the mapping fix. It already exists and updates the Question transcript block status to `'answered'` — it was just dead code until now.

### Layer 2: Extend ExtensionApi with answer callback

3. **`ApprovalAnswerHookInterface`** — new interface in `src/CodingAgent/ExtensionApi/`:
   ```php
   namespace Ineersa\Hatfield\ExtensionApi;

   /**
    * Optional interface for tool call hooks that requested approval
    * and want to receive the human's answer.
    *
    * A hook implementing this interface will be called back when the
    * human answers a RequireApproval decision that originated from
    * this hook. The hook can update its internal state based on the
    * answer (e.g., "Allow once" vs "Always allow" vs "Deny").
    */
   interface ApprovalAnswerHookInterface
   {
       public function onApprovalAnswered(ApprovalAnswerContextDTO $context): void;
   }
   ```

4. **`ApprovalAnswerContextDTO`** — new DTO in `src/CodingAgent/ExtensionApi/`:
   ```php
   namespace Ineersa\Hatfield\ExtensionApi;

   final readonly class ApprovalAnswerContextDTO
   {
       /**
        * @param array<string, mixed> $approvalContext  the details from the original RequireApproval
        */
       public function __construct(
           public string $questionId,
           public string $answer,
           public string $toolName,
           public array $approvalContext,
       ) {}
   }
   ```

   Both live in `ExtensionApi` — PHP-native types only, no Symfony/AgentCore dependencies. Deptrac-clean.

### Layer 3: CodingAgent bridge routes answers to hooks

5. **`ExtensionHookRegistry`** — add pending approval tracking:
   ```php
   /** @var array<string, ApprovalPendingEntry> question_id → {hook, details} */
   private array $pendingApprovals = [];

   public function registerPendingApproval(
       string $questionId,
       ToolCallHookInterface $hook,
       array $details,
   ): void;

   public function resolveApproval(string $questionId): ?ApprovalPendingEntry;
   ```

   Called by `ExtensionToolHookEventSubscriber` when it processes `RequireApproval`.

6. **`ExtensionToolHookEventSubscriber`** — store pending approval on RequireApproval:
   ```php
   if (ToolCallDecisionKindEnum::RequireApproval === $decision->kind) {
       // ... existing interrupt payload creation ...

       $this->hookRegistry->registerPendingApproval(
           questionId: $questionId,
           hook: $hook,  // the hook that returned RequireApproval
           details: $decision->details,
       );

       $event->setResult(new ToolResult($toolCall, [...]));
       return;
   }
   ```

7. **`ExtensionApprovalAnswerSubscriber`** — new subscriber in `src/CodingAgent/Extension/`:
   ```php
   /**
    * Routes human approval answers back to the extension hook that
    * requested them. Listens to AgentCore 'agent_command_applied' events
    * with kind=human_response and calls onApprovalAnswered() on the
    * originating hook (if it implements ApprovalAnswerHookInterface).
    */
   final readonly class ExtensionApprovalAnswerSubscriber implements EventSubscriberInterface
   {
       public function __construct(
           private ExtensionHookRegistry $hookRegistry,
       ) {}

       public static function getSubscribedEvents(): array
       {
           return ['agent_command_applied' => 'onAgentCommandApplied'];
       }

       public function onAgentCommandApplied(RunEventMappingEvent $event): void
       {
           $p = $event->runEvent->payload;
           if ('human_response' !== ($p['kind'] ?? '')) return;

           $questionId = (string) ($p['question_id'] ?? '');
           $entry = $this->hookRegistry->resolveApproval($questionId);
           if (null === $entry) return;

           if ($entry->hook instanceof ApprovalAnswerHookInterface) {
               $entry->hook->onApprovalAnswered(new ApprovalAnswerContextDTO(
                   questionId: $questionId,
                   answer: (string) ($p['options']['answer'] ?? ''),
                   toolName: $entry->details['tool_name'] ?? '',
                   approvalContext: $entry->details,
               ));
           }
       }
   }
   ```

   Note: this subscriber listens at the AgentCore event mapping level (same level as `HitlMappingSubscriber` and `CancelAndFallbackMappingSubscriber`). Both the answer routing to hooks AND the mapping to `human_input.answered` must fire — the answer subscriber does NOT mark `$event->handled = true` so the mapping subscriber can still process it.

### Layer 4: SafeGuard extension changes

8. **`ApprovalSessionTracker`** — new class in `Extension/Builtin/SafeGuard/`:
   ```php
   final class ApprovalSessionTracker
   {
       /** @var array<string, bool> key => approved */
       private array $approved = [];

       /** @var array<string, string> question_id => key */
       private array $pendingByQuestionId = [];

       public function markPending(string $questionId, string $key): void;
       public function approve(string $key): void;
       public function consumeApproval(string $key): bool;  // removes after returning
       public function remove(string $key): void;
       public function isApproved(string $key): bool;
   }
   ```

   This is a simple in-memory tracker. Since all tool execution within a run happens in the same Messenger consumer process, a singleton-scoped service works. It is NOT persisted across runs.

9. **`SafeGuardToolCallHook`** — implement `ApprovalAnswerHookInterface`:
   - Before classification: check `ApprovalSessionTracker::isApproved($key)` → if yes, consume and return `Allow`
   - After classification: for policy-relaxable decisions (Destructive, DangerousGit, SensitiveInfo, WriteOutsideCwd, ProtectedRead, CustomDangerous):
     - If in policy allowlist → `Allow` (existing behavior from SAFE-02)
     - If NOT in allowlist → generate `questionId`, `markPending()`, return `RequireApproval`
   - Hard-blocked decisions (HardBlock: sudo, su) → `Block` (never approvable, existing behavior)
   - Generate tracker key from classification: `{category}:{normalized_command_or_path}`

   ```php
   public function onApprovalAnswered(ApprovalAnswerContextDTO $context): void
   {
       $category = $context->approvalContext['category'] ?? '';
       $command = $context->approvalContext['command'] ?? $context->approvalContext['path'] ?? '';
       $key = $this->trackerKey($category, $command);

       if ('Deny' === $context->answer) {
           $this->tracker->remove($key);
           return;
       }

       if ('Allow once' === $context->answer) {
           $this->tracker->approve($key);
           return;
       }

       if ('Always allow' === $context->answer) {
           $this->tracker->approve($key);
           $this->policyWriter->addAllowPattern($category, $command);
       }
   }
   ```

10. **`SafeGuardPolicyWriter`** — new class for policy file mutation ("Always allow"):
    ```php
    final class SafeGuardPolicyWriter
    {
        public function __construct(
            private string $cwd,
            private SafeGuardConfig $config,
        ) {}

        public function addAllowPattern(string $category, string $pattern): void
        {
            // Read current policy or create new
            // Add pattern to the correct allowlist field based on category:
            //   destructive → allow_command_patterns
            //   dangerous_git → allow_command_patterns
            //   sensitive_info → allow_command_patterns
            //   write_outside → allow_write_outside_cwd
            //   protected_read → (remove from protected_read_patterns? or add to allowlist?)
            // Write back to .hatfield/safe-guard.json
        }
    }
    ```

11. **Noninteractive/headless behavior**:
    - When no TUI is attached, `RequireApproval` would hang forever at `WaitingHuman`
    - SafeGuard should detect headless mode and return `Block` instead of `RequireApproval`
    - Config knob: `safe_guard.auto_deny_in_noninteractive: true` (default: true)
    - Add to `SafeGuardConfig` and `hatfield.defaults.yaml`

### Config changes

12. **`config/hatfield.defaults.yaml`** — add under `safe_guard` settings:
    ```yaml
    auto_deny_in_noninteractive: true
    ```

### Deptrac

13. **`depfile.yaml`** — add `ApprovalAnswerHookInterface` and `ApprovalAnswerContextDTO` to the `AppExtensionApi` layer (same as other ExtensionApi types).

## Classification → decision mapping

| SafeGuardDecisionKind | Current (SAFE-02) | With approval (SAFE-04) |
|---|---|---|
| Allow | `ToolCallDecisionDTO::allow()` | Same |
| HardBlock (sudo, su) | `ToolCallDecisionDTO::block()` | Same — never approvable |
| DestructiveCommand | `ToolCallDecisionDTO::block()` | `RequireApproval` (if not approved or in policy allowlist) |
| DangerousGit | `ToolCallDecisionDTO::block()` | `RequireApproval` |
| SensitiveInfo (env, printenv) | `ToolCallDecisionDTO::block()` | `RequireApproval` |
| WriteOutsideCwd | `ToolCallDecisionDTO::block()` | `RequireApproval` |
| ProtectedRead | `ToolCallDecisionDTO::block()` | `RequireApproval` |
| CustomDangerous | `ToolCallDecisionDTO::block()` | `RequireApproval` |

## Tracker key examples

```
destructive:rm -rf /tmp/build
destructive:git reset --hard HEAD
dangerous_git:git push --force origin main
sensitive_info:env
write_outside:/etc/config.yaml
protected_read:.env.local
```

## New files

```
src/CodingAgent/ExtensionApi/ApprovalAnswerHookInterface.php
src/CodingAgent/ExtensionApi/ApprovalAnswerContextDTO.php
src/CodingAgent/Extension/ExtensionApprovalAnswerSubscriber.php
src/CodingAgent/Extension/Builtin/SafeGuard/ApprovalSessionTracker.php
src/CodingAgent/Extension/Builtin/SafeGuard/SafeGuardPolicyWriter.php
```

## Modified files

```
src/CodingAgent/Extension/ExtensionHookRegistry.php             — add pending approval tracking
src/CodingAgent/Extension/ExtensionToolHookEventSubscriber.php  — register pending on RequireApproval
src/CodingAgent/Runtime/Mapping/HitlMappingSubscriber.php       — add answer event mapping
src/CodingAgent/Extension/Builtin/SafeGuard/SafeGuardToolCallHook.php — implement ApprovalAnswerHookInterface, add approval logic
src/CodingAgent/Extension/Builtin/SafeGuard/SafeGuardExtension.php    — wire tracker + writer into hook
src/CodingAgent/Extension/Builtin/SafeGuard/SafeGuardConfig.php       — add auto_deny_in_noninteractive
config/hatfield.defaults.yaml                                  — add auto_deny_in_noninteractive
depfile.yaml                                                   — new ExtensionApi types
```

## Acceptance criteria

- **Layer 1 — Answer mapping**: `agent_command_applied` with `kind=human_response` maps to `RuntimeEventTypeEnum::HumanInputAnswered`. `HitlProjectionSubscriber::onHumanInputAnswered()` updates Question transcript blocks to `status: 'answered'`.
- **Layer 2 — ExtensionApi**: `ApprovalAnswerHookInterface` and `ApprovalAnswerContextDTO` live in `src/CodingAgent/ExtensionApi/`, use only PHP-native types, and pass deptrac as `AppExtensionApi` layer.
- **Layer 3 — Answer bridge**: `ExtensionApprovalAnswerSubscriber` receives `agent_command_applied` with `kind=human_response`, resolves the originating hook from `ExtensionHookRegistry`, calls `onApprovalAnswered()` if the hook implements `ApprovalAnswerHookInterface`. Does NOT interfere with the event mapping subscriber (both process the same event).
- **Layer 4 — SafeGuard approval flow**:
  - SafeGuard classifies dangerous operations and returns `RequireApproval` for policy-relaxable categories.
  - Hard-blocked operations (sudo, su) remain `Block` — never approvable.
  - The interrupt payload flows through the existing HITL pipeline: `WaitingHuman` → TUI/controller answer → `AdvanceRun` → LLM retry → hook allows → tool executes.
  - "Allow once" allows exactly one retry of the same operation within the session. Approval is consumed on use.
  - "Always allow" persists the pattern to the policy file AND allows the current retry.
  - "Deny" removes the pending entry; LLM sees denial and does not retry.
  - Noninteractive/headless mode returns `Block` instead of `RequireApproval` (configurable via `auto_deny_in_noninteractive`).
- **Tests**: Existing SafeGuard classifier tests continue to pass; new tests cover:
  - `HitlMappingSubscriber` answer mapping (agent_command_applied → HumanInputAnswered)
  - `ExtensionHookRegistry` pending approval registration and resolution
  - `ExtensionApprovalAnswerSubscriber` answer routing
  - `ApprovalSessionTracker` lifecycle (pending → approved → consumed)
  - `SafeGuardToolCallHook` RequireApproval for each relaxable category
  - `SafeGuardToolCallHook::onApprovalAnswered()` for "Allow once", "Always allow", "Deny"
  - Noninteractive auto-deny
  - HardBlock stays Block
  - Allowlist bypass still works
- **Validation**: `castor test --filter SafeGuard`, `castor test --filter HitlMapping`, `castor test --filter Extension`, `castor deptrac`.

## Out of scope

- **TUI interactive approval dialog** — QH-07 (`tasks/TODO/qh-07-bind-hitl-to-question-coordinator.md`) owns wiring `RuntimeEventPoller` → `QuestionCoordinator::enqueue()`. SAFE-04 validates the approval flow at the controller/runtime level; the TUI overlay is QH-07's scope.
- **Approval-specific transcript rendering** (badges, icons) — MVP uses the generic Question block.
- **Per-tool-call approval** (batch context) — when multiple tool calls run in parallel and one requires approval, the whole batch may or may not be interruptible. This depends on Hatfield's batch execution semantics.
- **Approval audit log** — separate from transcript, future work.

## Workflow metadata
Status: IN-PROGRESS
Branch: task/safe-04-safeguard-approval-flow
Worktree: /home/ineersa/projects/agent-core-worktrees/safe-04-safeguard-approval-flow
Fork run:
PR URL:
PR Status:
Started: 2026-05-31T17:35:50.684Z
Completed:

## Work log
- Created: 2026-05-29T20:50:28.944Z
- Updated: 2026-05-30 — Rewrote task based on architectural discovery that existing HITL interrupt flow handles the entire approval lifecycle. Removed TUI-APPROVAL-01 dependency. Detailed the pending-approval tracker approach for "Allow once" behavior and the gap analysis for "Always allow" persistence.
- Updated: 2026-05-31 — Major rewrite: replaced time-bounded tracker hack with proper three-layer answer routing architecture. Added Layer 1 (answer event mapping fix), Layer 2 (ExtensionApi `ApprovalAnswerHookInterface`), Layer 3 (CodingAgent answer bridge subscriber). SafeGuard now receives actual human answers and can distinguish "Allow once", "Always allow", and "Deny". "Always allow" persistence included via `SafeGuardPolicyWriter`.

## Task workflow update - 2026-05-31T17:35:50.684Z
- Moved TODO → IN-PROGRESS.
- Created branch task/safe-04-safeguard-approval-flow.
- Created worktree /home/ineersa/projects/agent-core-worktrees/safe-04-safeguard-approval-flow.
- Copied vendor directory into /home/ineersa/projects/agent-core-worktrees/safe-04-safeguard-approval-flow.
- Copied .vera index into /home/ineersa/projects/agent-core-worktrees/safe-04-safeguard-approval-flow.

## Task workflow update - 2026-05-31T17:58:12.714Z
- Reviewer found critical process isolation gap: ExtensionApprovalAnswerSubscriber runs in controller process but ExtensionHookRegistry (pending approvals) lives in tool worker — onApprovalAnswered() never fires in async mode. Also flagged SafeGuardPolicyWriter silent data loss on YAML parse failure, silent @mkdir/file_put_contents failures, SafeGuardConfig bool cast footgun. Launching fix fork.
