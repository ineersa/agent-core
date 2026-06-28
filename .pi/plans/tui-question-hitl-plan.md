# TUI questions and ask_human HITL tool plan

Date: 2026-05-17

## Purpose

Define a reusable TUI question/approval interaction layer and bind AgentCore human-in-the-loop (HITL) requests to it through an `ask_human` tool.

This plan separates two concepts:

1. **Local TUI questions** — ephemeral UI/application prompts such as "move long-running command to background?" or "change model?".
2. **AgentCore HITL questions** — agent/tool-originated prompts that pause a run, are replayable, and appear in transcript projection.

Only AgentCore HITL questions are persisted as transcript/runtime projection data. Local TUI questions are UI state only.

## Current code facts

Relevant existing pieces:

- `src/CodingAgent/Runtime/Contract/UserCommand.php`
  - supports `answer_human`.
- `src/AgentCore/Domain/Run/RunStatus.php`
  - includes `WaitingHuman`.
- `src/AgentCore/Domain/Command/CoreCommandKind.php`
  - includes `HumanResponse`.
- `src/AgentCore/Application/Pipeline/ToolCallResultHandler.php`
  - when a tool result contains interrupt details, emits `waiting_human` and transitions the run to `WaitingHuman`.
- `src/AgentCore/Application/Pipeline/ApplyCommandHandler.php`
  - accepts `human_response` only while the run is `WaitingHuman`, appends a human-response message, transitions back to `Running`, and advances the run.
- `src/AgentCore/Application/Pipeline/ToolCallExtractor.php`
  - detects interrupt payloads when tool result details or raw result contains `kind: interrupt`.
- `src/AgentCore/Application/Handler/ToolExecutor.php`
  - already has an interrupt result helper and special-cases `ask_user` or interrupt execution mode.
  - when a Symfony toolbox tool returns `['kind' => 'interrupt', ...]`, `toDomainResult()` copies that into result details, so the current pipeline can already detect it.

Existing interrupt payload shape:

```php
[
    'kind' => 'interrupt',
    'question_id' => '...',
    'prompt' => '...',
    'schema' => ['type' => 'string'],
]
```

## Codex scouting notes

A scout inspected `/home/ineersa/claw/codex` for questions, approvals, and HITL flows. Useful references:

- `codex-rs/protocol/src/request_user_input.rs`
  - core `RequestUserInputQuestion`, `RequestUserInputArgs`, `RequestUserInputResponse`, and `RequestUserInputEvent` types.
- `codex-rs/tools/src/request_user_input_tool.rs`
  - model-visible `request_user_input` tool spec and argument normalization.
- `codex-rs/core/src/tools/handlers/request_user_input.rs`
  - tool handler that parses model arguments, asks session for input, and returns serialized response to the model.
- `codex-rs/core/src/session/mod.rs`
  - `request_user_input()` creates a oneshot channel, stores the sender in active turn state, emits `EventMsg::RequestUserInput`, then awaits the answer.
  - `notify_user_input_response()` looks up the pending sender and delivers the response.
- `codex-rs/tui/src/bottom_pane/request_user_input/mod.rs`
  - large question overlay implementation.
- `codex-rs/tui/src/bottom_pane/approval_overlay.rs`
  - separate approval overlay with queued approval requests.
- `codex-rs/tui/src/bottom_pane/mod.rs`
  - `push_user_input_request()` and `push_approval_request()` disable composer input while action is required.
- `codex-rs/core/src/codex_delegate.rs`
  - child/session HITL forwarding and guardian auto-review path.
- `codex-rs/core/src/mcp_tool_call.rs`
  - MCP tool approval can fallback to `request_user_input` compatibility questions.

Codex question shape:

```rust
RequestUserInputQuestion {
    id: String,
    header: String,
    question: String,
    is_other: bool,
    is_secret: bool,
    options: Option<Vec<RequestUserInputQuestionOption>>,
}

RequestUserInputQuestionOption {
    label: String,
    description: String,
}

RequestUserInputResponse {
    answers: HashMap<String, RequestUserInputAnswer>,
}
```

Codex core flow:

