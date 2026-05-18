# QH-09 Question/HITL docs, deterministic tests, and manual smoke

## Goal
Plan: .pi/plans/tui-question-hitl-plan.md

Scope:
- Update tool prompt/docs to teach ask_human usage and schema subset.
- Add deterministic tests for local question flow and HITL flow.
- Add manual smoke steps using castor run:agent with a model/tool call to ask_human.
- Record known limitations, especially no full JSON Schema renderer in v1.

Exclusions:
- Do not add live-model tests to castor check.
- Do not implement missing production APIs solely for tests.
- Do not broaden to safety policy/approval guard implementation.

Dependencies: QH-07, QH-08.
Parallelizable with: none after dependencies.

## Acceptance criteria
- Docs/prompt guidance explain when to use ask_human.
- Tests cover local question non-persistence and HITL transcript persistence.
- Manual smoke verifies ask_human -> TUI question -> answer_human -> run continues.
- Known limitations are documented.
- Relevant castor test filters and castor deptrac pass.

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
- Created: 2026-05-18T00:05:00.782Z
