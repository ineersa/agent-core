---
builtin: true
description: Ask for text, confirmation or a choice; handle cancellation and child-agent questions safely.
---

# Human input

Use `ask_human` when a genuine user decision or missing information blocks progress.
Inspect observable behavior yourself instead of asking the user to guess it.
Tool-policy approvals are a separate flow: see [approvals](approvals.md).

## Call shapes

```json
{"question":"What should the report be called?"}
```

```json
{"question":"Publish this release?","kind":"confirm"}
```

```json
{"question":"Choose a target environment","choices":["staging","production"]}
```

`question` must be nonempty. Optional `header` adds a short UI heading.
`kind` accepts only `confirm` and cannot be combined with `choices`.
Omit both for free text; a choices list must be nonempty. Do not supply a JSON
Schema, `question_id`, `prompt`, defaults or internal UI fields.

A confirmation returns a boolean; choices/free text return a string.
Cancellation returns **`Cancelled by user`**. Treat it as an abort signal, not
approval, and do not immediately retry the same question or invent an answer.

## Answering in the terminal

The question overlay pauses the waiting tool call. Enter submits the selected
answer; ordinary arrows select choices. If a long prompt shows `Partial prompt`,
Ctrl+Up / Ctrl+Down page through its text without changing selection.

For a child question, open `/agents-live`, select that child and answer there.
Return with `/agents-main` or Ctrl+backslash. Answers belong to the exact run and
request, not whichever question was displayed most recently.

Cancellation, advancing beyond a human wait, or reattaching through resume/reload
can cancel outstanding questions. Late answers do not reopen cancelled requests.
Questions from the same waiting turn remain FIFO until that lifecycle boundary.

Related: [agents](agents.md), [sessions](session-storage.md).