```text
model calls request_user_input
  -> tool handler validates/normalizes args
  -> Session::request_user_input() stores oneshot sender and emits RequestUserInput event
  -> TUI shows RequestUserInputOverlay and disables composer
  -> user submits answers
  -> app/server resolves request
  -> Session::notify_user_input_response() completes oneshot
  -> tool handler returns serialized answer to model
```

Codex TUI UX details worth copying:

- Disable normal composer input while a HITL question is active.
- Show an action-required surface/status while waiting for user input.
- Queue incoming questions/approvals rather than dropping them.
- Use structured choice options with `label` and `description`, not only bare strings.
- Keep approvals separate from generic questions, but allow both to share presentation primitives.
- Do not copy Codex's very large overlay module shape; split DTOs, coordinator, renderer, input routing, and HITL adapter.

Important design difference for this project:

- Codex blocks the tool handler on a oneshot until the user answers.
- AgentCore already has a persistent state-machine flow: tool returns interrupt payload -> run enters `WaitingHuman` -> runtime projects HITL -> TUI sends `answer_human` -> run continues.
- Prefer AgentCore's existing `WaitingHuman` / `HumanResponse` flow. Do **not** make `ask_human` block inside the tool implementation.

## Goals

- Add a reusable TUI question/approval widget system.
- Add model-visible `ask_human()` tool for HITL.
- Bind AgentCore `waiting_human` runtime events to the same TUI question widget.
- Keep local TUI questions out of transcript/runtime projection.
- Keep HITL questions replayable and transcript-visible.
- Preserve architecture boundaries: `src/Tui/` must not import AgentCore internals.

## Non-goals

- Full JSON Schema form renderer.
- Complex multi-step wizard UI.
- Final approval/safety policy implementation.
- Rich transcript rendering beyond simple question/approval blocks.
- Replacing AgentCore's existing `WaitingHuman` / `HumanResponse` flow.

## Current v1 scope decision (2026-06-28)

**V1 is a thin live-session `ask_human`.** The model-visible tool returns an interrupt
payload; AgentCore's existing `WaitingHuman`/`HumanResponse` flow pauses the run; the TUI
shows a question overlay; the answer resumes the run. Resume of a pending HITL question
after session restart is **explicitly deferred / unsupported for v1**. If a session is
resumed while a run is still `WaitingHuman`, the TUI will not restore the question overlay.
The run remains paused; the user must cancel or rerun.

Tasks reflecting this decision:
- **QH-07** is already satisfied by existing `TickPollListener::handleHumanInputRequested()` — closed/superseded.
- **QH-08** is deferred — not required for v1.
- **QH-09** depends only on the thin v1 stack (QH-04, slim QH-05, slim QH-06), not on QH-07/QH-08.

## Concept model

### Shared in-memory question request

A UI-facing DTO can live in `src/Tui/Question/` because it is presentation state. If CodingAgent runtime needs to create the DTO from runtime events, use only scalar payloads and runtime DTOs.

```php
enum QuestionSource: string
{
    case Tui = 'tui';
    case AgentCore = 'agent_core';
}

enum QuestionKind: string
{
    case Text = 'text';
    case Confirm = 'confirm';
    case Choice = 'choice';
    case Approval = 'approval';
}

final readonly class QuestionOption
{
    public function __construct(
        public string $label,
        public string $description = '',
    ) {}
}

final readonly class QuestionRequest
{
    /** @param array<string, mixed> $schema @param list<QuestionOption> $choices */
    public function __construct(
        public string $requestId,
        public QuestionSource $source,
        public QuestionKind $kind,
        public string $prompt,
        public array $schema = ['type' => 'string'],
        public array $choices = [],
        public mixed $default = null,
        public ?string $header = null,
        public bool $allowOther = true,
        public bool $secret = false,
        public ?string $runId = null,
        public ?string $questionId = null,
        public ?string $toolCallId = null,
        public ?string $toolName = null,
        public bool $transcript = false,
    ) {}
}
```

Rules:

- `source=tui`: local question, `transcript=false`, answer resolves a local callback/action.
- `source=agent_core`: HITL question, `transcript=true`, answer sends `UserCommand(type: 'answer_human')`.

### Question state

```php
enum QuestionStatus: string
{
    case Pending = 'pending';
    case Answered = 'answered';
    case Rejected = 'rejected';
    case Cancelled = 'cancelled';
}
```

