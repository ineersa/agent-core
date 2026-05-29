# EXT-HOOK-02 Extension hook registry and API bridge

## Goal
Plan: `.pi/plans/extension-tool-hooks-safeguard-plan.md`

Implement the app-internal storage/registration side for extension tool hooks. This bridges the public Extension API registration methods into CodingAgent runtime state without executing hooks yet.

Depends on: `EXT-HOOK-01`.

Scope:
- Add an internal hook registry under `src/CodingAgent/Extension/` for ordered tool call/result hooks.
- Update the current Extension API bridge (`ExtensionToolRegistryBridge` or renamed equivalent) to implement the new hook registration methods.
- Wire services in `config/services.yaml` without changing existing tool registration behavior.

## Acceptance criteria
- Extensions can register multiple tool call hooks and tool result hooks through `ExtensionApiInterface`.
- Hooks are stored in extension registration order.
- Existing `registerTool()` behavior and extension loading remain unchanged.
- Tests cover hook registration order and coexistence with tool registration.
- Validation with Castor: `castor test --filter Extension`; `castor deptrac`.

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
- Created: 2026-05-29T20:49:40.311Z
