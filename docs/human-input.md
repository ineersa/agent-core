---
builtin: true
description: Model-facing ask_human questions, answers, cancellation, and child-agent routing.
---

# Human Input

Human input (Path B) is the model-driven question flow: the LLM calls `ask_human`,
the runtime pauses in a waiting-human state, the TUI renders a schema-driven overlay,
and the answer is applied without inventing tool results.

Tool-approval suspensions (SafeGuard / `RequireApproval`) are a separate path —
see [approvals.md](approvals.md).

## Model-facing tool: `ask_human`

The model supplies structured arguments — **not** raw JSON Schema:

| Argument | Meaning |
|---|---|
| `question` | **Required** non-empty user-facing text |
| `kind` | Optional; sole accepted value is `confirm` (yes/no or approval) |
| `choices` | Optional non-empty string list for selection mode |
| `header` | Optional short UI header |

There is **no** model input or compatibility alias for `prompt`, `ui_kind`, `default`,
or `question_id`. Those names may appear only in internal interrupt payloads or TUI
plumbing, never as tool arguments.

### Modes (mutually exclusive)

| Mode | Call shape | Derived answer schema | Output `ui_kind` |
|---|---|---|---|
| Free-form | `question` only (omit `kind` and `choices`) | `{"type":"string"}` | `text` |
| Confirm | `kind=confirm` without `choices` | `{"type":"boolean"}` | `confirm` |
| Selection | non-empty `choices`, omit `kind` | `{"type":"string","enum":[...]}` | `choice` |

`kind=confirm` together with any provided `choices` is rejected. Empty `choices` is
also rejected. Hatfield always derives the answer schema and output `ui_kind`
internally — do not send a schema object as a tool argument.

`question_id` is generated from `question` / `kind` / `choices` / `header` for
internal correlation only; the model does not supply it.

Typical outcomes:

| Outcome | Meaning |
|---|---|
| Structured answer | Matches the derived schema and resumes the agent turn |
| Free-text escape | UI may offer a plain-answer path when schema entry is impractical |
| Cancel | Answer is the string `'Cancelled by user'` — treat as an abort signal, not a successful answer; do not immediately retry the same question |

## End-to-end flow

1. Model invokes `ask_human` with one exclusive mode above (`question` required).
2. Runtime records a pending human-input request and projects the runtime event
   `human_input.requested` from AgentCore `waiting_human`.
3. TUI `QuestionCoordinator` / controller renders the overlay.
4. Human submits the runtime command `answer_human` or cancels.
5. Runtime resumes the agent with the answer payload attached to the waiting tool call.

Answers are correlated to the exact pending request — not “latest question wins” across unrelated ids.

## Child agents / live view

Child subagents may also request human input. In the parent TUI:

- Open `/agents-live`, select the child, and answer in the child’s live view context.
- Return to the parent with `/agents-main` or `Ctrl+\` when finished.

Parent and child questions must not be answered into the wrong run context.

Each live-view entry reconstructs the child transcript from that child's
`events.jsonl`. Outside agents-live, the parent TUI does not retain child
transcript or event archives. Unresolved canonical `human_input.requested`
events are rediscovered from the snapshot on reopening; local bash-background
`tool_question.requested` prompts are restored from `ToolQuestionStore`.

## Cancellation

Cancel returns the answer string `'Cancelled by user'`. Treat it as an abort signal:
reformulate or continue without treating the question as answered, and do not
immediately retry the same question. Do not invent answers in tool results.

## Related

- Approvals / SafeGuard: [approvals.md](approvals.md)
- Agents live view: [agents.md](agents.md)
- Sessions: [session-storage.md](session-storage.md)