The TUI should display one active question at a time, but the coordinator should own a small queue. Codex queues incoming questions/approvals while an overlay is active; we should copy that behavior rather than dropping later requests. AgentCore HITL should usually be single-active because a run is paused waiting for one answer, but local TUI prompts and future side-thread questions can still benefit from queueing.

## Local TUI question flow

Examples:

- bash foreground command runs >15s: "Move to background?"
- model setting confirmation
- destructive local UI action confirmation

Flow:

```text
TUI/application service asks QuestionCoordinator::ask($request, $onAnswer)
  -> QuestionWidget appears above editor or as overlay
  -> user answers with keys/editor
  -> coordinator invokes local callback
  -> widget disappears
  -> no runtime event, no transcript block, no answer_human command
```

Local questions may be logged to debug logs if needed, but not to `transcript.jsonl`.

## AgentCore HITL flow

Model/tool asks for human input through `ask_human()`:

```text
LLM emits tool call ask_human(prompt, schema, ...)
  -> Symfony toolbox tool returns ['kind' => 'interrupt', ...]
  -> ToolExecutor normalizes ToolResult details
  -> ToolCallResultHandler detects interrupt details
  -> run status becomes WaitingHuman
  -> AgentCore emits waiting_human
  -> RuntimeEventMapper projects human_input.requested / approval.requested
  -> RuntimeEventPoller/TranscriptProjector creates HITL transcript block
  -> TUI QuestionCoordinator displays QuestionWidget from runtime payload
  -> user answers
  -> AgentSessionClient::send(UserCommand(type: 'answer_human', payload: ...))
  -> ApplyCommandHandler applies HumanResponse and run continues
```

Answer command:

```php
$client->send($runId, new UserCommand(
    type: 'answer_human',
    payload: [
        'question_id' => $request->questionId,
        'answer' => $answer,
    ],
));
```

## `ask_human` tool

### Tool name

Use `ask_human`, not `ask_user`, as the model-visible tool name.

`ask_user` may remain as a backwards-compatible internal alias while the codebase migrates, but prompts/docs should teach `ask_human`.

### Tool class

Create `src/CodingAgent/Tool/AskHumanTool.php`.

Register through the Hatfield tool definition/provider convention from TOOLS-R02, execute through the registry-backed Toolbox from TOOLS-R03, not by relying on Symfony `#[AsTool]` metadata:

```php
final readonly class AskHumanTool
{
    /**
     * @param array<string, mixed>|null $schema
     * @param list<string>|null $choices
     *
     * @return array<string, mixed>
     */
    public function __invoke(
        string $prompt,
        ?string $kind = 'text',
        ?array $schema = null,
        ?array $choices = null,
        mixed $default = null,
        ?string $question_id = null,
        ?string $header = null,
        bool $allow_other = true,
        bool $secret = false,
    ): array {
        return [
            'kind' => 'interrupt',
            'question_id' => $question_id ?? /* stable generated id */,
            'header' => $header,
            'prompt' => $prompt,
            'schema' => $schema ?? ['type' => 'string'],
            'ui_kind' => $kind,
            'choices' => $this->normalizeChoices($choices ?? []),
            'default' => $default,
            'allow_other' => $allow_other,
            'secret' => $secret,
        ];
    }
}
```

Choice values should normalize to Codex-style option objects:

```php
[
    ['label' => 'simple', 'description' => 'Fast, minimal change'],
    ['label' => 'robust', 'description' => 'More complete implementation'],
]
```

Bare string choices can be accepted as shorthand and normalized to `['label' => $choice, 'description' => '']`.

Important: the tool should not block waiting for input. It returns an interrupt payload immediately. AgentCore owns pausing/resuming the run. This intentionally differs from Codex's oneshot-blocking `request_user_input` handler.

### Tool result compatibility

The current pipeline detects interrupts when either:

```php
$result->result['details']['kind'] === 'interrupt'
```

or:

```php
$result->result['kind'] === 'interrupt'
```

`ToolExecutor::toDomainResult()` already copies raw toolbox results with `kind=interrupt` into `details`. Therefore a Symfony toolbox `AskHumanTool` returning the interrupt array should work with the existing detection path.

