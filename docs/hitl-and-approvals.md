---
description: Human-in-the-loop questions, approval flows, and SafeGuard modes.
---

# HITL and Approval Architecture

Human-in-the-loop (HITL) covers two distinct flows where the agent runtime
pauses and asks the human for input before proceeding. Each serves a different
purpose and follows a different data path:

- **Path A — Extension approvals (SafeGuard / RequireApproval):** A tool call
  hook (`ToolCallHookInterface`) returns `RequireApproval`. The
  `ExtensionToolHookEventSubscriber` returns a typed
  `ToolExecutionHumanInputSuspension` (non-terminal). The tool worker exits;
  AgentCore admits the call into `ToolBatchStateDTO.awaitingHumanInput`, appends
  a FIFO `PendingHumanInputRequestDTO` with `continuation_kind=tool_call`, and
  transitions to `WaitingHuman`. Runtime projects `human_input.requested`. The
  TUI renders the schema-driven overlay. When the human answers via
  `answer_human`, `ApplyCommandHandler` attaches a typed internal answer to the
  exact stored `ExecuteToolCall`, requeues it through the existing batch
  scheduler, and dispatches it post-commit. On resume the originating
  `ApprovalAnswerHookInterface` maps Allow/Block/ReplaceResult. Allow runs the
  original tool handler for that exact call — **no extra LLM turn**.

- **Path B — Agent-driven questions (ask_human):** The LLM calls the
  model-facing `ask_human` tool. The tool returns an interrupt payload
  (`kind=interrupt`) through the normal Symfony AI Toolbox. AgentCore detects
  `kind=interrupt` generically and transitions the run to `WaitingHuman` with
  `continuation_kind=model_turn`. The runtime projects `human_input.requested`.
  When the human answers, `answer_human` appends a human-response message and
  the run resumes with a new LLM turn.

- **Local boolean ToolQuestion (bash background):** Unrelated to SafeGuard.
  `RuntimeBashBackgroundPromptAdapter` still uses `ToolQuestion` + blocking
  boolean poll + `answer_tool_question` for process-local bash prompts only.

Paths A and B share the same TUI question infrastructure
(`QuestionCoordinator`, `QuestionController`, `QuestionRequest`, `QuestionKind`)
via `human_input.requested`. Bash local prompts use `tool_question.requested`.

## Architecture principles

Both HITL mechanisms are **generic and extension-neutral** within their own
layers.

### Path A (extension approvals)

The CodingAgent infrastructure contains ZERO knowledge of any specific extension.
Approvals use canonical AgentCore WaitingHuman + `answer_human`, not ToolQuestion
polling. It exposes:

- A **typed non-terminal suspension** (`ToolExecutionHumanInputSuspension`)
- A **FIFO pending-human-input queue** on `RunState` with `continuation_kind=tool_call`
- Exact-call authority in **`ToolBatchStateDTO`** + capacity-aware requeue
- A **schema-driven TUI overlay** via existing `human_input.requested`

