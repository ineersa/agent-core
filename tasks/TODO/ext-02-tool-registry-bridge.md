# EXT-02 Extension API tool registry bridge

## Goal
Plan: `.pi/plans/extension-api-phar-plan.md`

Depends on EXT-00.

Implement the `ExtensionApiInterface::registerTool()` bridge into the CodingAgent-owned `ToolRegistry`. This task owns mapping extension-provided `ToolRegistrationDTO` data into registry registrations while preserving registry policy for scope, active tool sets, provider schema exposure, allowlist enforcement, and hooks.

EXT-02 can run mostly in parallel with EXT-01 after EXT-00 lands; final integration requires both tasks together.

## Acceptance criteria
- An implementation of `Ineersa\Hatfield\ExtensionApi\ExtensionApiInterface` accepts `ToolRegistrationDTO` and registers the tool with CodingAgent `ToolRegistry`.
- Extension-provided tool metadata maps cleanly to registry metadata: schema description, prompt summary/guidelines, scope, and handler.
- Third-party registration means available to the registry, not automatically callable by the model unless registry activation/policy allows it.
- Registered extension tools flow through the same active tool set, provider schema exposure, execution allowlist, before/after hooks, and tool execution context behavior as built-in tools.
- Duplicate names and invalid schemas/handlers produce deterministic errors.
- Validation includes `castor deptrac` and targeted tests using a fake extension/tool registration.

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
- Created: 2026-05-22T22:43:22.435Z