Also update `ToolExecutor` special-case fallback from only `ask_user` to `ask_user|ask_human` or configure the tool policy for `ask_human` as interrupt mode. Preferred v1:

1. implement real `AskHumanTool` plus a Hatfield tool definition/provider for schema/discovery;
2. keep a defensive `ask_human` interrupt fallback in `ToolExecutor` for robustness.

### Suggested schema variants

Text question:

```php
ask_human(
    prompt: 'What should I name the migration?',
    kind: 'text',
    schema: ['type' => 'string']
)
```

Confirmation:

```php
ask_human(
    prompt: 'May I delete these generated files?',
    kind: 'confirm',
    schema: ['type' => 'boolean'],
    default: false
)
```

Choice:

```php
ask_human(
    prompt: 'Which strategy should I use?',
    kind: 'choice',
    schema: ['type' => 'string', 'enum' => ['simple', 'robust']],
    choices: [
        ['label' => 'simple', 'description' => 'Fast, minimal change'],
        ['label' => 'robust', 'description' => 'More complete implementation'],
    ]
)
```

Approval:

```php
ask_human(
    prompt: 'Approve running this destructive command?',
    kind: 'approval',
    schema: ['type' => 'boolean'],
    default: false
)
```

## Runtime events and transcript projection

Runtime projection event:

```php
human_input.requested
```

Payload:

```php
[
    'request_id' => '...',
    'question_id' => '...',
    'kind' => 'text|confirm|choice|approval',
    'header' => '...',
    'prompt' => '...',
    'schema' => ['type' => 'string'],
    'choices' => [
        ['label' => '...', 'description' => '...'],
    ],
    'default' => null,
    'allow_other' => true,
    'secret' => false,
    'tool_call_id' => '...',
    'tool_name' => 'ask_human',
]
```

Optional specialized projection:

```php
approval.requested
```

Only emit this if useful for widget styling/approval policy. It should be derivable from `human_input.requested(kind=approval)`.

Answer projection events:

- `human_input.answered`
- `human_input.rejected`
- `approval.approved`
- `approval.rejected`

Transcript block:

```php
TranscriptBlockKind::Question
TranscriptBlockKind::Approval
```

Block metadata should include `question_id`, `tool_call_id`, `schema`, status, and whether the answer has been submitted. It should not include ANSI-rendered strings.

## TUI components

### QuestionCoordinator

A stateful per-run coordinator owned by the TUI runtime context/screen.

Responsibilities:

- hold the active `QuestionRequest`;
- maintain a small FIFO queue for later questions/approvals;
- register local questions with callbacks;
- receive HITL questions from runtime events;
- route answers based on `QuestionSource`;
- clear active question on answer/cancel and advance to the next queued request;
- expose current question to widgets;
- expose an `actionRequired` flag for footer/status/title surfaces.

### QuestionWidget / ApprovalWidget

Initial rendering can be simple:

```text
? Human input required
  <prompt>
  [type answer below and press Enter]
```

For `confirm|approval`:

```text
? Approval requested
  <prompt>
  y = approve, n = reject
```

For `choice`:

```text
? Choose an option
  1. simple — Fast, minimal change
  2. robust — More complete implementation
```

Use theme tokens for question/approval/status colors. Do not implement full JSON Schema forms in v1.

Codex lesson: keep the overlay/widget small. Do not put queue management, answer normalization, rendering, key handling, and runtime command dispatch all in one large class.

### Input routing

When a HITL question is active:

- normal composer input should be disabled or explicitly rerouted, with status text like Codex's "Answer the questions to continue.";
- editor submit should answer the question instead of sending a new user message;
- `Esc` must not silently dismiss HITL; it should either ask to cancel the run or leave the request pending;
- `y/n` shortcuts can answer `confirm|approval` questions;
- footer/status/title surfaces should indicate action required.

For local TUI questions:

- editor submit or keybinding resolves the local callback;
- no runtime command is sent;
- `Esc` can cancel/dismiss when the local caller provides a cancellation callback.

## Session replay

On resume:

- load runtime events;
- `TranscriptProjector` rebuilds HITL question/approval blocks;
- local TUI questions are not restored.

**V1 note:** Re-showing the active HITL question widget on resume is **deferred/not v1**.
If a session is resumed while a run is still `WaitingHuman`, the TUI will not restore
the question overlay. The run remains paused. The user must cancel or rerun.

