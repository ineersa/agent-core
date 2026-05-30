# QH-02 Question widget rendering and ChatScreen integration

## Goal
Plan: .pi/plans/tui-question-hitl-plan.md

## Design decisions

- **One widget class** (`QuestionWidget`) handles all four `QuestionKind` variants.
  Approval is just a kind variant, not a separate class.
- **Reuse Symfony TUI** — do not reinvent rendering. Use `TuiWidget` (project interface)
  for the inline informational display, and Symfony TUI `SelectListWidget` for
  interactive choice selection. No custom rendering primitives.
- **Dedicated slot** in ChatScreen between `statusPanel` and `editorSep` — question
  prompt + hint appear directly above the editor where the user types.
- **No new theme tokens** — reuse `Warning` (indicator), `Text` (prompt),
  `Muted` (hints), `Accent` (choice labels).

## Scope

### 1. `src/Tui/Question/QuestionWidget.php`

Implements `TuiWidget`. Render-only informational display for all four kinds:

| Kind | Rendering |
|------|----------|
| Text | `? <header>\n  <prompt>\n  [type answer and press Enter]` |
| Confirm | `? <header>\n  <prompt>\n  y = yes, n = no` |
| Choice | `? <header>\n  <prompt>\n  1. label — description` per option |
| Approval | `? <header>\n  <header>\n  y = approve, n = reject` |

- Holds a `?QuestionRequest $request` with `setRequest()` / `getRequest()`.
- Returns `[]` when no active request.
- `?` indicator uses `ThemeColorEnum::Warning`, prompt uses `Text`, hint uses `Muted`,
  choice labels use `Accent`, descriptions use `Muted`.
- `secret=true` changes hint to "answer will be hidden".

### 2. ChatScreen dedicated question slot

Add a `questionWidget` LiveTextWidget slot in `ChatScreen` between
`statusPanelWidget` and `editorSepWidget` in the mount order:

```
statusPanelWidget
questionWidget       ← NEW
editorSepWidget
editor
```

- Producer closure reads from a `QuestionWidget` renderable (like `WorkingStatusWidget` pattern).
- Expose `setQuestionRequest(?QuestionRequest)` and `clearQuestion()` on ChatScreen.
- Wire `QuestionCoordinator::activeRequest()` → ChatScreen on coordinator changes
  (full wiring is QH-03's job; QH-02 just proves the slot exists and renders).

### 3. Deptrac update

Update `TuiQuestion` ruleset from `~` (unrestricted) to explicitly allow
`TuiWidget` and `TuiTheme` — the widget now has real cross-layer deps.

## Exclusions
- No input routing — QH-03 owns submit-listener interception and y/n routing.
- No runtime command dispatch (answer_human) — QH-07 owns that.
- No `SelectListWidget` overlay for choice — QH-03 wires that interactively.
- No queue management — QH-01 owns coordinator behavior.

## Dependencies: QH-01.
## Parallelizable with: QH-04, QH-05.

## Acceptance criteria
- QuestionWidget renders all four kinds correctly with theme tokens.
- Choice options render `label — description` (no trailing `—` when no description).
- `secret=true` produces "answer will be hidden" hint.
- Widget returns `[]` when no request set.
- ChatScreen has a dedicated question slot between statusPanel and editorSep.
- Tests cover all four kinds + edge cases (no request, no description, custom header, secret).
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
- Created: 2026-05-18T00:04:15.305Z
