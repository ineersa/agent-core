# EXT-HOOK-05 Extension tool approval decision bridge

## Goal
Plan: `.pi/plans/extension-tool-hooks-safeguard-plan.md`
Related task: `TUI-APPROVAL-01 Common approval prompt component`

Add a generic extension/tool-hook approval path. This is not SafeGuard-specific and is not part of the QH/HITL flow: any extension tool-call hook should be able to return an approval-required decision, and the runtime should route it through a local tool-approval control channel.

Depends on: `EXT-HOOK-04`, `TUI-APPROVAL-01`.

Scope:
- Add public/API-local approval request DTOs and a `ToolCallDecisionDTO::requireApproval(...)` style decision if the base hook contracts are already in place.
- Add the runtime/control plumbing needed for approval-required tool calls to wait for a local operator decision and then resume or deny the original tool call.
- Render approval requests with the common TUI approval prompt component, not with QH/HITL widgets/coordinators.
- Resume or deny the original tool call based on the local answer without writing model-visible HITL transcript events.
- Define deterministic noninteractive/controller behavior, defaulting to deny unless preapproved by policy/config.
- Keep the approval UI generic; extension-specific choices and labels should come from the approval request payload.

## Acceptance criteria
- Any extension tool-call hook can request local approval through the public ExtensionApi decision shape.
- The approval prompt uses the common TUI approval component, not QH/HITL widgets/coordinators and not a SafeGuard-owned widget.
- Approval answers resume or deny the original tool call deterministically.
- Local extension approval prompts do not append `ask_human`/HITL transcript events.
- Noninteractive/controller mode never hangs waiting for TUI input.
- Product-level validation is run because this touches runtime/TUI flow: `castor test:tui` or `castor run:agent-test`, plus relevant unit tests.

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
- Created: 2026-05-29T20:59:55.419Z