`transcript.jsonl` should persist HITL question/approval blocks as projection data, not rendered strings. Local TUI questions do not appear there.

## Implementation order and task graph

The work is split into small tasks for smaller models. Keep each task narrow and avoid large all-in-one TUI classes; Codex's question overlay is a cautionary example.

### Task list

| Task | Title | Depends on | Can run in parallel with | Notes |
|------|-------|------------|--------------------------|-------|
| QH-01 | Question request DTOs and coordinator queue | none | QH-04 | TUI-only in-memory model; no runtime events or transcript writes. |
| QH-02 | Basic QuestionWidget and ApprovalWidget rendering | QH-01 | QH-04, QH-05 | Static rendering and sample blocks only; no input routing. |
| QH-03 | Local TUI question input routing and action-required status | QH-01, QH-02 | QH-04, QH-05 | Local callbacks only; local questions never write transcript/runtime projection. |
| QH-04 | `ask_human` tool and interrupt payload normalization | none | QH-01, QH-02, QH-03 | Thin non-blocking tool; returns interrupt payload immediately. No resume. Main remaining implementation task. |
| QH-05 | AgentCore interrupt compatibility for `ask_human` (slim) | QH-04 | QH-02, QH-03 | Slim scope: ToolCallExtractor payload preservation only. ToolExecutor interrupt-compatibility folded into QH-04. |
| QH-06 | HITL runtime projection payload support (slim) | QH-05, RTVS-01, RTVS-02, RTVS-04, RTVS-05 | QH-03 if not done | Slim scope: richer payload passthrough and transcript assertions only. Existing RuntimeEventMapper already maps waiting_human→human_input.requested. |
| QH-07 | Bind HITL runtime requests to TUI question coordinator | — | — | Already satisfied by existing `TickPollListener::handleHumanInputRequested()`. Superseded/closed. |
| QH-08 | Resume pending HITL question from session replay (DEFERRED — not v1) | — | — | Deferred. Not required for v1. Only explicit unsupported-safe behavior if implemented. |
| QH-09 | Prompt/docs, deterministic tests, and manual smoke (thin v1) | QH-04, QH-05, QH-06 | none | Thin v1 only: docs/prompt guidance for ask_human, minimal deterministic tests, manual smoke. No resume. |

### Dependency waves

1. **TUI local foundation**
   - QH-01 starts first.
   - QH-02 follows QH-01.
   - QH-03 follows QH-01/02 and proves local TUI questions without touching AgentCore.

2. **Tool foundation**
   - QH-04 can start immediately in parallel with QH-01.
   - QH-05 follows QH-04 and verifies `ask_human` produces interrupt details that the current pipeline detects.

3. **Projection integration**
   - QH-06 waits for the relevant runtime transcript backbone tasks: RTVS-01, RTVS-02, RTVS-04, and RTVS-05.
   - QH-06 should not implement local TUI widgets; it only normalizes HITL runtime/projection payloads.

4. **TUI HITL binding (already satisfied)**
   - QH-07 is already satisfied by existing `TickPollListener::handleHumanInputRequested()`. No implementation work needed.

5. **Final validation (thin v1)**
   - QH-08 is **deferred** — not required for v1.
   - QH-09 depends only on the thin v1 stack (QH-04, QH-05, QH-06) and owns docs, prompt guidance, minimal deterministic tests, and smoke notes. No resume tests.

### Parallelization guidance

- Safe parallel tracks:
  - QH-01 and QH-04 can start immediately.
  - QH-02/QH-03 can progress while QH-04/QH-05 progress.
  - QH-06 can be prepared once RTVS contract tasks are ready, but final integration must use actual RTVS event/block names.
- Avoid parallel edits to RuntimeEventPoller, input routing, and session replay code.
- Keep local TUI questions and AgentCore HITL routing separate in code and tests:
  - local question answer -> local callback/action only;
  - HITL answer -> `AgentSessionClient::send(UserCommand(type: 'answer_human', ...))`.
- Only HITL question/approval blocks appear in `transcript.jsonl`; local TUI questions never do.

### Task details

#### QH-01 Question request DTOs and coordinator queue

Scope:

