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
| `question` or `prompt` | Required user-facing text (either non-empty) |
| `kind` / `ui_kind` | Optional: `text`, `confirm`, `choice`, `approval` |
| `choices` | Required non-empty string list when kind is `choice` |
| `default` | Optional default value |
| `question_id` | Optional stable id; otherwise derived from prompt/kind/choices/header |
| `header` | Optional short header |

Hatfield **derives** the answer schema internally from kind/choices (boolean for
confirm/approval, string enum for choices, otherwise string). Do not send a schema
object as a tool argument.

Typical outcomes:

| Outcome | Meaning |
|---|---|
| Structured answer | Matches the derived schema and resumes the agent turn |
| Free-text escape | UI may offer a plain-answer path when schema entry is impractical |
| Cancel | Explicit cancel of the pending question (not a successful answer) |

## End-to-end flow

1. Model invokes `ask_human` with question/kind/choices (as above).
2. Runtime records a pending human-input request and projects `human_input.requested`
   from AgentCore `waiting_human` (see runtime protocol docs).
3. TUI `QuestionCoordinator` / controller renders the overlay.
4. Human submits `answer_human` (runtime command) or cancels.
5. Runtime resumes the agent with the answer payload attached to the waiting tool call.

Answers are correlated to the exact pending request — not “latest question wins” across unrelated ids.

## Child agents / live view

Child subagents may also request human input. In the parent TUI:

- Open `/agents-live`, select the child, and answer in the child’s live view context.
- Return to the parent with `/agents-main` or `Ctrl+\` when finished.

Parent and child questions must not be answered into the wrong run context.

## Cancellation

Cancel is an explicit signal. After cancel, the model should reformulate or continue without treating the question as answered. Do not invent answers in tool results.

## Related

- Approvals / SafeGuard: [approvals.md](approvals.md)
- Agents live view: [agents.md](agents.md)
- Sessions: [session-storage.md](session-storage.md)
