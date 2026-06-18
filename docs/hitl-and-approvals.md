# HITL and Approval Architecture

Human-in-the-loop (HITL) is the mechanism by which the agent runtime pauses
tool execution and asks a human (or an approval broker) for a decision before
proceeding with a potentially dangerous operation. This document describes the
end-to-end flow, from the moment a tool hook returns `RequireApproval` to the
moment the human's answer is routed back to the originating hook.

## Architecture principles

The HITL mechanism is **generic and extension-neutral**. The CodingAgent
infrastructure (subscribers, command handlers, TUI) contains ZERO knowledge
of any specific extension. It exposes:

- A **ToolQuestion store** (shared SQLite) — cross-process persistent state
- A **blocking-poll subscriber** — suspends the tool worker until the human answers
- A **schema-driven handler** — routes answers by schema type, not by extension kind
- A **schema-driven TUI overlay** — renders Choice/Confirm/Text based on the schema

Extensions drive this entire mechanism through the [Extension API](#extension-approval-flow):
`ToolCallHookInterface::onToolCall()` returns `RequireApproval` with a prompt,
schema, and metadata. The subscriber creates a generic ToolQuestion, blocks the
tool worker on a poll, renders a schema-driven overlay, routes the answer by
schema, and calls `onApprovalAnswered()` (side-effects) + `resolveApprovalAnswer()`
(outcome) on the hook.

**Adding a new approval-granting extension requires zero changes to the
CodingAgent infrastructure** — only implementing the Extension API contracts.
The canonical reference is `SafeGuardToolCallHook`.

## Overview

```
                       ┌──────────────────────────┐
                       │  Tool call intercepted    │
                       │  by ExtensionToolHook     │
                       │  EventSubscriber          │
                       └────────────┬─────────────┘
                                    │  RequireApproval
                                    ▼
┌─────────────────────────────────────────────────────────────────────────────┐
│ ExtensionToolHookEventSubscriber::handleRequireApproval()                    │
│                                                                             │
│  1. Computes deterministic requestId = hash(hookId)_{runId}_{toolCallId}    │
│  2. Creates ToolQuestion (kind=approval) in shared SQLite                   │
│     (schema and prompt from the extension's requireApproval DTO)           │
│  3. BLOCKS tool-worker thread polling pollAnswerText() — no interrupt       │
│     result, no WaitingHuman, no AdvanceRun, no extra LLM turn              │
└────────────────────────────────┬────────────────────────────────────────────┘
                                 │
                                 │  ToolQuestionPoller emits
                                 │  tool_question.requested
                                 │  (generic kind, schema from extension)
                                 ▼
┌─────────────────────────────────────────────────────────────────────────────┐
│ TUI TickPollListener (schema-driven)                                        │
│  → Inspects schema: has enum → Choice overlay (enum values = buttons)       │
│                    boolean  → Confirm overlay                                │
│                    else     → Text overlay                                   │
│  → onAnswer closure sends answer_tool_question with generic kind             │
│  → NO extension-specific knowledge — rendering is driven by schema          │
└────────────────────────────────┬────────────────────────────────────────────┘
                                 │
                                 │  answer_tool_question
                                 │  (kind=approval, answer=any string)
                                 ▼
┌─────────────────────────────────────────────────────────────────────────────┐
│ AnswerToolQuestionHandler (schema-driven)                                   │
│  → Looks up stored ToolQuestion by request_id                                │
│  → Inspects schema: boolean → answer() (boolean), enum/string → answerWithText() │
│  → Writes answer/answer_text column → pollAnswerText() returns → poll breaks │
│  → NO kind-based routing — routing is driven by stored schema               │
└────────────────────────────────┬────────────────────────────────────────────┘
                                 │
                                 ▼
┌─────────────────────────────────────────────────────────────────────────────┐
│ ExtensionToolHookEventSubscriber answer routing (generic)                   │
│                                                                             │
│  1. $hook->onApprovalAnswered($ctx)    // Side-effects (e.g. policy write)  │
│  2. $hook->resolveApprovalAnswer($ctx) // Extension returns ToolCallDecisionDTO │
│  3. Apply Allow/Block/ReplaceResult generically                            │
│                                                                             │
│  The extension owns the complete answer vocabulary and outcome mapping.     │
│  This subscriber contains ZERO extension-specific knowledge.                │
└─────────────────────────────────────────────────────────────────────────────┘
```

### Key properties

- **No interrupt result.** The subscriber does NOT set a ToolResult. The tool call
  remains active on the event, waiting for the poll to return. The Symfony AI
  toolbox's `RegistryBackedToolbox::execute()` blocks inside the subscriber
  because the subscriber runs synchronously in the event listener chain.
- **No WaitingHuman, no human_input.requested.** Approval questions flow through
  the ToolQuestion system (`tool_question.requested`), not the AgentCore HITL
  pipeline.
- **No extra LLM turn.** The LLM never sees a tool result from the interrupted
  call. After the poll returns, the real handler runs, and only the REAL result
  reaches the LLM.
- **Cross-process safe.** The ToolQuestion row lives in the shared SQLite DB
  (`messenger.sqlite`), accessible from all consumer processes. The tool worker
  blocks on the poll; the controller process writes the answer; the poll breaks.
- **OCP-correct.** Any extension implementing `ToolCallHookInterface` +
  `ApprovalAnswerHookInterface` can drive the approval flow with its OWN
  vocabulary, schema, and outcome mapping — zero changes to any infra file.
## From RequireApproval to tool execution (generic flow)

### Step-by-step

1. **Tool hook interception**: `ExtensionToolHookEventSubscriber::onToolCallRequested()`
   (`src/CodingAgent/Extension/ExtensionToolHookEventSubscriber.php`) iterates over
   registered tool call hooks. When a hook returns `ToolCallDecisionKindEnum::RequireApproval`,
   the subscriber:
   - Computes a **deterministic requestId** from the hook identity (crc32b hash of
     `$hook::class`), `runId`, and `toolCallId` — no extension-specific namespace.
   - Looks up an existing pending ToolQuestion by requestId. If not found, creates
     a new `ToolQuestion` with generic `kind=approval` in the shared SQLite store.
     The schema and prompt come from the extension's `requireApproval()` DTO.
   - **Blocks the tool-worker thread** in a polling loop (`pollAnswerText()`) with
     a 200ms interval — no timeout, no TTL. The run stays halted at the tool execution
     stage until the human answers.

2. **ToolQuestion event emission**: The `ToolQuestionPoller`
   (`src/CodingAgent/Runtime/Controller/ToolQuestionPoller.php`)
   picks up the pending ToolQuestion from the shared SQLite and emits a
   `tool_question.requested` runtime event. The payload includes the generic
   kind (`approval`), the extension-supplied prompt and schema, and the
   deterministic requestId:
   ```json
   {
     "type": "tool_question.requested",
     "payload": {
       "request_id": "<hookHash>_<runId>_<toolCallId>",
       "kind": "approval",
       "prompt": "Allow write outside working directory: /home/user/file?",
       "schema": {"type": "string", "enum": ["Allow once", "Always allow", "Deny"]},
       "tool_call_id": "call_00_<sha256>",
       "tool_name": "write"
     }
   }
   ```
   Note: the `schema`, `prompt`, and `enum` values are all supplied by the extension.
   The infrastructure treats them as opaque.

3. **TUI rendering (schema-driven)**: `TickPollListener::handleToolQuestionRequested()`
   inspects the schema (not the kind) to choose the overlay:
   - Schema has `enum` → `QuestionKind::Choice` overlay with buttons for each enum value
   - Schema type=boolean → `QuestionKind::Confirm` overlay (yes/no)
   - Else → `QuestionKind::Text` overlay
   
   The TUI sends `answer_tool_question` with `kind=approval` (generic) for
   Choice/Text overlays, and `kind=confirm` for boolean overlays. No extension-
   specific kind is used.

### Answer routing (schema-driven)

When the user answers, `AnswerToolQuestionHandler`
(`src/CodingAgent/Runtime/Controller/CommandHandler/AnswerToolQuestionHandler.php`)
routes the answer by the **stored question's schema**, not by a kind string:

1. Looks up the stored `ToolQuestion` by `request_id`.
2. Parses the stored schema:
   - Boolean schema → stores the answer via `answer()` (boolean column, legacy confirm path)
   - String/enum schema → stores via `answerWithText()` (answer_text column)
3. The blocking poll (`pollAnswerText()`) returns the non-null answer → poll breaks.

This is fully generic. NO kind-based routing exists. Adding a new approval-granting
extension requires zero changes to the handler.

### Answer outcome (extension-owned)

Once the poll returns, the subscriber calls two hooks on the extension:

1. **`onApprovalAnswered($context)`** — for side-effects (e.g., SafeGuard's
   `SafeGuardPolicyWriter` persists "Always allow" to `.hatfield/settings.yaml`).
2. **`resolveApprovalAnswer($context)`** — returns a `ToolCallDecisionDTO`:
   - `Allow` → handler runs (no `setResult`)
   - `Block` → denied result with extension-supplied reason/message
   - `ReplaceResult` → supplied result

The subscriber applies the returned decision generically using
`ToolCallDecisionKindEnum`. The extension owns the complete answer vocabulary
and outcome mapping.

### Cross-process safety

| Aspect | Effect |
|--------|-------|
| Tool question store | Shared SQLite (`messenger.sqlite`), accessible from all processes |
| Poll location | Tool consumer process — blocks the tool-worker thread |
| Answer location | Controller or worker process — writes to shared SQLite |
| Polling seam | `ExtensionToolHookEventSubscriber::handleRequireApproval()` |
| Crash/redelivery | Idempotent re-attach via `findByRequestId()` with deterministic requestId |
| Extension-specific persistence | Handled by the extension's `onApprovalAnswered()` (e.g. SafeGuardPolicyWriter) |
| No timeout / no TTL | Tool worker blocks indefinitely until answered; run stays halted

## TUI question system

The TUI question system manages interactive overlays that pause the single-column
layout to request user input.

### QuestionRequest DTO

`src/Tui/Question/QuestionRequest.php` — immutable DTO:

| Field | Type | Purpose |
|-------|------|---------|
| `requestId` | `string` | Unique identifier (format: `hitl_<questionId>`) |
| `source` | `QuestionSource` | `Tui` (local questions) or `AgentCore` (runtime questions) |
| `kind` | `QuestionKind` | `Text`, `Confirm`, or `Choice` |
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
- `Choice` — structured option selection via SelectListWidget (driven from schema enum)

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

For `Choice` questions (driven by an enum schema), the SelectListWidget shows
items built from the schema `enum` values. Each enum value is rendered as a
button with its raw label. Answer values are the raw enum strings dispatched
as `answer_tool_question` payload. Select keybindings: Arrow keys navigate,
Enter selects, Escape cancels.

### Integration points

- **`TickPollListener::handleHumanInputRequested()`** — receives `human_input.requested`
  runtime events from `RuntimeEventPoller::poll()`, builds a `QuestionRequest` with
  `QuestionSource::AgentCore` and `QuestionKind::Choice`, then enqueues it in
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

1. Generate a unique `question_id` (the infra treats it as opaque)
2. Provide a `prompt` describing the operation
3. Provide a `schema` (e.g. `['type' => 'string', 'enum' => [...]]`) — the infra
   drives rendering and routing from this schema without knowing its values
4. Include any `details` needed for later routing (stored in `approval_context`)

### How extensions receive answers

Extensions that need to process the human's answer implement
`ApprovalAnswerHookInterface` (`src/CodingAgent/ExtensionApi/ApprovalAnswerHookInterface.php`):

```php
interface ApprovalAnswerHookInterface
{
    public function onApprovalAnswered(ApprovalAnswerContextDTO $context): void;
    public function resolveApprovalAnswer(ApprovalAnswerContextDTO $context): ToolCallDecisionDTO;
}
```

`onApprovalAnswered()` is called first for side-effects (e.g., persisting "Always allow"
to settings). `resolveApprovalAnswer()` is called after and returns the tool-execution
decision: `Allow` (handler runs), `Block` (denied with extension-supplied reason), or
`ReplaceResult`. The extension owns the complete answer→outcome mapping.

The `ApprovalAnswerContextDTO` provides:
- `questionId` — the question ID from the RequireApproval return
- `answer` — the raw answer string (e.g. `"Allow once"`)
- `toolName` — the tool that was intercepted
- `approvalContext` — the full `details` array from the original RequireApproval

### Routing lifecycle (generic)

1. `ExtensionToolHookEventSubscriber::onToolCallRequested()` → a hook returns
   `ToolCallDecisionKindEnum::RequireApproval`.
2. `ExtensionToolHookEventSubscriber::handleRequireApproval()` creates a generic
   `ToolQuestion` (`kind=approval`, deterministic `requestId={hookHash}_{runId}_{toolCallId}`,
   schema and prompt from the extension's `requireApproval()` DTO) in the shared
   SQLite store and blocks polling `pollAnswerText()`. No interrupt result, no
   WaitingHuman, no human_input.requested.
3. `ToolQuestionPoller` emits `tool_question.requested` → TUI `TickPollListener`
   inspects the schema (has enum? → Choice overlay with enum values as buttons).
   The overlay labels come from the extension's schema — the TUI does not know
   what any label means.
4. User selects an option → `onAnswer` closure sends `answer_tool_question` command
   with `kind=approval` (generic) and the raw answer string.
5. `AnswerToolQuestionHandler` looks up the stored question by `request_id` and
   routes by schema type: enum/string → `answerWithText()`, boolean → `answer()`.
6. The blocking poll sees `pollAnswerText()` return a non-null value, breaks out.
7. `$hook->onApprovalAnswered($ctx)` — side-effects (e.g., SafeGuard persists
   "Always allow" to `.hatfield/settings.yaml`).
8. `$hook->resolveApprovalAnswer($ctx)` → returns `ToolCallDecisionDTO`.
   The subscriber applies Allow (handler runs), Block (denied), or ReplaceResult.
   The extension owns the complete answer→outcome mapping.

### Extension author considerations

This is an **internal API** that is not yet published as a standalone Composer package.
The `Ineersa\Hatfield\ExtensionApi` namespace is the designated future public surface.

Extension authors should:
- Implement `ToolCallHookInterface` for tool call interception
- Implement `ApprovalAnswerHookInterface` for both:
  - `onApprovalAnswered()` — side-effects (policy persistence, etc.)
  - `resolveApprovalAnswer()` — return `Allow`, `Block`, or `ReplaceResult`
- Return `RequireApproval` with a unique `question_id`, human-readable `prompt`, an
  answer `schema` enum, and any `details` metadata
- The extension's own vocabulary (prompt, enum values, labels, denied reasons) is
  private to the extension — the infrastructure treats all of it as opaque
- Treat the `approvalContext` (the `details` array from `requireApproval()`) as the
  extension's private payload — it is round-tripped from `RequireApproval` → question
  → answer → `onApprovalAnswered()`

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