- Add `QuestionRequest`, `QuestionOption`, `QuestionSource`, `QuestionKind`, and `QuestionStatus` under `src/Tui/Question/`.
- Add `QuestionCoordinator` with one active request and a small FIFO queue.
- Support local callbacks and HITL request metadata, but do not send runtime commands yet.
- Expose `actionRequired` / current request read methods for widgets/status.

Acceptance:

- Local request can be enqueued, activated, answered, and cleared.
- Multiple requests are displayed one at a time in FIFO order.
- DTOs do not depend on AgentCore internals.
- Tests cover queueing and source-aware routing decisions without rendering.

#### QH-02 Basic QuestionWidget and ApprovalWidget rendering

Scope:

- Add simple TUI widgets for text, confirm, choice, and approval questions.
- Render `QuestionOption` as `label — description` when description exists.
- Use theme tokens; no rich forms or JSON Schema renderer.
- Add focused rendering tests or snapshots for representative requests.

Acceptance:

- Static question/approval requests render clearly.
- Choice options include descriptions.
- Widgets do not own queueing, answer submission, or runtime command dispatch.

#### QH-03 Local TUI question input routing and action-required status

Scope:

- Route editor submit/keybindings to the active local `QuestionRequest` callback.
- Support `y/n` shortcuts for confirm/approval local prompts.
- Allow `Esc` to cancel local questions when a cancellation callback exists.
- Expose action-required status/footer/title state while any question is active.
- Ensure local questions never append runtime events or transcript blocks.

Acceptance:

- Local TUI question can be answered through input routing.
- Normal prompt submission is blocked/rerouted while a local question is active.
- Local cancellation works when configured.
- Tests prove no runtime command/transcript write happens for local questions.

#### QH-04 `ask_human` tool and interrupt payload normalization

**V1 note:** Thin non-blocking tool — returns interrupt payload immediately; no oneshot/blocking path; no resume responsibility.

Scope:

- Add `src/CodingAgent/Tool/AskHumanTool.php` plus a Hatfield tool definition/provider for `ask_human` using the TOOLS-R02 convention.
- Return `kind=interrupt` payload immediately; do not block waiting for input.
- Support prompt, header, kind, schema, choices, default, allow_other, secret, and optional question_id.
- Normalize bare string choices to label/description objects.
- Generate stable fallback `question_id` when absent.
- Include defensive `ask_human` interrupt fallback in ToolExecutor alongside existing `ask_user`.

Acceptance:

- `ask_human` is discoverable through registry-backed Symfony Toolbox metadata and present in ToolRegistry permanent metadata.
- Tool result contains `kind=interrupt`, `question_id`, `prompt`, `schema`, normalized choices, and UI metadata.
- Unit tests cover text, confirm, choice, approval, and fallback id behavior.

#### QH-05 AgentCore interrupt compatibility for `ask_human` (slim)

Slim scope (v1 decision 2026-06-28):

- Ensure `ToolCallExtractor::interruptPayloadFromToolResult()` preserves header, ui_kind/kind, choices, default, allow_other, and secret where available.
- ToolExecutor interrupt-compatibility is folded into QH-04 (the tool itself returns an interrupt payload; QH-04 adds the defensive `ask_human` fallback in ToolExecutor alongside existing `ask_user`).

Acceptance:

- Interrupt payload preserves UI metadata (header, ui_kind, choices, default, allow_other, secret) needed by runtime/TUI projection.
- No new blocking/oneshot tool execution path is introduced.
- castor deptrac passes.

#### QH-06 HITL runtime projection payload support (slim)

Slim scope (v1 decision 2026-06-28):

- RuntimeEventMapper already maps `waiting_human` → `human_input.requested` — no change needed for basic mapping.
- Richer payload passthrough: ensure `header`, `choices`, `default`, `allow_other`, `secret`, `tool_call_id`, and `tool_name` are passed through in the human_input.requested payload.
- Verify TranscriptProjector creates question/approval transcript blocks only for HITL, not local TUI prompts (add/review assertions).

Acceptance:

- Runtime payload for `human_input.requested` includes the full set of question metadata fields.
- Local TUI question code path cannot create transcript blocks.
- Projector/replay tests cover requested and answered HITL question.
- castor deptrac passes.

