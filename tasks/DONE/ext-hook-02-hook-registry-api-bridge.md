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
Status: DONE
Branch: task/ext-hook-02-hook-registry-api-bridge
Worktree: /home/ineersa/projects/agent-core-worktrees/ext-hook-02-hook-registry-api-bridge
Fork run: zenqm6om5pyd
PR URL: https://github.com/ineersa/agent-core/pull/65
PR Status: merged
Started: 2026-05-29T21:22:56.507Z
Completed: 2026-05-29T21:32:46.090Z

## Work log
- Created: 2026-05-29T20:49:40.311Z

## Task workflow update - 2026-05-29T21:22:56.507Z
- Moved TODO → IN-PROGRESS.
- Created branch task/ext-hook-02-hook-registry-api-bridge.
- Created worktree /home/ineersa/projects/agent-core-worktrees/ext-hook-02-hook-registry-api-bridge.
- Copied vendor directory into /home/ineersa/projects/agent-core-worktrees/ext-hook-02-hook-registry-api-bridge.
- Copied .vera index into /home/ineersa/projects/agent-core-worktrees/ext-hook-02-hook-registry-api-bridge.
- Summary: Starting EXT-HOOK-02 to implement app-internal extension hook registry and bridge storage for tool call/result hooks. Scope is registration/storage only; no dispatch or SafeGuard code.

## Task workflow update - 2026-05-29T21:23:30.904Z
- Recorded fork run: zenqm6om5pyd
- Launched implementation fork zenqm6om5pyd in worktree /home/ineersa/projects/agent-core-worktrees/ext-hook-02-hook-registry-api-bridge with strict root/branch verification and absolute-path-only instructions. Scope: internal hook registry and bridge storage only; no dispatch, AgentCore, SafeGuard, approval, or TUI work.

## Task workflow update - 2026-05-29T21:28:15.466Z
- Recorded fork run: zenqm6om5pyd
- Validation: castor test --filter Extension: OK (78 tests, 193 assertions); castor deptrac: OK (0 violations); castor cs-check --path src/CodingAgent/Extension/ --path tests/CodingAgent/Extension/: OK
- Summary: Implementation completed in worktree /home/ineersa/projects/agent-core-worktrees/ext-hook-02-hook-registry-api-bridge. Fork commit 347ef357 was reviewed; I removed the optional default ExtensionHookRegistry constructor dependency because it was a production fallback added to preserve tests and could hide DI/shared-registry mistakes. Tests now use a test helper to pass an explicit registry. Final amended commit: c7f4889f (EXT-HOOK-02 Add extension hook registry and API bridge wiring). Adds ExtensionHookRegistry, wires it as a service, updates ExtensionToolRegistryBridge to store tool call/result hooks, updates legacy ExtensionApiBridge in-memory hook storage, and extends extension tests.

## Task workflow update - 2026-05-29T21:28:54.406Z
- Moved IN-PROGRESS → CODE-REVIEW.
- Pushed task/ext-hook-02-hook-registry-api-bridge to origin.
- branch 'task/ext-hook-02-hook-registry-api-bridge' set up to track 'origin/task/ext-hook-02-hook-registry-api-bridge'.
- Created PR: https://github.com/ineersa/agent-core/pull/65
- Validation: castor test --filter Extension: OK (78 tests, 193 assertions); castor deptrac: OK (0 violations); castor cs-check --path src/CodingAgent/Extension/ --path tests/CodingAgent/Extension/: OK
- Summary: EXT-HOOK-02 is implemented and committed on task branch at c7f4889f. Adds internal ExtensionHookRegistry storage, wires it into ExtensionToolRegistryBridge, updates legacy ExtensionApiBridge hook collection, and covers order/coexistence tests. Parent review removed the fork's optional default registry constructor fallback so production requires the shared DI registry explicitly.

## Task workflow update - 2026-05-29T21:32:46.090Z
- Moved CODE-REVIEW → DONE.
- Merged task/ext-hook-02-hook-registry-api-bridge into integration checkout.
- Merge made by the 'ort' strategy.
 config/services.yaml                               |   2 +
 src/CodingAgent/Extension/ExtensionApiBridge.php   |  42 +++++
 .../Extension/ExtensionHookRegistry.php            |  61 +++++++
 .../Extension/ExtensionToolRegistryBridge.php      |  11 +-
 .../CodingAgent/Extension/ExtensionManagerTest.php | 159 ++++++++++++++++++-
 .../Extension/ExtensionToolRegistryBridgeTest.php  | 175 +++++++++++++++++++--
 6 files changed, 432 insertions(+), 18 deletions(-)
 create mode 100644 src/CodingAgent/Extension/ExtensionHookRegistry.php
- Removed worktree /home/ineersa/projects/agent-core-worktrees/ext-hook-02-hook-registry-api-bridge.
- Pulled integration checkout: Merge made by the 'ort' strategy..
- Validation: PR #65 merged on GitHub; castor test --filter Extension: OK (78 tests, 193 assertions); castor deptrac: OK (0 violations); castor cs-check --path src/CodingAgent/Extension/ --path tests/CodingAgent/Extension/: OK
- Summary: PR #65 was merged; moving EXT-HOOK-02 to DONE and syncing integration checkout.
