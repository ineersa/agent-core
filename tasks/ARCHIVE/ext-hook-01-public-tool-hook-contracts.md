# EXT-HOOK-01 Public ExtensionApi tool hook contracts

## Goal
Plan: `.pi/plans/extension-tool-hooks-safeguard-plan.md`

Add the public Extension API v2 contracts for tool interception only. This is the first SafeGuard prerequisite and must preserve the public `Ineersa\Hatfield\ExtensionApi` extraction boundary.

Scope:
- Add `registerToolCallHook()` and `registerToolResultHook()` to `ExtensionApiInterface`.
- Add public API-local hook interfaces, context DTOs, decision DTOs, and decision enums for tool calls/results.
- Keep the API pure: PHP-native types and API-local types only; no Symfony, AgentCore, CodingAgent internals, TUI, settings, runtime, or registry dependencies.
- Preserve source compatibility for existing extensions using `registerTool()` only.

## Acceptance criteria
- `src/CodingAgent/ExtensionApi/` contains tool call/result hook interfaces, context DTOs, decision DTOs, and decision enums matching the plan.
- `ExtensionApiInterface` exposes `registerToolCallHook()` and `registerToolResultHook()` alongside `registerTool()`.
- ExtensionApi classes remain dependency-free and pass the `AppExtensionApi` deptrac boundary.
- Focused contract tests cover DTO factory methods/immutability and interface shape.
- Validation with Castor: `castor deptrac`; `castor test --filter ExtensionApi`.

## Workflow metadata
Status: DONE
Branch: task/ext-hook-01-public-tool-hook-contracts
Worktree: /home/ineersa/projects/agent-core-worktrees/ext-hook-01-public-tool-hook-contracts
Fork run: nrsr9n918pbc
PR URL: https://github.com/ineersa/agent-core/pull/64
PR Status: merged
Started: 2026-05-29T21:05:44.445Z
Completed: 2026-05-29T21:19:08.188Z

## Work log
- Created: 2026-05-29T20:49:34.363Z

## Task workflow update - 2026-05-29T21:05:44.445Z
- Moved TODO → IN-PROGRESS.
- Created branch task/ext-hook-01-public-tool-hook-contracts.
- Created worktree /home/ineersa/projects/agent-core-worktrees/ext-hook-01-public-tool-hook-contracts.
- Copied vendor directory into /home/ineersa/projects/agent-core-worktrees/ext-hook-01-public-tool-hook-contracts.
- Copied .vera index into /home/ineersa/projects/agent-core-worktrees/ext-hook-01-public-tool-hook-contracts.

## Task workflow update - 2026-05-29T21:05:56.491Z
- Recorded fork run: abw7u9pjs2t2
- Summary: Launched implementation fork abw7u9pjs2t2 in /home/ineersa/projects/agent-core-worktrees/ext-hook-01-public-tool-hook-contracts.

## Task workflow update - 2026-05-29T21:08:28.270Z
- Recorded fork run: nrsr9n918pbc
- Previous fork abw7u9pjs2t2 was stopped because it wrote initial files into the integration checkout instead of the task worktree. Rolled back those stray source files from main and relaunched with absolute worktree-only instructions.
- Launched replacement implementation fork nrsr9n918pbc with required root/branch verification and absolute path instructions for /home/ineersa/projects/agent-core-worktrees/ext-hook-01-public-tool-hook-contracts.

## Task workflow update - 2026-05-29T21:11:20.453Z
- Recorded fork run: nrsr9n918pbc
- Validation: castor test --filter ExtensionApi: OK (24 tests, 83 assertions); castor deptrac: OK (0 violations); castor cs-fix --path src/CodingAgent/ExtensionApi/: applied formatting; castor cs-check --path src/CodingAgent/ExtensionApi/: OK; castor cs-check --path src/CodingAgent/Extension/: OK
- Summary: Implementation completed in worktree /home/ineersa/projects/agent-core-worktrees/ext-hook-01-public-tool-hook-contracts. Commit 939c5bcc (EXT-HOOK-01 Add public ExtensionApi tool call/result hook contracts). Added public ExtensionApi tool call/result hook interfaces, context DTOs, decision DTOs/enums; updated ExtensionApiInterface; added minimal no-op bridge methods pending EXT-HOOK-02; extended ExtensionApi contract tests.

## Task workflow update - 2026-05-29T21:15:56.846Z
- Moved IN-PROGRESS → CODE-REVIEW.
- Pushed task/ext-hook-01-public-tool-hook-contracts to origin.
- branch 'task/ext-hook-01-public-tool-hook-contracts' set up to track 'origin/task/ext-hook-01-public-tool-hook-contracts'.
- Created PR: https://github.com/ineersa/agent-core/pull/64
- Validation: castor test --filter ExtensionApi: OK (24 tests, 83 assertions); castor deptrac: OK (0 violations); castor cs-check --path src/CodingAgent/ExtensionApi/: OK; castor cs-check --path src/CodingAgent/Extension/: OK
- Summary: EXT-HOOK-01 is implemented and committed on task branch at 939c5bcc. Adds public ExtensionApi tool call/result hook contracts and minimal bridge stubs pending EXT-HOOK-02.

## Task workflow update - 2026-05-29T21:19:08.188Z
- Moved CODE-REVIEW → DONE.
- Merged task/ext-hook-01-public-tool-hook-contracts into integration checkout.
- Merge made by the 'ort' strategy.
 .../Extension/ExtensionToolRegistryBridge.php      |  22 +++
 .../ExtensionApi/ExtensionApiInterface.php         |  26 ++-
 .../ExtensionApi/ToolCallContextDTO.php            |  34 ++++
 .../ExtensionApi/ToolCallDecisionDTO.php           |  58 ++++++
 .../ExtensionApi/ToolCallDecisionKindEnum.php      |  21 ++
 .../ExtensionApi/ToolCallHookInterface.php         |  23 +++
 .../ExtensionApi/ToolResultContextDTO.php          |  37 ++++
 .../ExtensionApi/ToolResultDecisionDTO.php         |  52 +++++
 .../ExtensionApi/ToolResultDecisionKindEnum.php    |  19 ++
 .../ExtensionApi/ToolResultHookInterface.php       |  20 ++
 .../ExtensionApi/ExtensionApiContractsTest.php     | 212 +++++++++++++++++++++
 11 files changed, 521 insertions(+), 3 deletions(-)
 create mode 100644 src/CodingAgent/ExtensionApi/ToolCallContextDTO.php
 create mode 100644 src/CodingAgent/ExtensionApi/ToolCallDecisionDTO.php
 create mode 100644 src/CodingAgent/ExtensionApi/ToolCallDecisionKindEnum.php
 create mode 100644 src/CodingAgent/ExtensionApi/ToolCallHookInterface.php
 create mode 100644 src/CodingAgent/ExtensionApi/ToolResultContextDTO.php
 create mode 100644 src/CodingAgent/ExtensionApi/ToolResultDecisionDTO.php
 create mode 100644 src/CodingAgent/ExtensionApi/ToolResultDecisionKindEnum.php
 create mode 100644 src/CodingAgent/ExtensionApi/ToolResultHookInterface.php
- Removed worktree /home/ineersa/projects/agent-core-worktrees/ext-hook-01-public-tool-hook-contracts.
- Pulled integration checkout: Merge made by the 'ort' strategy..
- Validation: PR #64 merged on GitHub; castor test --filter ExtensionApi: OK (24 tests, 83 assertions); castor deptrac: OK (0 violations)
- Summary: PR #64 was merged; moving EXT-HOOK-01 to DONE and syncing integration checkout.