#### QH-07 Bind HITL runtime requests to TUI question coordinator (SUPERSEDED)

**Status: Already implemented by existing code. No work needed.**

The following behavior is already in place:

- `TickPollListener::handleHumanInputRequested()` receives `human_input.requested` from `RuntimeEventPoller::poll()`.
- It builds a `QuestionRequest` with `QuestionSource::AgentCore` and `QuestionKind::Choice`, enqueues it in `QuestionCoordinator`.
- Answer callback sends `UserCommand(type: 'answer_human', ...)` via `AgentSessionClient::send()`.
- Cancel callback sends `answer_human` with `'answer' => 'cancel'`.
- Duplicate guard via `$questionCoordinator->hasRequest($requestId)` prevents event-replay double-enqueue.

Everything listed in the original scope and acceptance criteria is satisfied.
This task is closed/superseded.

#### QH-08 Resume pending HITL question from session replay (DEFERRED — not v1)

**Decision (2026-06-28): Deferred. Not required for v1.**

If a session is resumed while a run is still `WaitingHuman`, the TUI will not restore
the question widget. The run remains paused. The user must cancel or rerun.

If this task is picked up later, it should ensure:
- On resume, if the latest run is still `WaitingHuman`, the pending question widget is shown again.
- Answer after resume still sends `answer_human` and continues the run.
- Local TUI questions are never restored.
- Transcript blocks are already rebuilt by `TranscriptProjector` — only the interactive overlay needs restoring.

#### QH-09 Prompt/docs, deterministic tests, and manual smoke (thin v1)

Slim scope (v1 decision 2026-06-28):

- Update tool prompt/docs to teach `ask_human` usage and schema subset.
- Add deterministic tests for the thin v1 `ask_human` flow (tool interrupt, payload shape, TUI overlay rendering, answer_human routing).
- Do **not** add tests for pending-HITL resume — that path is deferred.
- Add manual smoke steps using `castor run:agent` with a model/tool call to `ask_human`.
- Record known limitations, especially no full JSON Schema renderer and no resume support in v1.

Acceptance:

- Docs/prompt guidance explain when to use `ask_human` and note that resume support is deferred.
- Tests cover thin v1 flow: ask_human → interrupt → human_input.requested → TUI overlay → answer_human → run continues.
- Manual smoke verifies ask_human → TUI question → answer_human → run continues.
- Known limitations documented (no full JSON Schema renderer, no resume).

## Validation

Use Castor tasks:

```bash
castor test --filter Question
castor test --filter AskHuman
castor test --filter HumanResponse
castor deptrac
```

Manual smoke:

1. Configure a model with tool calling.
2. Ask it to call `ask_human` before proceeding.
3. Confirm TUI shows a question widget.
4. Answer in the TUI.
5. Confirm the run continues and transcript contains only the HITL question/answer, not unrelated local UI prompts.

## Open questions

1. Should `approval.requested` be a separate runtime event or just `human_input.requested(kind=approval)`?
   - Recommended: use `human_input.requested` as canonical; optionally emit/derive `approval.requested` for renderer convenience.

2. Should `ask_human` allow arbitrary JSON schema or a constrained subset?
   - Recommended v1: accept arbitrary schema but only render `string`, `boolean`, and simple enum choices. Fall back to text input for unknown schema.

3. What should HITL cancellation do?
   - Codex has mixed semantics: generic question overlay Esc interrupts/cancels the turn, while approval overlay Esc maps to a structured cancel/reject decision.
   - Recommended v1: `Esc` does not silently dismiss HITL. For approval/confirm questions it can submit an explicit negative answer if the schema supports it; otherwise it should ask for confirmation to cancel the run or leave the request pending.

4. Should `question_id` be model-supplied or generated?
   - Recommended: allow model/tool-supplied `question_id`, but generate a stable fallback from tool call id when absent.

5. Should local TUI questions use the same widget placement as HITL?
   - Recommended: yes, same widget system, different source/routing/persistence.

6. Should the tool be named `ask_human` or `request_user_input`?
   - Codex uses `request_user_input`, but this project should use `ask_human` as the model-visible name because it matches the existing `answer_human` command and is easier to explain in prompts. Mention Codex's shape only as inspiration, not as naming precedent.
