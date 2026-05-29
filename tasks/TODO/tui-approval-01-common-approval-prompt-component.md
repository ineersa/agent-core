# TUI-APPROVAL-01 Common approval prompt component

## Goal
Plan: `.pi/plans/extension-tool-hooks-safeguard-plan.md`

Create a generic TUI approval prompt element that can be reused by extension/tool approvals and any future question/HITL UI, without coupling it to the QH task flow or SafeGuard.

Scope:
- Add a common approval prompt widget/component with title, message, risk/details fields, and configurable choices.
- Keep it generic: no SafeGuard-specific policy semantics and no `ask_human`/HITL transcript behavior.
- Provide focused rendering tests/snapshots for representative approval prompts.
- Do not implement runtime queueing, cross-process approval delivery, or tool-call resume semantics; the extension approval bridge owns that.

## Acceptance criteria
- A generic TUI approval prompt component exists and renders configurable choices clearly.
- The component is reusable outside SafeGuard and outside the QH/HITL flow.
- The component does not write transcript/runtime events and does not dispatch answers by itself.
- Rendering tests or snapshots cover normal approval, risk/details text, and multiple choices.
- Validation with Castor: `castor test --filter Approval`; `castor deptrac`.

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
- Created: 2026-05-29T21:01:47.960Z