Extensions drive this through the [Extension API](#extension-approval-flow):
`ToolCallHookInterface::onToolCall()` returns `RequireApproval` with prompt,
schema, and metadata. The subscriber emits a typed suspension; on resume it
validates answer correlation, then calls `onApprovalAnswered()` (side-effects)
+ `resolveApprovalAnswer()` (outcome) for the originating hook only.

**Adding a new approval-granting extension requires zero SafeGuard-specific
changes** — only implementing the Extension API contracts. The canonical
reference is `SafeGuardToolCallHook`.

### Path B (agent-driven questions)

AgentCore contains ZERO knowledge of the `ask_human` tool name, schema, or
field shapes. It generically preserves any tool result whose `details` contain
`kind=interrupt` and transitions the run to `WaitingHuman`. The `ask_human`
name, parameter schema, field normalization, and payload construction all live
exclusively in CodingAgent (`src/CodingAgent/Tool/AskHumanTool.php`,
`AskHumanPayloadFactory`). AgentCore does NOT have a defensive fallback or
hardcoded check for `ask_human`.

The TUI question system (`TickPollListener::handleHumanInputRequested()`) is
similarly generic — it builds a `QuestionRequest` from the payload's fields
(prompt, `ui_kind`, choices, header, default) and enqueues it in the
`QuestionCoordinator`. Answer callbacks send `answer_human` commands with the
raw answer value.

## Overview — Path A (extension approvals)

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
│  1. Builds PendingHumanInputRequestDTO (continuation_kind=tool_call)        │
│  2. Returns ToolExecutionHumanInputSuspension via ToolResult (non-terminal) │
│  3. Worker exits — no ToolQuestion, no blocking poll                        │
└────────────────────────────────┬────────────────────────────────────────────┘
                                 │
                                 │  ToolCallResultHandler admits suspension
                                 │  → WaitingHuman + human_input.requested
                                 ▼
┌─────────────────────────────────────────────────────────────────────────────┐
│ TUI (canonical HITL)                                                        │
│  → human_input.requested overlay from schema (choice/confirm/text)          │
│  → answer_human with question_id + answer                                   │
└────────────────────────────────┬────────────────────────────────────────────┘
                                 │
                                 │  ApplyCommandHandler tool-call branch
                                 │  → attach ToolCallHumanInputAnswerDTO
                                 │  → requeue exact ExecuteToolCall
                                 ▼
┌─────────────────────────────────────────────────────────────────────────────┐
│ Resumed ExecuteToolCallWorker / ExtensionToolHookEventSubscriber            │
│                                                                             │
│  1. Validate answer correlation (hook_id/class, run/tool_call ids)          │
│  2. $hook->onApprovalAnswered($ctx)    // side-effects (e.g. policy write)  │
│  3. $hook->resolveApprovalAnswer($ctx) // Allow / Block / ReplaceResult     │
│  4. Allow → remaining hooks + real handler; Block/Replace → terminal result │
└─────────────────────────────────────────────────────────────────────────────┘
```

### Key properties (Path A)

- **Typed non-terminal suspension.** Worker returns suspension envelope and exits;
  no blocked poll thread.
- **Canonical WaitingHuman + human_input.requested.** Approvals share Path B TUI.
- **No extra LLM turn on Allow.** Exact stored `ExecuteToolCall` is re-dispatched;
  only the real handler result reaches the model.
- **Durable exact-call authority.** Batch state + typed internal answer metadata;
  no process-local approval tracker.
- **OCP-correct.** Any extension implementing `ToolCallHookInterface` +
  `ApprovalAnswerHookInterface` can drive the approval flow with its OWN
  vocabulary, schema, and outcome mapping — zero changes to any infra file.

## Overview — Path B (agent-driven questions)

```
LLM calls ask_human (e.g.: kind=choice, choices=["Yes", "No"])
  │
  ▼
AskHumanTool::__invoke()                       (CodingAgent)
  → AskHumanPayloadFactory::createPayload()
    → Symfony Serializer denormalizes args to AskHumanArgumentsDTO
    → Symfony Validator validates the DTO
    → buildPayload() derives schema from kind/choices
    → returns normalised array {kind:interrupt, question_id, prompt,
       schema, ui_kind, header?, choices?, default?}
  │
  ▼
Symfony AI Toolbox returns the array as a ToolResult  (CodingAgent)
  │
  ▼
ToolExecutor::toDomainResult()                 (AgentCore)
  → generically detects kind=interrupt in details
  → preserves full details array
  → NO ask_human special-case, NO field enumeration
  │
  ▼
ToolCallResultHandler::handle()
  → sees interrupt payload → transitions run to WaitingHuman
  │
  ▼
ToolCallExtractor::interruptPayloadFromToolResult()
  → generic passthrough: $payload = $interrupt + 5 typed fallbacks
  → NO ask_human-specific field names in AgentCore
  │
  ▼
RuntimeEventTranslator::onWaitingHuman()
  → projects human_input.requested with full payload
  │
  ▼
TUI RuntimeEventPoller::poll()                 (Tui)
  → picks up human_input.requested
  → calls TickPollListener::handleHumanInputRequested()
    → resolveQuestionKind() from ui_kind (text/confirm/choice/approval)
    → buildChoices() from payload choices (or schema.enum fallback)
    → builds QuestionRequest with header, default, allowOther always-on
    → enqueues in QuestionCoordinator
    → TickPollListener tick callback opens QuestionController overlay
  │
  ▼
User answers (or cancels) via the overlay
  → onAnswer: dispatches answer_human command via AgentSessionClient
    (boolean for confirm, string otherwise)
  → onCancel: dispatches answer_human with answer='Cancelled by user'
  │
  ▼
AnswerHumanHandler → ApplyCommandHandler       (AgentCore)
  → validates answer (empty/null/array rejected)
  → appends human-response message to run context
  → transitions WaitingHuman → Running → run continues with next LLM turn
```

### Key properties (Path B)

- **Immediate interrupt result.** The `ask_human` tool returns immediately
  with `kind=interrupt`. The run enters `WaitingHuman` while the human
  answers. This is the normal Async Tool → Interrupt contract.
- **Requires a human-triggered `answer_human` command.** The run stays
  `WaitingHuman` until the command arrives. There is no polling loop.
- **Extra LLM turn.** After the human answers, a human-response message is
  appended and the run resumes with a new LLM turn. The model sees the
  human's answer as the tool result.
- **AgentCore is tool-agnostic.** It only generically checks for
  `kind=interrupt` in any tool result's details. Tool implementations can
  be added, renamed, or removed without changing AgentCore.
- **Schema is derived internally.** `AskHumanPayloadFactory::resolveSchema()`
  derives the answer schema from `kind` and `choices`. The model never
  supplies raw JSON Schema — the `parametersJsonSchema` exposed to the model
  contains only simple string arrays for choices, never nested JSON Schema
  objects.
- **`allowOther` is always-on for the HITL path.** The "Type your answer"
  escape hatch is appended to Choice overlays automatically.

## Path A: From RequireApproval to tool execution (generic flow)

### Step-by-step

1. **Tool hook interception**: `ExtensionToolHookEventSubscriber::onToolCallRequested()`
   iterates registered hooks. On `RequireApproval` it builds a
   `PendingHumanInputRequestDTO` (`continuation_kind=tool_call`) with
   `hook_class`/`hook_id`, prompt/schema from the extension, and a continuation
   ref (`run_id`/`turn_no`/`step_id`/`tool_call_id`). It returns
   `ToolExecutionHumanInputSuspension` (non-terminal). The worker exits — no
   ToolQuestion row and no blocking poll.

2. **Admission**: `ToolCallResultHandler` admits the suspension into
   `ToolBatchStateDTO.awaitingHumanInput`, appends the FIFO pending request on
   `RunState`, emits `waiting_human`, and projects `human_input.requested`.

3. **TUI rendering**: existing HITL overlay path (`handleHumanInputRequested`)
   builds a schema-driven Choice/Confirm/Text overlay. Answers use
   `answer_human` (not `answer_tool_question`).

4. **Resume**: `ApplyCommandHandler` attaches a typed internal
   `ToolCallHumanInputAnswerDTO` to the exact stored `ExecuteToolCall`, requeues
   through capacity-aware batch dispatch, and marks the command applied only after
   post-commit effect dispatch succeeds. On resume the subscriber validates
   hook/call correlation, then calls `onApprovalAnswered` + `resolveApprovalAnswer`.

### Answer outcome (extension-owned)

1. **`onApprovalAnswered($context)`** — side-effects (e.g. SafeGuard policy write).
2. **`resolveApprovalAnswer($context)`** — `Allow` / `Block` / `ReplaceResult`.

The extension owns the complete answer vocabulary and outcome mapping.

### Cross-process safety

| Aspect | Effect |
|--------|-------|
| Tool question store | Shared SQLite (`state.sqlite`), accessible from all processes |
| Poll location | Tool consumer process — blocks the tool-worker thread |
| Answer location | Controller or worker process — writes to shared SQLite |
| Polling seam | `ExtensionToolHookEventSubscriber::handleRequireApproval()` |
| Crash/redelivery | Idempotent re-attach via `findByRequestId()` with deterministic requestId |
| Extension-specific persistence | Handled by the extension's `onApprovalAnswered()` (e.g. SafeGuardPolicyWriter) |
| No timeout / no TTL | Tool worker blocks indefinitely until answered; run stays halted

## Path B: Agent-driven questions (ask_human)

### Model-facing tool: `ask_human`

**Source:** `src/CodingAgent/Tool/AskHumanTool.php`

The `ask_human` tool is registered as a permanent tool with
`ToolExecutionMode::Interrupt`. When the model calls it, the tool executes
immediately and returns an interrupt payload that triggers `WaitingHuman`.

**Parameters (model-facing JSON Schema):**

| Parameter | Type | Required | Description |
|-----------|------|----------|-------------|
| `question` | `string` | Yes | The question or prompt to display to the user. |
| `kind` | `enum` (text\|confirm\|choice\|approval) | No | Question kind. "text" for free-form input, "confirm"/"approval" for yes/no (boolean), "choice" for selecting from options. |
| `choices` | `array<string>` | For choice | List of answer choices as simple strings. The system derives the answer schema from kind and choices. |
| `prompt` | `string` | No | Deprecated alias for `question`. Prefer `question` instead. |
| `ui_kind` | `enum` (text\|confirm\|choice\|approval) | No | Alias for `kind`. Overrides derivation from kind/choices if present. |
| `default` | `mixed` | No | Default answer value. The v1 UI does not auto-select it; included for reference. |
| `question_id` | `string` | No | Optional stable identifier. Generated from content hash if absent. |
| `header` | `string` | No | Optional header text shown above the question in the UI. |

> **Schema derivation:** The answer schema (returned in the payload as `schema`)
> is always derived internally by `AskHumanPayloadFactory::resolveSchema()`.
> The model never supplies raw JSON Schema — `choices` are accepted as simple
> string arrays, not JSON Schema objects. Derivation rules:
>
> - `confirm` / `approval` → `{"type": "boolean"}`
> - `choice` with `choices` → `{"type": "string", "enum": [...]}`
> - `text` → `{"type": "string"}`

**Prompt guidelines** (from `AskHumanTool::promptGuidelines`):

- Use `ask_human` when you need the user to provide information, confirm an
  action, or make a choice before proceeding.
- Provide a clear question in the `question` field. Set `kind` to
  `confirm`/`approval` for yes-no, `choice` with `choices` for a selection, or
  `text` for free-form input.
- For choices, provide `choices` as an array of simple strings.
- Optionally provide a `default` value and `header` for UI display.
- If the user cancels the question, the answer will be the string
  `'Cancelled by user'`. Treat this as an abort signal — do not retry the same
  question immediately.
- Use `ask_human` only when you need the user's answer before you can continue.

### End-to-end flow

```
LLM calls ask_human (e.g.: kind=choice, choices=["React", "Vue", "Svelte"])
  │
  ├── 1. AskHumanTool::__invoke()
  │     delegates to AskHumanPayloadFactory::createPayload()
  │     → Symfony Serializer denormalizes args to AskHumanArgumentsDTO
  │     → Symfony Validator validates (question required, choices
  │       must be non-empty strings when given)
  │     → buildPayload() derives schema from kind/choices
  │     → generates stable question_id from content hash
  │     → normalises bare-string choices to {label, description} objects
  │     → returns array with kind=interrupt
  │
  ├── 2. Symfony AI Toolbox returns the array as ToolResult.details
  │     ToolExecutor::toDomainResult() generically preserves
  │     kind=interrupt — no ask_human special-case in AgentCore
  │
  ├── 3. ToolCallResultHandler sees kind=interrupt in details
  │     → transitions run to RunStatus::WaitingHuman
  │     → run stays halted until answer_human command arrives
  │
  ├── 4. ToolCallExtractor::interruptPayloadFromToolResult()
  │     → generic passthrough: $payload = $interrupt + typed fallbacks
  │       for 5 core fields (question_id, tool_call_id, tool_name,
  │       prompt, schema)
  │     → NO field enumeration, NO ask_human-specific field names
  │
  ├── 5. RuntimeEventTranslator::onWaitingHuman()
  │     → projects human_input.requested runtime event with full payload
  │     → payload includes: kind=interrupt, question_id, prompt, schema,
  │       ui_kind, header?, choices?, default?
  │
  ├── 6. TUI RuntimeEventPoller::poll() picks up the event
  │     → TickPollListener::handleHumanInputRequested()
  │       → resolveQuestionKind(): reads ui_kind from payload,
  │         falls back to kind, then schema-derivation
  │         (interrupt transport marker is skipped)
  │       → buildChoices(): reads structured choices from payload,
  │         falls back to schema.enum
  │       → builds QuestionRequest with:
  │         - source: AgentCore
  │         - kind: from ui_kind (text/confirm/choice)
  │         - prompt, schema, header, default from payload
  │         - allowOther: true (always-on for HITL path)
  │         - transcript: true (recorded in session transcript)
  │       → enqueues in QuestionCoordinator with answer and
  │         cancel callbacks dispatching answer_human commands
  │
  ├── 7. TickPollListener tick callback opens QuestionController overlay
  │     → Choice: SelectListWidget with options from choices (plus
  │       "Type your answer" if choice kind)
  │     → Confirm: SelectListWidget with ✓ Yes / ✗ No (styled with
  │       theme colors)
  │     → Text: empty list, editor awaits input
  │
  ├── 8. User answers via the overlay
  │     → onAnswer callback:
  │       Confirm kind → answer normalised to boolean (yes=true)
  │       Choice/Text kind → answer sent as string
  │     → dispatches answer_human command via AgentSessionClient
  │
  ├── 9. User cancels (ESC from select list)
  │     → onCancel callback dispatches answer_human with
  │       answer='Cancelled by user'
  │     → The model receives this string as the tool result
  │
  └── 10. AnswerHumanHandler validates the answer
          → ApplyCommandHandler appends human-response message
          → WaitingHuman → Running transition
          → run continues with next LLM turn
```

### The cancel signal

When the user cancels a HITL question (ESC from the select list overlay),
the `onCancel` callback in `TickPollListener::handleHumanInputRequested()`
sends an `answer_human` command with `answer = 'Cancelled by user'`.

This is not a tool error. The model receives the string `'Cancelled by user'`
as the tool result. Treat this as an abort signal — the model should stop
attempting the current operation.

The ToolQuestion path uses `'cancel'` (without apostrophes) as its cancel
sentinel for `answer_tool_question`. The two paths use different sentinels
because they have different consumers and different fail-closed semantics.

### The "Type your answer" escape hatch

For questions with `kind = choice`, a "Type your answer" option is
automatically appended to the option list. Selecting it closes the overlay
and routes subsequent editor input as the answer when the user presses Enter.
The `SubmitListener` intercepts the Enter key while a question is pending
and routes the text to `QuestionCoordinator::answer()`.

Confirm/approval questions do NOT get the escape hatch — yes/no is
exhaustive for boolean questions.

The escape hatch is always-on for the AgentCore HITL path (`allowOther`
is hardcoded to `true` in `handleHumanInputRequested()`). The ToolQuestion
path (`handleToolQuestionRequested()`) keeps `allowOther: false` — its
enum or boolean schemas are exhaustive.

### Architecture boundary

**AgentCore must know nothing about specific tools.**

This is a hard-won design decision (applied retroactively during the QH-04
redesign). AgentCore contains zero references to `ask_human`, and
`ToolExecutor::toDomainResult()` has no `ask_user` or `ask_human` name
checks. The only contract it enforces is: if `details` contains
`kind=interrupt`, preserve the entire details array as the interrupt payload
and transition the run to `WaitingHuman`. Any tool — now or in the future —
can produce interrupt payloads without modifying AgentCore.

The `ask_human` name, parameter schema, field normalization, and payload
construction live exclusively in CodingAgent:
- `src/CodingAgent/Tool/AskHumanTool.php` — model-facing tool definition
- `src/CodingAgent/Tool/AskHuman/AskHumanPayloadFactory.php` — payload
  construction with Symfony Serializer + Validator
- `src/CodingAgent/Tool/AskHuman/AskHumanArgumentsDTO.php` — typed argument DTO

## TUI question system (shared)

The TUI question system manages interactive overlays that pause the single-column
layout to request user input. Both Path A (extension approvals) and Path B
(agent-driven questions) feed into this system, entering through different
listeners (`handleToolQuestionRequested` vs. `handleHumanInputRequested`).

### QuestionRequest DTO

`src/Tui/Question/QuestionRequest.php` — immutable DTO:

| Field | Type | Default | Purpose |
|-------|------|---------|---------|
| `requestId` | `string` | (required) | Unique identifier (format: `hitl_<questionId>` for HITL, `tool_<requestId>` for tool questions) |
| `source` | `QuestionSource` | (required) | `Tui` (local tool questions) or `AgentCore` (runtime HITL questions) |
| `kind` | `QuestionKind` | (required) | `Text`, `Confirm`, or `Choice` |
| `prompt` | `string` | (required) | Question text displayed to the user |
| `schema` | `array` | `['type' => 'string']` | JSON Schema describing expected answer shape |
| `choices` | `list<QuestionOption>` | `[]` | Structured options for choice questions |
| `default` | `mixed` | `null` | Default answer value. Pass-through only in v1 — the widget does NOT auto-select it. |
| `header` | `?string` | `null` | Optional header text above the question overlay |
| `allowOther` | `bool` | `true` | For AgentCore HITL questions: always-on (appends "Type your answer" to Choice overlays). For ToolQuestion/TUI path: `false` (enum/boolean schemas are exhaustive). |
| `runId` | `?string` | `null` | Associated run ID (AgentCore questions only) |
| `questionId` | `?string` | `null` | Runtime question ID from the event (AgentCore only) |
| `toolCallId` | `?string` | `null` | Tool call that triggered the question |
| `toolName` | `?string` | `null` | Tool name that triggered the question |
| `transcript` | `bool` | `false` | Whether to record in session transcript (true for AgentCore HITL, false for local tool questions) |

### QuestionKind enum

`src/Tui/Question/QuestionKind.php`:

- `Text` — free-text input via editor (rendered as TextWidget banner above editor)
- `Confirm` — yes/no selection via SelectListWidget (rendered with ✓/✗ icons and theme colors)
- `Choice` — structured option selection via SelectListWidget (driven from choices field, with "Type your answer" escape hatch for HITL)

### QuestionCoordinator

`src/Tui/Question/QuestionCoordinator.php` — per-session FIFO queue of `QuestionRequest`.

Key methods:
- `enqueue(QuestionRequest, ?Closure $onAnswer, ?Closure $onCancel)` — adds to queue or activates immediately
- `answer(mixed $value)` — resolves the active question; invokes the `$onAnswer` callback
- `cancel()` — cancels the active question (ESC); invokes the `$onCancel` callback
  (for HITL questions, sends `'Cancelled by user'`; for tool questions, sends `'cancel'`)
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
- `isOpen()` — whether the overlay is currently visible
- `isAwaitingFreeForm()` — whether the "Type your answer" escape hatch was selected
- `restoreFromFreeForm()` — returns from free-form mode to the select list (called by
  CancelListener when ESC is pressed during free-form typing)

Rendering order:
```
aboveEditorWidget → question overlay → editorSep → editor → belowEditorWidget → footerSep → footer
```

For `Choice` questions, the SelectListWidget shows items built from the
`choices` field. Each option is rendered as a button with its label. For
AgentCore HITL questions, a "Type your answer" option is appended. Select
keybindings: Arrow keys navigate, Enter selects, Escape cancels.

For `Confirm` questions, the overlay shows ✓ Yes / ✗ No with theme colors.

For `Text` questions, the overlay shows a hint banner and the editor awaits
input.

### Integration points

- **`TickPollListener::handleHumanInputRequested()`** — receives
  `human_input.requested` runtime events from `RuntimeEventPoller::poll()`.
  Resolves the `QuestionKind` from the payload's `ui_kind` field (with
  fallback to `kind`, then schema-driven derivation). Builds `QuestionOption`
  objects from the payload `choices` field (with fallback to `schema.enum`).
  Wires `header`, `default`, and `allowOther: true` into the
  `QuestionRequest`. Enqueues in `QuestionCoordinator` with:
  - **onAnswer:** For Confirm kind, normalises to boolean (`yes` = `true`).
    For Choice/Text, sends the raw answer string. Dispatches `answer_human`
    via `AgentSessionClient::send()`.
  - **onCancel:** Sends `answer_human` with `answer = 'Cancelled by user'`.
  See `src/Tui/Listener/TickPollListener.php` lines ~200–260.

- **`TickPollListener::handleToolQuestionRequested()`** — receives
  `tool_question.requested` runtime events. Schema-driven (has enum →
  Choice overlay, boolean → Confirm, else → Text). Sends
  `answer_tool_question` commands. Cancel sends `false` (for boolean) or
  `'cancel'` string. See lines ~400–580.

- **`SubmitListener`** — routes editor text to the active question when the
  question is in Text mode or the "Type your answer" escape hatch is active,
  and the user presses Enter.

- **`CancelListener`** — checks `QuestionController::isAwaitingFreeForm()`
  first. If true (user in "Type your answer" mode), calls
  `QuestionController::restoreFromFreeForm()` to return to the select list
  instead of cancelling the run. If false, proceeds with normal run
  cancellation.

## Path A: Extension approval flow

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

### Routing lifecycle (generic) — Path A

1. Hook returns `RequireApproval`.
2. Subscriber emits typed `ToolExecutionHumanInputSuspension` (no ToolQuestion poll).
3. AgentCore admits WaitingHuman + `human_input.requested`.
4. User answers via `answer_human`.
5. ApplyCommandHandler requeues exact `ExecuteToolCall` with typed internal answer.
6. On resume: `onApprovalAnswered` then `resolveApprovalAnswer` → Allow/Block/ReplaceResult.

### Extension author considerations — Path A

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

## Path A: SafeGuard architecture and modes

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

### Answer values (Path A — SafeGuard)

SafeGuard uses three canonical answer values (defined in the schema `enum`):

| Canonical value | Display label | Behavior |
|----------------|--------------|----------|
| `Allow once` | ✅ Allow once | Approves the exact suspended tool call. AgentCore re-dispatches the stored `ExecuteToolCall` with a typed internal answer; SafeGuard maps Allow and the real handler runs. One-shot — no process-local tracker. |
| `Always allow` | 🔐 Always allow | Same as "Allow once" PLUS persists the pattern to `.hatfield/settings.yaml` via `SafeGuardPolicyWriter::addAllowPattern()`. Future matching tool calls across sessions are allowed. |
| `Deny` | ❌ Deny | Denies the operation. The resumed call returns a blocked tool result to the model. |

**Fail-closed behavior**: cancel/ESC maps to cancelled (`Cancelled by user` /
`cancel`) and blocks the tool. Unrecognized answers fail closed as
`safeguard_unknown_answer` without echoing the raw answer.

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

## Known v1 limitations

- **No resume of pending HITL questions after session restart.** If a session
  is resumed while a run is `WaitingHuman`, the TUI does NOT restore the
  question overlay; the run stays paused. The user must cancel or rerun.
  (QH-08 deferred.)

- **No full JSON Schema form renderer.** The answer schema is derived internally
  from `kind` and `choices`. The model cannot supply arbitrary JSON Schema.
  This is a deliberate design decision — LLMs are unreliable at embedding JSON
  Schema syntax inside tool call arguments (JSON-in-JSON).

- **`default` has no widget auto-select.** The `default` value is passed through
  to the `QuestionRequest` DTO and the runtime payload, but the
  `SelectListWidget` does not pre-select it. Follow-up work needed for
  widget-level default support.

- **`allowOther` is always-on** for the AgentCore HITL path (hardcoded `true`
  in `handleHumanInputRequested()`) and not model-controllable. The model sees
  no `allow_other` parameter in the tool definition.

- **No secret/masking.** The `secret` field was briefly present in early HITL
  iterations (QH-04) but added only hint text (never actually masked input);
  it was removed in QH-06 as dead code.

## Manual smoke testing

To manually exercise the `ask_human` HITL flow:

1. Start the agent: `castor run:agent` (TUI mode).
2. Ask the model to call `ask_human`:
   - Choice: "Ask me a question with choices"
   - Confirm: "Ask me to confirm something"
   - Text: "Ask me a free-form question"
3. Observe the overlay appears. Answer via the select list or type a custom
   answer.
4. Verify the run resumes with a new LLM turn that incorporates your answer.
5. Cancel a question (ESC from the select list) and verify the model receives
   `'Cancelled by user'` — the model should stop attempting the operation.
6. For choice questions, select "Type your answer", type a custom answer, and
   press Enter. Verify the answer reaches the model.

### Test coverage

The full end-to-end HITL flow is covered by focused layer tests at each
stage and has been smoke-tested live. See `tests/AGENTS.md` for details on
test groups and conventions.

| Flow stage | Test file | What it proves |
|-----------|-----------|---------------|
| `ask_human` → interrupt payload | `tests/CodingAgent/Tool/AskHumanToolTest.php` | Tool returns correct `kind=interrupt` payload with all fields |
| Interrupt → generic preservation | `tests/AgentCore/Application/Handler/ToolExecutorTest.php` | AgentCore preserves `kind=interrupt` generically (no tool-specific code) |
| Interrupt → payload passthrough | `tests/AgentCore/Application/Pipeline/ToolCallExtractorTest.php` | Rich payload fields survive the extractor (QH-05) |
| WaitingHuman → event projection | `tests/CodingAgent/Runtime/RuntimeEventMapperTest.php` + `tests/CodingAgent/Runtime/Projection/TranscriptProjectorTest.php` | `human_input.requested` projected with correct fields (QH-06) |
| Event → TUI overlay → answer dispatch | `tests/Tui/Listener/TickPollListenerTest.php` | `QuestionRequest` built correctly, answer/cancel callbacks send expected commands (QH-06) |
| Answer → run resumes | `tests/CodingAgent/Runtime/Controller/CommandHandler/AnswerHumanHandlerTest.php` + `tests/AgentCore/Application/Pipeline/ApplyCommandHandlerTest.php` | Answer validated and applied; run state transitions correctly |


## Child subagent HITL in live view

Child `human_input.requested` / `tool_question.requested` events use the shared QuestionCoordinator with run-scoped request ids and headers like `Subagent <name> asks`. Answers and cancellations target the child run id. Orphan cleanup clears child attention when the user dismisses or navigates away.
