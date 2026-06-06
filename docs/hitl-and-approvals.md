# HITL and Approval Architecture

Human-in-the-loop (HITL) is the mechanism by which the agent runtime pauses
tool execution and asks a human (or an approval broker) for a decision before
proceeding with a potentially dangerous operation. This document describes the
end-to-end flow, from the moment a tool hook returns `RequireApproval` to the
moment the human's answer is routed back to the originating hook.

## Overview

```
                       ┌──────────────────────────┐
                       │  Tool call intercepted    │
                       │  by ExtensionToolHook     │
                       │  EventSubscriber          │
                       └────────────┬─────────────┘
                                    │  RequireApproval
                                    ▼
┌───────────┐   interrupt   ┌──────────────────┐   WaitingHuman   ┌────────────────────┐
│ Tool      │ ◄──────────── │ ToolCallResult   │ ───────────────► │ HitlMapping        │
│ Executor  │               │ Handler          │                  │ Subscriber         │
└───────────┘               └──────────────────┘                  └────────┬───────────┘
                                                                          │
                                                        human_input.requested
                                                                          │
                      ┌───────────────────────────────────────────────────┘
                      │
                      ▼
┌─────────────────┐   poll     ┌─────────────────┐   enqueue   ┌────────────────────┐
│ RuntimeEvent    │ ◄───────── │ TickPollListener │ ──────────► │ QuestionCoordinator│
│ Poller          │            │                 │            │ (FIFO queue)        │
└─────────────────┘            └─────────────────┘            └────────┬───────────┘
                                                                       │
                    ┌──────────────────────────────────────────────────┘
                    │  QuestionController.open() → overlay renders
                    │  above editor with SelectListWidget
                    ▼
┌─────────────────┐   answer_human cmd   ┌────────────────────┐
│ TUI (user)      │ ──────────────────► │ AnswerHumanHandler  │
│ selects answer  │                     │ (controller)        │
└─────────────────┘                     └────────┬───────────┘
                                                 │
                    ┌────────────────────────────┘
                    │  UserCommand → AgentSessionClient → runner.answerHuman()
                    ▼
┌─────────────────┐   HumanResponse   ┌─────────────────────────────────┐
│ AgentRunner     │ ────────────────► │ ExtensionApprovalAnswer         │
│ ::answerHuman() │                   │ Subscriber                      │
└─────────────────┘                   └────────────┬────────────────────┘
                                                   │
                                                   │  ApprovalAnswerHookInterface
                                                   │  ::onApprovalAnswered()
                                                   ▼
                                   ┌───────────────────────────┐
                                   │ SafeGuardToolCallHook     │
                                   │ (or any hook implementing │
                                   │  ApprovalAnswerHook)      │
                                   └───────────────────────────┘
```

## Runtime HITL event flow

### Event types

The `RuntimeEventTypeEnum` enum (`src/CodingAgent/Runtime/Protocol/RuntimeEventTypeEnum.php`)
defines the full HITL family:

| Enum case | String value | Purpose |
|-----------|-------------|---------|
| `HumanInputRequested` | `human_input.requested` | Emitted when the runtime needs a human answer |
| `HumanInputAnswered` | `human_input.answered` | Emitted after an answer is received |
| `HumanInputRejected` | `human_input.rejected` | Emitted when the question times out or is cancelled |
| `ApprovalRequested` | `approval.requested` | Reserved for future use |
| `ApprovalApproved` | `approval.approved` | Reserved for future use |
| `ApprovalRejected` | `approval.rejected` | Reserved for future use |

### From RequireApproval to human_input.requested

1. **Tool hook interception**: `ExtensionToolHookEventSubscriber::onToolCallRequested()`
   (`src/CodingAgent/Extension/ExtensionToolHookEventSubscriber.php`) iterates over
   registered tool call hooks. When a hook returns `ToolCallDecisionKindEnum::RequireApproval`,
   the subscriber:
   - Registers the hook + question_id in `ExtensionHookRegistry::registerPendingApproval()`
   - Sets the `ToolCallRequested` result to an interrupt DTO containing `question_id`,
     `prompt`, `schema`, `tool_call_id`, `tool_name`, and `approval_context`.

