# AI-14 TUI model controls and favorites

## Goal
Plan reference: .pi/plans/symfony_ai_platform_integration_plan.md#ai-14--tui-model-controls-and-favorites

Goal: add user controls for switching models/reasoning from the TUI.

Depends on: AI-07, AI-08, AI-13.

Parallelism: final UI task after selection, runtime input, and footer projection exist.

Scope:
- `/model` overlay/list: favorites first, all configured provider models after favorites, scrollable, `Ctrl+F` toggles favorite, `Enter` selects.
- `Ctrl+P` cycles favorite models.
- `Shift+Tab` cycles reasoning levels: `off -> minimal -> low -> medium -> high -> xhigh -> off`.
- Persist model/reasoning changes through selection services.
- Emit/update runtime events so footer changes immediately.

## Acceptance criteria
- User can select a model before the next turn.
- Favorite cycling only cycles configured/favorited models.
- Reasoning cycle updates session/home defaults and footer state.
- Existing TUI snapshots are updated only if rendering intentionally changes.
- Suggested validation: `castor test --filter Tui`; `castor test:tui`.

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
- Created: 2026-05-16T22:02:34.212Z
