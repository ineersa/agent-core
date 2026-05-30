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
Status: CODE-REVIEW
Branch: task/qh-02-question-approval-widgets
Worktree: /home/ineersa/projects/agent-core-worktrees/qh-02-question-approval-widgets
Fork run: 2vgd139wwmnp
PR URL: https://github.com/ineersa/agent-core/pull/78
PR Status: open
Started: 2026-05-30T22:26:13.282Z
Completed:

## Work log
- Created: 2026-05-18T00:04:15.305Z
- 2026-05-30: First implementation (QuestionWidget + ChatScreen slot) — closed after review. PR #77 closed.
- 2026-05-30: Redesign — merging QH-02+QH-03 into QuestionController pattern (ModelPickerController).

## Task workflow update - 2026-05-30T22:54:22.894Z
- Moved CODE-REVIEW → IN-PROGRESS.

## Task workflow update - 2026-05-30T23:00:43.788Z
- Recorded fork run: i62t71teprw1
- Validation: castor cs-check → ok; castor test --filter=Question → 43 tests, 153 assertions, 0 failures; castor deptrac → 0 violations
- Summary: Rewrote QH-02 (merged with QH-03): QuestionController following ModelPickerController pattern. SelectListWidget for Confirm/Choice/Approval with allowOther "Type your answer" option. TextWidget banner for Text kind. SubmitListener intercepts editor submit when question active. Dynamic tui->add()/remove(), no ChatScreen modifications. 43 tests/153 assertions, 0 deptrac violations.

## Task workflow update - 2026-05-30T23:02:16.887Z
- Moved IN-PROGRESS → CODE-REVIEW.
- Pushed task/qh-02-question-approval-widgets to origin.
- branch 'task/qh-02-question-approval-widgets' set up to track 'origin/task/qh-02-question-approval-widgets'.
- Created PR: https://github.com/ineersa/agent-core/pull/78

## Task workflow update - 2026-05-30T23:22:30.276Z
- Recorded fork run: 2vgd139wwmnp
- Validation: castor cs-fix → ok; castor cs-check → ok; castor test --filter=Question → 43 tests, 153 assertions, 0 failures; castor deptrac → 0 violations
- Summary: Addressed all 4 PR #78 review comments: (1) replaced 3 nullable props with TuiRuntimeContext + constructor-injected QuestionCoordinator, (2) removed local variable aliases, (3) replaced static function + $onSelectController captures with non-static closures, (4) split 96-line open() into addHeader/addTextBanner/addSelectList/mount. Bonus fix: SubmitListener::register() now actually calls setRuntimeRefs() — previously open() silently no-op'd.