2. **Tool execution abort**: The tool executor sees the interrupt result and aborts the
   tool call. The `ToolCallResultHandler` produces a `WaitingHuman` domain event.

3. **HitlMappingSubscriber** maps `WaitingHuman` to a `RuntimeEvent` with type
   `human_input.requested`. The payload includes:
   ```json
   {
     "v": 1,
     "type": "human_input.requested",
     "runId": "<run_id>",
     "seq": <number>,
     "payload": {
       "question_id": "sg_<sha256>",
       "prompt": "Allow write outside working directory: /home/user/file?",
       "schema": {"type": "string", "enum": ["Allow once", "Always allow", "Deny"]},
       "tool_call_id": "call_00_<sha256>",
       "tool_name": "write"
     }
   }
   ```

4. **Runtime event emission**: The event is written to the session's
   `runtime-events.jsonl` projection and emitted through the runtime event FIFO queue
   (`InProcessAgentSessionClient` in-process mode) or stdout JSONL (controller mode).

### Answer routing: answer_human command

When the user provides an answer, the TUI (or an external broker) sends an
`answer_human` `UserCommand` with payload:

```json
{
  "type": "answer_human",
  "runId": "<run_id>",
  "payload": {
    "question_id": "sg_<sha256>",
    "answer": "Allow once"
  }
}
```

The command flows through:

- **TUI**: `TickPollListener` closure → `AgentSessionClient::send()` with `UserCommand`
- **In-process mode**: `InProcessAgentSessionClient::send()` → `AgentRunner::answerHuman()`
  → `CoreCommand(HumanResponse)` → run_control consumer → `ApplyCommandHandler`
- **Controller mode**: `JsonlProcessAgentSessionClient::send()` writes JSONL to stdin;
  controller's `AnswerHumanHandler` parses it → `AgentRunner::answerHuman()` (same as above)

After `ApplyCommandHandler` processes the `HumanResponse`, the
`ExtensionApprovalAnswerSubscriber` (`src/CodingAgent/Extension/ExtensionApprovalAnswerSubscriber.php`)
resolves the originating hook from `ExtensionHookRegistry::resolveApproval(questionId)` and
calls `ApprovalAnswerHookInterface::onApprovalAnswered()`.

### Controller vs in-process modes

| Aspect | In-process | Controller (JSONL) |
|--------|-----------|-------------------|
| Event transport | Shared `InProcessAgentSessionClient` FIFO queue | JSONL over stdin/stdout pipes |
| answer_human routing | Direct `AgentRunner::answerHuman()` call | `AnswerHumanHandler` parses JSONL → same runner path |
| Approval channel | N/A (same process) | `HATFIELD_APPROVAL_CHANNEL=controller` env var |
| Subprocess isolation | N/A | Controller + messenger consumers in separate processes |

## TUI question system

The TUI question system manages interactive overlays that pause the single-column
layout to request user input.

### QuestionRequest DTO

`src/Tui/Question/QuestionRequest.php` — immutable DTO:

| Field | Type | Purpose |
|-------|------|---------|
| `requestId` | `string` | Unique identifier (format: `hitl_<questionId>`) |
| `source` | `QuestionSource` | `Tui` (local questions) or `AgentCore` (runtime questions) |
| `kind` | `QuestionKind` | `Text`, `Confirm`, `Choice`, or `Approval` |
| `prompt` | `string` | Question text displayed to the user |
| `schema` | `array` | JSON Schema describing expected answer shape |
| `choices` | `list<QuestionOption>` | Structured options for choice/approval questions |
| `allowOther` | `bool` | Whether to show a "Type your answer" option |
| `secret` | `bool` | Whether to hide the answer in transcripts |
| `runId` | `?string` | Associated run ID (AgentCore questions only) |
| `questionId` | `?string` | Runtime question ID from the event (AgentCore only) |
| `toolCallId` | `?string` | Tool call that triggered the question |
| `toolName` | `?string` | Tool name that triggered the question |
| `transcript` | `bool` | Whether to record in session transcript |

### QuestionKind enum

`src/Tui/Question/QuestionKind.php`:

