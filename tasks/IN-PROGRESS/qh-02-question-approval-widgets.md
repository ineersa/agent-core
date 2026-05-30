# QH-02 QuestionController — interactive question UI and input routing

## Goal
Plan: .pi/plans/tui-question-hitl-plan.md

## Design decisions

- **No QuestionWidget class** — controller builds Symfony TUI widgets on the fly, like ModelPickerController.
- **QuestionController** (following ModelPickerController pattern) dynamically mounts/unmounts widgets via `tui->add()` / `tui->remove()`. No permanent ChatScreen slot.
- **Reuse Symfony TUI** — `SelectListWidget` for Confirm/Choice/Approval, `TextWidget` banner for Text. No custom rendering.
- **Placement is a parameter** on `open()` — `WidgetPlacementEnum::AboveEditor` or `BelowEditor`. Caller decides.
- **`allowOther` on QuestionRequest** controls whether "type your answer" option appears in SelectListWidget. Tool approval (e.g. approve/block) sets `allowOther=false`; choice questions set `allowOther=true`.
- **"Type your answer" flow**: selecting that option submits the selected value + editor text together. Consumer of the answer decides what to do with it.
- **Submit-listener interception**: when `coordinator->actionRequired()`, submit listener routes editor content to `coordinator->answer()` instead of normal prompt.
- **No new theme tokens** — reuse existing tokens.

## Merged scope (was QH-02 + QH-03)

### 1. `src/Tui/Question/QuestionController.php`

Following ModelPickerController pattern:
- `setRuntimeRefs(Tui $tui, ChatScreen $screen, TuiSessionState $state): void`
- `open(QuestionRequest $request, WidgetPlacementEnum $placement = AboveEditor): void`
- `close(): void`
- `isOpen(): bool`

**Rendering by kind:**

| Kind | UI |
|------|---|
| Text | `TextWidget` banner (prompt + hint). User types in existing editor. Submit listener routes answer. |
| Confirm | `SelectListWidget` with [Yes, No] + optional "Type your answer" if `allowOther=true`. |
| Choice | `SelectListWidget` with `$request->choices` as items + optional "Type your answer" if `allowOther=true`. |
| Approval | `SelectListWidget` with [Approve, Reject] + optional "Type your answer" if `allowOther=true`. |

Interactive widgets: `ContainerWidget` + `TextWidget` (header) + `SelectListWidget`, added to TUI via `tui->add()`, focus via `tui->setFocus()`.

**Select callbacks:**
- `onSelect` → extract selected value + editor text → `coordinator->answer()` → `close()`
- `onCancel` → `coordinator->cancel()` → `close()`

### 2. SubmitListener question interception

In `SubmitListener::register()`, before routing through `SubmissionRouter`:
```php
if ($coordinator->actionRequired()) {
    $text = $screen->extract();
    if ('' !== $text) {
        $coordinator->answer($text);
        return;
    }
}
```
This handles Text kind — user types in editor, presses Enter, routed to coordinator.

For Confirm/Choice/Approval kinds, the SelectListWidget handles selection directly (not through submit listener).

### 3. Action-required status

While `coordinator->actionRequired()`:
- Set footer/status: `screen->setStatus('action', '⚠ Question pending')`
- Clear when resolved.

### 4. Local cancellation

For local (source=Tui) questions, Escape cancels via SelectListWidget's `onCancel`. HITL (source=AgentCore) questions cannot be cancelled via Escape alone.

### 5. Deptrac update

Update `TuiQuestion` ruleset to allow needed layers. `TuiScreen` may need `TuiQuestion` if ChatScreen provides helpers, but ideally the controller uses `Tui` + `ChatScreen` refs without ChatScreen modifications.

## Exclusions
- No HITL `answer_human` routing — QH-07 owns that.
- No `ask_human` tool — QH-04 owns that.
- No transcript/runtime projection writes.
- No ChatScreen modifications for slots — all dynamic via controller.

## Dependencies: QH-01.
## Parallelizable with: QH-04, QH-05.

## Acceptance criteria
- Text question shows banner, answer captured through editor submit.
- Confirm/Choice/Approval questions show SelectListWidget overlay.
- `allowOther=true` adds "Type your answer" option to SelectListWidget.
- Normal prompt submission blocked while question is active.
- Selection routes to coordinator callback.
- Escape cancels local (source=Tui) questions.
- Controller dynamically adds/removes widgets — no ChatScreen layout changes.
- Tests cover all four kinds + allowOther + cancellation.
- castor deptrac passes.

## Workflow metadata
Status: IN-PROGRESS
Branch: task/qh-02-question-approval-widgets
Worktree: /home/ineersa/projects/agent-core-worktrees/qh-02-question-approval-widgets
Fork run: tpnta6q1pnb0
PR URL: https://github.com/ineersa/agent-core/pull/77
PR Status: closed
Started: 2026-05-30T22:26:13.282Z
Completed:

## Work log
- Created: 2026-05-18T00:04:15.305Z
- 2026-05-30: First implementation (QuestionWidget + ChatScreen slot) — closed after review. PR #77 closed.
- 2026-05-30: Redesign — merging QH-02+QH-03 into QuestionController pattern (ModelPickerController).

## Task workflow update - 2026-05-30T22:54:22.894Z
- Moved CODE-REVIEW → IN-PROGRESS.
