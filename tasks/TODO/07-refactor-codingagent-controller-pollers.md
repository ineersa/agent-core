# 07-refactor-codingagent-controller-pollers: extract headless controller pollers

## Goal
Plan: .pi/plans/architecture-refactor-plan.md
Reports: .pi/reports/coding-agent-architecture.md, .pi/reports/tests-architecture.md

Shrink HeadlessController by extracting self-contained event-drain and stdout-stream polling services. Preserve controller-mode event ordering, partial-line JSONL buffering, transcript persistence, and process supervision behavior.

Scope:
- Extract EventDrainPoller for canonical runtime events: cursoring, mapping, stdout emit, transcript persistence feed.
- Extract StdoutStreamPoller/JsonlLineBuffer for transient LLM stdout deltas and partial-line/error handling.
- Move orphan-consumer cleanup or process supervision details toward ConsumerSupervisor if safe.
- Add fast unit tests for pollers while keeping controller E2E as smoke coverage.

## Acceptance criteria
- HeadlessController is reduced to lifecycle/wiring responsibilities with poller services handling drain/stdout work.
- Partial JSONL buffering and canonical-event cursoring have focused unit tests.
- ControllerSmokeTest and related controller E2E behavior remain unchanged.
- Run and report Castor validation: poller tests, castor test:controller, and castor check, or exact environmental blockers.

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
- Created: 2026-06-03T00:32:14.231Z