- `Text` — free-text input via editor (rendered as TextWidget banner above editor)
- `Confirm` — yes/no selection via SelectListWidget
- `Choice` — structured option selection via SelectListWidget
- `Approval` — approval-specific selection (SafeGuard), same widget but with
  display-only icons/colors and canonical answer values

### QuestionCoordinator

`src/Tui/Question/QuestionCoordinator.php` — per-session FIFO queue of `QuestionRequest`.

Key methods:
- `enqueue(QuestionRequest, ?Closure $onAnswer, ?Closure $onCancel)` — adds to queue or activates immediately
- `answer(mixed $value)` — resolves the active question; invokes the `$onAnswer` callback
- `cancel()` — cancels the active question (ESC); invokes the `$onCancel` callback
  with fail-safe "Deny" for AgentCore questions
- `reject()` — rejects without calling callbacks; advances queue
- `actionRequired()` — returns true when a question is active
- `hasRequest(string $requestId)` — duplicate guard for event replays
- `activeRequest()` / `activeStatus()` — current state accessors

Lifecycle: Pending → Answered / Cancelled / Rejected → advance to next queued

Callbacks run inside `try/finally` blocks so queue advancement always happens.

### QuestionController

`src/Tui/Question/QuestionController.php` — manages the interactive overlay lifecycle.

Methods:
- `setRuntimeRefs(TuiRuntimeContext, ChatScreen)` — wired by `TickPollListener::register()`
- `open(QuestionRequest)` — builds a `ContainerWidget` with header + widget (TextWidget or
  SelectListWidget), inserts it **above the editor** via `ChatScreen::insertOverlayBeforeEditor()`,
  sets focus, requests render
- `close()` — removes overlay, clears state, refreshes screen

Rendering order:
```
aboveEditorWidget → question overlay → editorSep → editor → belowEditorWidget → footerSep → footer
```

For `Approval` questions, the SelectListWidget shows items built from the schema `enum` with:
- Display-only UTF-8 icons: ✅ `Allow once`, 🔐 `Always allow`, ❌ `Deny`
- Theme colors per value: `Accent` / `Success` / `Error`
- Select keybindings: Arrow keys navigate, Enter selects, Escape cancels

**Answer values are canonical exact strings** — icons and colors are display-only.
The `value` field sent to `QuestionCoordinator::answer()` and then dispatched as
`answer_human` payload is always the raw enum string (e.g. `"Allow once"`,
`"Always allow"`, `"Deny"`).

### Integration points

- **`TickPollListener::handleHumanInputRequested()`** — receives `human_input.requested`
  runtime events from `RuntimeEventPoller::poll()`, builds a `QuestionRequest` with
  `QuestionSource::AgentCore` and `QuestionKind::Approval`, then enqueues it in
  `QuestionCoordinator` with answer and cancel callbacks that send `answer_human`
  commands via `AgentSessionClient::send()`. Cancel callback sends a fail-safe `Deny`.
- **`SubmitListener`** — routes editor text to the active question when the question
  is in Text mode and the user presses Enter.

## Extension approval flow

### How extensions request approval

Extensions implement `ToolCallHookInterface` (`src/CodingAgent/ExtensionApi/ToolCallHookInterface.php`).
The `onToolCall(ToolCallContextDTO)` method inspects the tool call and returns a
`ToolCallDecisionDTO` with one of:

- `Allow` — proceed normally
- `Block` — deny immediately
- `ReplaceResult` — substitute a different result
- `RequireApproval` — pause and ask for human decision

When `RequireApproval` is returned, the extension must also:

1. Generate a unique `question_id` (SafeGuard uses `sg_<sha256>`)
2. Provide a `prompt` describing the operation
3. Provide a `schema` with an `enum` of allowed answers
4. Include any `details` needed for later routing (stored in `approval_context`)

### How extensions receive answers

Extensions that need to process the human's answer implement
`ApprovalAnswerHookInterface` (`src/CodingAgent/ExtensionApi/ApprovalAnswerHookInterface.php`):

```php
interface ApprovalAnswerHookInterface
{
    public function onApprovalAnswered(ApprovalAnswerContextDTO $context): void;
}
```

