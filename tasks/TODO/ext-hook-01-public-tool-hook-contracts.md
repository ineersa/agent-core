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
Status: TODO
Branch:
Worktree:
Fork run:
PR URL:
PR Status:
Started:
Completed:

## Work log
- Created: 2026-05-29T20:49:34.363Z
