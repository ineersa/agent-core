# EDITOR-06 Editor viewport, growth, and internal scrolling

## Goal
Plan: .pi/plans/editor_rollout_plan.md

MVP: no.

Scope:
- Add EditorViewport or equivalent logic for visible height/width and scroll state.
- Grow editor from one visible line up to maxVisibleLines = min(20, max(3, floor(rows * 0.30))).
- Keep cursor visible by adjusting scrollOffset.
- Support PageUp/PageDown while editor is focused.
- Render top/bottom scroll indicators such as "↑ 3 more" and "↓ 2 more".

Exclusions:
- No transcript/history scrolling outside editor focus.
- No prompt history; EDITOR-07 owns history.
- No completion/paste behavior.

Dependencies: EDITOR-01, EDITOR-02.
Parallelizable with: EDITOR-03, EDITOR-04, and later EDITOR-08.

## Acceptance criteria
- Editor grows up to the configured max visible line count.
- Long multiline input keeps cursor visible through scrollOffset updates.
- PageUp/PageDown scroll the focused editor viewport.
- Scroll indicators are rendered when hidden editor content exists.
- Unit tests cover viewport/growth/scroll math; tmux e2e added or documented if rendering changes materially.
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
- Created: 2026-05-18T00:15:43.021Z
