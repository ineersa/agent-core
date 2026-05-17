t's # TUI questions and ask_human HITL tool plan

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

final readonly class QuestionRequest
{
    /** @param array<string, mixed> $schema @param list<array<string, mixed>|string> $choices */
    public function __construct(
        public string $requestId,
        public QuestionSource $source,
        public QuestionKind $kind,
        public string $prompt,
        public array $schema = ['type' => 'string'],
        public array $choices = [],
        public mixed $default = null,
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

The TUI needs one active question at a time for v1. A later queue can support multiple local prompts, but AgentCore HITL should usually be single-active because a run is paused waiting for one answer.

## Local TUI question flow

Examples:

- bash foreground command runs >30s: "Move to background?"
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

Register with Symfony AI toolbox:

```php
#[AsTool('ask_human', description: 'Ask the human user for input or approval before continuing')]
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
    ): array {
        return [
            'kind' => 'interrupt',
            'question_id' => $question_id ?? /* stable generated id */,
            'prompt' => $prompt,
            'schema' => $schema ?? ['type' => 'string'],
            'ui_kind' => $kind,
            'choices' => $choices ?? [],
            'default' => $default,
        ];
    }
}
```

Important: the tool should not block waiting for input. It returns an interrupt payload immediately. AgentCore owns pausing/resuming the run.

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

1. implement real `AskHumanTool` for schema/discovery;
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
    choices: ['simple', 'robust']
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
    'prompt' => '...',
    'schema' => ['type' => 'string'],
    'choices' => [],
    'default' => null,
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
- register local questions with callbacks;
- receive HITL questions from runtime events;
- route answers based on `QuestionSource`;
- clear active question on answer/cancel;
- expose current question to widgets.

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
  1. simple
  2. robust
```

Use theme tokens for question/approval/status colors. Do not implement full JSON Schema forms in v1.

### Input routing

When a HITL question is active:

- editor submit should answer the question instead of sending a new user message;
- `Esc` may cancel local TUI questions;
- for HITL, cancellation should probably send run cancellation or leave unanswered, not silently discard the request;
- `y/n` shortcuts can answer `confirm|approval` questions.

For local TUI questions:

- editor submit or keybinding resolves the local callback;
- no runtime command is sent.

## Session replay

On resume:

- load runtime events;
- `TranscriptProjector` rebuilds HITL question/approval blocks;
- if the latest run state is still `WaitingHuman`, show the active HITL `QuestionWidget` again;
- local TUI questions are not restored.

`transcript.jsonl` should persist HITL question/approval blocks as projection data, not rendered strings. Local TUI questions do not appear there.

## Implementation phases

### Q-01 Local TUI question backbone

Scope:

- Add `QuestionRequest`, `QuestionSource`, `QuestionKind`, `QuestionStatus`.
- Add `QuestionCoordinator` for one active local/HITL question.
- Add basic `QuestionWidget` and `ApprovalWidget` rendering.
- Add TUI input routing for local questions.
- Add tests for local callback routing and rendering.

Acceptance:

- Local question can be shown, answered, and cleared.
- Local question does not append runtime events or transcript entries.
- TUI boundaries remain clean.

### Q-02 ask_human tool and interrupt compatibility

Scope:

- Add `src/CodingAgent/Tool/AskHumanTool.php` with `#[AsTool('ask_human', ...)]`.
- Return interrupt payload, do not block.
- Update docs/prompt guidance to teach `ask_human`.
- Add/keep `ToolExecutor` defensive fallback for `ask_human` if needed.
- Add tests that executing the tool produces interrupt details detected by `ToolCallExtractor`.

Acceptance:

- `ask_human` is discoverable in Symfony AI toolbox metadata.
- Tool result contains `kind=interrupt`, `question_id`, `prompt`, and `schema`.
- AgentCore transitions to `WaitingHuman` when the tool result is committed.

### Q-03 HITL runtime projection and transcript blocks

Scope:

- Map AgentCore `waiting_human` to `human_input.requested`.
- Add transcript block kind(s) for HITL question/approval.
- Project accepted/rejected human responses into block status updates.
- Persist HITL blocks in `transcript.jsonl`.
- Rebuild HITL blocks on resume.

Acceptance:

- HITL question appears in transcript projection.
- Local TUI questions do not appear in transcript projection.
- Replay from runtime events reconstructs the same HITL block.

### Q-04 Bind HITL to TUI question widget

Scope:

- Runtime event poller/coordinator detects active HITL request.
- Show QuestionWidget/ApprovalWidget for `human_input.requested`.
- Submit answer through `AgentSessionClient::send(... answer_human ...)`.
- Clear/update widget on `human_input.answered`, run cancellation, or rejection.

Acceptance:

- A model/tool call to `ask_human` pauses the run and shows a TUI question.
- User answer resumes the run.
- Resume while waiting shows the pending HITL question again.
- TUI does not import AgentCore internals.

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
   - Recommended v1: `Esc` does not silently dismiss HITL. It either asks for confirmation to cancel the run or leaves the question pending.

4. Should `question_id` be model-supplied or generated?
   - Recommended: allow model/tool-supplied `question_id`, but generate a stable fallback from tool call id when absent.

5. Should local TUI questions use the same widget placement as HITL?
   - Recommended: yes, same widget system, different source/routing/persistence.
