# TOOLS-R00 ToolRegistry with permanent and dynamic tool sets

## Goal
Plan source: `.pi/plans/toolbox-design-plan.md` section 6.
Related extension plan: `.pi/plans/extension-api-phar-plan.md`.

This task can land before PHAR packaging and before the public Extension API tasks. It owns the CodingAgent-internal registry abstraction and AgentCore toolset resolution seam. EXT-02 later bridges `ExtensionApiInterface::registerTool()` into this registry; TOOLS-R00 must not depend on extension loader/PHAR packaging code.

Implement the CodingAgent-owned ToolRegistry policy layer above Symfony AI Toolbox. The registry must separate permanent tools, which contribute to the stable system prompt, from dynamic tools, which can be added/removed per request and never appear in the stable system prompt.

Pi scout reference: pi keeps rich coding-agent tool metadata (`promptSnippet`, `promptGuidelines`) separate from the lean runtime tool contract and builds system prompt sections from the active registered metadata.

## Acceptance criteria
- A `ToolRegistryInterface` or equivalently named semantic interface exists under CodingAgent internals, not AgentCore or TUI.
- `registerTool()` registers permanent tools with model-visible name, provider/schema reference or handler reference, small prompt description line, and zero or more prompt guidelines.
- Permanent tools are active by default, appear in provider-schema snapshots unless disabled by policy, and contribute only deduped prompt lines/guidelines to the system prompt snapshots.
- Dynamic tools are stored separately and managed with `addDynamicTool()`, `removeDynamicTool()`, `setDynamicTools()`, and `getDynamicTools()` methods.
- Dynamic tools can be included in active provider-schema and execution-allowlist snapshots for a request, but never appear in system prompt available-tools or guidelines snapshots.
- Registry snapshots expose permanent prompt lines, permanent prompt guidelines, active provider-schema tools, active execution allowlist names, and active tool set metadata with deterministic ordering.
- Duplicate tool names are deterministic: identical permanent re-registration is idempotent; conflicting permanent/dynamic name collisions are rejected or resolved by explicit documented policy.
- AgentCore defines a generic `ToolSetResolverInterface` or equivalently named semantic interface that resolves `toolsRef` plus run/turn context to active toolset data without depending on CodingAgent registry classes.
- The resolved toolset data is represented by a typed DTO/value object and includes provider-visible tool names and execution-allowlist names.
- `LlmPlatformAdapter` propagates `ModelInvocationInput::$toolsRef`, `runId`, and `turnNo` into Symfony AI `Input` options before `DynamicToolDescriptionProcessor::processInput()` runs.
- `DynamicToolDescriptionProcessor` uses `ToolSetResolverInterface` when a tools ref is present, while preserving the current fallback behavior for invocations without a resolver/ref.
- CodingAgent provides the concrete `ToolSetResolverInterface` implementation that maps AgentCore `toolsRef` values to ToolRegistry active snapshots, including current dynamic tools.
- Provider tool schemas and tool execution allowlists are derived from the same resolved active snapshot.
- No dependency is introduced from AgentCore or TUI to CodingAgent ToolRegistry internals.
- No dependency is introduced from TOOLS-R00 registry code to PHAR packaging or extension loader internals; extension integration remains an EXT-02 bridge concern.
- Validation includes `castor deptrac` and targeted tests for permanent registration, dynamic add/remove/set/get, ordering, dedupe, duplicate handling, snapshots, `toolsRef` resolution, provider schema filtering, and active allowlist behavior.

## Workflow metadata
Status: IN-PROGRESS
Branch: task/tools-r00-tool-registry-permanent-dynamic
Worktree: /home/ineersa/projects/agent-core-worktrees/tools-r00-tool-registry-permanent-dynamic
Fork run:
PR URL:
PR Status:
Started: 2026-05-25T20:57:55.181Z
Completed:

## Work log
- Created: 2026-05-25T16:34:24.419Z

## Task workflow update - 2026-05-25T20:57:55.181Z
- Moved TODO → IN-PROGRESS.
- Created branch task/tools-r00-tool-registry-permanent-dynamic.
- Created worktree /home/ineersa/projects/agent-core-worktrees/tools-r00-tool-registry-permanent-dynamic.
- Copied vendor directory into /home/ineersa/projects/agent-core-worktrees/tools-r00-tool-registry-permanent-dynamic.
- Copied .vera index into /home/ineersa/projects/agent-core-worktrees/tools-r00-tool-registry-permanent-dynamic.
