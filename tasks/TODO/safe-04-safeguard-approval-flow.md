# SAFE-04 SafeGuard policy actions over generic extension approvals

## Goal
Plan: `.pi/plans/extension-tool-hooks-safeguard-plan.md`
Related task: `EXT-HOOK-05 Extension tool approval decision bridge`

Add SafeGuard-specific policy actions on top of the generic extension/tool approval bridge. This task must not create a SafeGuard-owned TUI widget and must not depend on the QH/HITL flow; it only supplies SafeGuard-specific approval request content and policy actions.

Depends on: `EXT-HOOK-05`, `SAFE-02`.

Scope:
- Teach SafeGuard to use the generic extension approval decision for eligible policy-relaxable classifications.
- Supply SafeGuard-specific approval labels/details: Block, Allow once, Always allow.
- Persist Always allow choices to SafeGuard policy using Hatfield policy locations.
- Keep hard blocks such as `sudo` non-approvable.

## Acceptance criteria
- SafeGuard uses the generic extension approval bridge rather than a SafeGuard-owned TUI element or any QH/HITL coordinator.
- SafeGuard approval requests include enough generic payload context for the common approval widget: tool name, operation summary, path/command, risk category, and choices.
- Allow once resumes the original tool call without persisting policy.
- Always allow persists the appropriate SafeGuard policy relaxation and resumes the original tool call.
- Block returns a structured denied tool result.
- Hard-blocked categories remain denied without prompting.
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
- Created: 2026-05-29T20:50:28.944Z
