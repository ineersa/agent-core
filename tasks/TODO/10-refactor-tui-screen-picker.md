# 10-refactor-tui-screen-picker: split screen sections and picker overlay

## Goal
Plan: .pi/plans/architecture-refactor-plan.md
Reports: .pi/reports/tui-architecture.md, .pi/reports/tests-architecture.md

Improve TUI composition testability by splitting ChatScreen into composable sections and extracting shared picker overlay lifecycle code.

Scope:
- Extract PickerOverlay from ModelPickerController and FavoritePickerController lifecycle boilerplate.
- Split ChatScreen into per-section objects or a screen model that owns LiveTextWidget production and slot rendering per section.
- Preserve TuiSlotRegistry extension slots, ChatScreen listener-facing public API, mount order, and terminal-resize responsiveness.
- Remove unused empty widget stubs if still unused and safe.

## Acceptance criteria
- Picker controllers share common overlay lifecycle code and keep only selection-specific behavior.
- ChatScreen is reduced to orchestration with section-level units that can be tested without full tmux E2E.
- Startup snapshot and TUI smoke expectations remain stable or are intentionally updated with documented visual changes.
- Run and report Castor validation: focused TUI tests, castor test:tui, and castor check where prerequisites allow, or exact environmental blockers.

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
- Created: 2026-06-03T00:32:16.525Z