The `ApprovalAnswerContextDTO` provides:
- `questionId` — the question ID from the RequireApproval return
- `answer` — the raw answer string (e.g. `"Allow once"`)
- `toolName` — the tool that was intercepted
- `approvalContext` — the full `details` array from the original RequireApproval

### Routing lifecycle

1. `ExtensionToolHookEventSubscriber::onToolCallRequested()` → `$hookRegistry->registerPendingApproval(questionId, hook, details)`
2. Runtime emits `WaitingHuman` → `human_input.requested` → TUI question overlay
3. User answers → `answer_human` command → `ApplyCommandHandler` → `HumanResponse` event
4. `ExtensionApprovalAnswerSubscriber::onAgentCommandApplied()` → `$hookRegistry->resolveApproval(questionId)`
   → calls `$hook->onApprovalAnswered(context)` if the hook implements the interface
5. The hook updates its internal state (e.g. SafeGuard marks operation as approved)

### Extension author considerations

This is an **internal API** that is not yet published as a standalone Composer package.
The `Ineersa\Hatfield\ExtensionApi` namespace is the designated future public surface.

Extension authors should:
- Implement `ToolCallHookInterface` for tool call interception
- Implement `ApprovalAnswerHookInterface` to receive human answers
- Return `RequireApproval` with a unique `question_id`, human-readable `prompt`, and an
  answer `schema` enum
- Handle the three canonical answer values or define their own via the schema enum
- Treat the `approvalContext` as the extension's private payload — it is round-tripped
  from `RequireApproval` → question → answer → `onApprovalAnswered()`

The canonical reference implementation is `SafeGuardToolCallHook`.

## SafeGuard architecture and modes

SafeGuard is a built-in extension (`src/CodingAgent/Extension/Builtin/SafeGuard/`).
It classifies tool calls (bash commands, file writes/edits, file reads) and decides
whether to allow, block, or require user approval.

### Components

| Class | Role |
|-------|------|
| `SafeGuardExtension` | Extension entry point; registers hooks and reads config |
| `SafeGuardToolCallHook` | Core hook implementing both `ToolCallHookInterface` and `ApprovalAnswerHookInterface` |
| `SafeGuardClassifier` | Classifies tool calls into decision kinds (WriteOutsideCwd, Destructive, etc.) |
| `SafeGuardPolicy` | In-memory policy loaded from YAML config |
| `SafeGuardPolicyWriter` | Persists "Always allow" patterns to `.hatfield/settings.yaml` |
| `SafeGuardConfig` | Config DTO (`enabled`, `toolAlias`, `autoDenyInNoninteractive`) |
| `ApprovalSessionTracker` | In-memory tracker: pending → approved → consumed lifecycle |

### Classification

`SafeGuardClassifier::classify()` checks tool name and arguments against the policy:

| Decision kind | Example | Relaxable? |
|--------------|---------|------------|
| `HardBlock` | `sudo`, `su` | No — always denied |
| `Destructive` | `rm -rf`, `mkfs` | Yes — requires approval |
| `DangerousGit` | `git push --force`, `git rebase` | Yes |
| `SensitiveInfo` | `env`, `printenv` | Yes |
| `WriteOutsideCwd` | `write` / `edit` targeting path outside CWD | Yes |
| `ProtectedRead` | Reading `.env.local`, `~/.ssh/id_*`, etc. | Yes |
| `CustomDangerous` | User-configured patterns | Yes |

Relaxable categories prompt the user for approval. Hard-blocked operations are
always denied.

### Three runtime modes

SafeGuard detects the execution context and chooses an appropriate behavior:

#### 1. Interactive TUI (approval-capable)

```
castor run:agent
  → php var/tmp/phar/hatfield.phar agent       # TUI mode
    → spawns controller: HATFIELD_APPROVAL_CHANNEL=controller
      → messenger consumers inherit the env var
```

- `hasApprovalChannel()` returns `true`
- `auto_deny_in_noninteractive` is irrelevant (user is present)
- SafeGuard returns `RequireApproval` for relaxable violations
- The TUI shows the approval overlay with ✅ / 🔐 / ❌ options
- User selects → answer_human dispatched → hook receives answer

