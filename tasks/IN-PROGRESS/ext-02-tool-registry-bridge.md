# EXT-02 Extension API tool registry bridge

## Goal
Plan: `.pi/plans/extension-api-phar-plan.md`

Depends on EXT-00 and TOOLS-R00.

Implement the `ExtensionApiInterface::registerTool()` bridge into the CodingAgent-owned `ToolRegistry`. This task owns mapping extension-provided `ToolRegistrationDTO` data into permanent registry registrations while preserving registry policy for active tool sets, provider schema exposure, allowlist enforcement, prompt summary/guideline dedupe, and hooks.

EXT-02 can run mostly in parallel with EXT-01 after EXT-00 and TOOLS-R00 land; final integration requires EXT-01, EXT-02, and TOOLS-R00 together.

## Acceptance criteria
- An implementation of `Ineersa\Hatfield\ExtensionApi\ExtensionApiInterface` accepts `ToolRegistrationDTO` and registers the tool with CodingAgent `ToolRegistry`.
- Extension-provided tool metadata maps cleanly to permanent registry metadata: provider schema description, prompt summary/guidelines, and handler.
- Third-party `registerTool()` creates a permanent registry entry; it is callable only when registry activation/policy includes it in the active provider-schema and execution-allowlist snapshot.
- Registered extension tools flow through the same active tool set, provider schema exposure, execution allowlist, before/after hooks, and tool execution context behavior as built-in tools.
- Duplicate names and invalid schemas/handlers produce deterministic errors.
- Validation includes `castor deptrac` and targeted tests using a fake extension/tool registration.

## Workflow metadata
Status: IN-PROGRESS
Branch: task/ext-02-tool-registry-bridge
Worktree: /home/ineersa/projects/agent-core-worktrees/ext-02-tool-registry-bridge
Fork run: krnppsz9uqev
PR URL:
PR Status:
Started: 2026-05-25T22:56:48.576Z
Completed:

## Work log
- Created: 2026-05-22T22:43:22.435Z

## Task workflow update - 2026-05-25T22:56:48.576Z
- Moved TODO → IN-PROGRESS.
- Created branch task/ext-02-tool-registry-bridge.
- Created worktree /home/ineersa/projects/agent-core-worktrees/ext-02-tool-registry-bridge.
- Copied vendor directory into /home/ineersa/projects/agent-core-worktrees/ext-02-tool-registry-bridge.
- Copied .vera index into /home/ineersa/projects/agent-core-worktrees/ext-02-tool-registry-bridge.

## Task workflow update - 2026-05-25T22:57:16.688Z
- Recorded fork run: krnppsz9uqev
- Launched implementation fork krnppsz9uqev in /home/ineersa/projects/agent-core-worktrees/ext-02-tool-registry-bridge.
