# QH-03 Local question input routing and action-required status

## Goal
Plan: .pi/plans/tui-question-hitl-plan.md

## Design decisions

- **Reuse existing editor + submit listener** — for Text/Confirm/Approval questions,
  the user types in the existing EditorWidget. The SubmitListener checks
  `QuestionCoordinator::actionRequired()` and routes the editor content as
  the answer instead of sending a normal prompt.
- **Reuse Symfony TUI SelectListWidget** — for Choice questions, mount a
  `SelectListWidget` overlay (following `ModelPickerController` pattern):
  `tui->add(container)`, `tui->setFocus(listWidget)`, wire `onSelect`/`onCancel`,
  `tui->remove(container)` on resolve.
- **No custom widgets for input** — no custom y/n handler, no custom text input.
  y/n for confirm/approval goes through the existing editor submit path
  (user types "y" or "n" and presses Enter; submit listener normalizes).

## Scope

### 1. Submit-listener question interception

In `SubmitListener::register()`, before routing through `SubmissionRouter`:

```php
if ($coordinator->actionRequired()) {
    $text = $screen->extract();
    if ('' !== $text) {
        $coordinator->answer($text);
        $screen->setQuestionRequest($coordinator->activeRequest());
        return; // do not send as normal prompt
    }
}
```

This handles Text, Confirm, and Approval questions — user types answer in
editor, presses Enter, submit listener sees active question → routes to coordinator.

### 2. SelectListWidget overlay for Choice questions

Add a `QuestionChoiceController` (follows `ModelPickerController` pattern):
- Builds a `ContainerWidget` with `TextWidget` (header) + `SelectListWidget` (options)
- Maps `QuestionOption` items to `SelectListWidget` items: `value=label, label=label, description=description`
- `tui->add(container)`, `tui->setFocus(listWidget)` — takes focus from editor
- `onSelect` → extracts selected label → `coordinator->answer(label)` → removes overlay → restores editor focus
- `onCancel` → `coordinator->cancel()` → removes overlay → restores editor focus
- Called by submit listener when active question is `QuestionKind::Choice`

### 3. Action-required status

While `coordinator->actionRequired()`:
- Set a footer/status entry: `screen->setStatus('action', '⚠ Question pending')`
- Clear it when question is resolved
- Update `QuestionWidget` display via `screen->setQuestionRequest()`

### 4. Local cancellation

For local (source=Tui) questions, Escape in editor cancels:
- Add Escape handler in submit listener that calls `coordinator->cancel()`
  when a question is active and `source === QuestionSource::Tui`.
- HITL (source=AgentCore) questions must NOT be cancelled via Escape alone —
  that requires explicit confirmation (deferred to QH-07).

## Exclusions
- No HITL `answer_human` routing — QH-07 owns that.
- No `ask_human` tool.
- No transcript/runtime projection writes.
- No Hitl Escape-to-cancel — only local Tui questions support Escape cancel.

## Dependencies: QH-01, QH-02.
## Parallelizable with: QH-04, QH-05.

## Acceptance criteria
- Local TUI question answered through editor submit → coordinator callback invoked.
- Choice questions use SelectListWidget overlay with arrow-key navigation.
- Normal prompt submission blocked while question is active.
- Local cancellation via Escape works for source=Tui questions.
- Action-required status visible in footer/status while question is active.
- Tests prove no runtime command/transcript write happens for local questions.
- castor deptrac passes.

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
- Created: 2026-05-18T00:04:22.230Z