#### 2. Headless approval-capable (controller + parent relay)

- Controller mode with `HATFIELD_APPROVAL_CHANNEL=controller`
- `human_input.requested` events propagate to the parent TUI (or broker)
  via the runtime event stream
- The parent/broker sends `answer_human` back to the controller
- SafeGuard returns `RequireApproval` → blocked until answer arrives

#### 3. Unattended noninteractive (fail-closed)

- No `HATFIELD_APPROVAL_CHANNEL` env var
- `auto_deny_in_noninteractive` defaults to `true`
- SafeGuard returns `Block` for relaxable violations with `auto_denied: true`
- The tool call is denied immediately; no question is ever shown
- If `auto_deny_in_noninteractive` is deliberately set to `false` without an
  approval channel, `RequireApproval` is returned but the run will hang in
  `WaitingHuman` forever — use only when an external approval broker is present

### `HATFIELD_APPROVAL_CHANNEL` env var

- **It is a capability signal, not a security boundary.** Any process with
  environment access can set it. It exists to tell SafeGuard that an approval
  path is available so `RequireApproval` is appropriate instead of `Block`.
- Set by `JsonlProcessAgentSessionClient::spawnProcess()` in
  `src/CodingAgent/Runtime/Process/JsonlProcessAgentSessionClient.php` (line 334)
  as `'HATFIELD_APPROVAL_CHANNEL' => 'controller'`.
- Inherited by messenger consumer subprocesses spawned by the controller's
  `ConsumerSupervisor`. This means tool workers (where SafeGuard runs) see the same
  env var.
- Not needed in in-process mode because the TUI and runtime share a process.

### Answer values

SafeGuard uses three canonical answer values (defined in the schema `enum`):

| Canonical value | Display label | Behavior |
|----------------|--------------|----------|
| `Allow once` | ✅ Allow once | Approves the current operation. The operation key is approved via `ApprovalSessionTracker::approveByQuestionId()`. On the next `onToolCall()`, `consumeApproval()` returns true and the tool proceeds. One-time use — consumed after execution. |
| `Always allow` | 🔐 Always allow | Same as "Allow once" PLUS persists the pattern to `.hatfield/settings.yaml` via `SafeGuardPolicyWriter::addAllowPattern()`. Future matching tool calls across sessions are allowed. |
| `Deny` | ❌ Deny | Denies the operation. Calls `removeByQuestionId()` to clean the pending mapping. The tool call is blocked — the LLM receives the denial result. |

**Fail-closed behavior**: cancel/ESC sends `Deny` via the cancel callback.
Unrecognized, empty, or non-scalar answers are silently ignored — the pending
entry remains and the operation stays blocked.

### Policy persistence

When the user chooses "Always allow", `SafeGuardPolicyWriter` persists the pattern:

| Category | Persists to | Settings key |
|----------|-----------|-------------|
| `destructive`, `dangerous_git`, `sensitive_info`, `custom_dangerous` | `extensions.settings.safe_guard.allow_command_patterns` | List of command strings |
| `write_outside_cwd` | `extensions.settings.safe_guard.allow_write_outside_cwd` | List of absolute paths |

Protected reads and hard-blocked operations are not persistable.

The writer uses **atomic write** (write to `.tmp.<pid>`, then rename) to avoid
partial writes. The settings file is loaded via Symfony YAML; the existing content
is preserved and the pattern is appended only if not already present (idempotent).

### Settings

See [Hatfield Settings](settings.md) for full configuration reference. Key SafeGuard
settings:

| Setting | Default | Purpose |
|---------|---------|---------|
| `extensions.settings.safe_guard.auto_deny_in_noninteractive` | `true` | Fail-closed when no approval channel |
| `extensions.settings.safe_guard.allow_command_patterns` | `[]` | Allowlisted command patterns |
| `extensions.settings.safe_guard.allow_write_outside_cwd` | `[]` | Allowlisted write/edit paths |
| `extensions.settings.safe_guard.protected_read_patterns` | `[]` | Additional protected read paths |

SafeGuard is enabled by default via `extensions.enabled` in `config/hatfield.defaults.yaml`.
