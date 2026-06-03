# 09-refactor-tui-runtime-state: decompose runtime poller and session state

## Goal
Plan: .pi/plans/architecture-refactor-plan.md
Reports: .pi/reports/tui-architecture.md, .pi/reports/tests-architecture.md

Turn RuntimeEventPoller and TuiSessionState into deeper, testable runtime components with explicit invariants for activity state, sequencing, and footer usage.

Scope:
- Extract activity state transition logic into a pure state machine/service.
- Extract footer usage/turn metrics accumulation into an invariant-bearing object or service.
- Split TuiSessionState into structured sub-objects for run handle, sequencing, footer projection, and turn metrics where practical.
- Preserve RuntimeEventPoller::poll() caller contract for listeners.

## Acceptance criteria
- Activity transitions and usage extraction have focused unit tests independent of full TUI/tmux E2E.
- Per-turn metric reset invariants are enforced by methods/objects rather than comment-only conventions.
- Existing listener behavior and RuntimeEventPoller public caller contract are preserved.
- Run and report Castor validation: new TUI runtime tests, castor test:tui and castor check where prerequisites allow, or exact environmental blockers.

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
- Created: 2026-06-03T00:32:15.420Z
