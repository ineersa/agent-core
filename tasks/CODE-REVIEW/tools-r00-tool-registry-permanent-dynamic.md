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
Status: CODE-REVIEW
Branch: task/tools-r00-tool-registry-permanent-dynamic
Worktree: /home/ineersa/projects/agent-core-worktrees/tools-r00-tool-registry-permanent-dynamic
Fork run: v2b9aavby81k
PR URL: https://github.com/ineersa/agent-core/pull/48
PR Status: open
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

## Task workflow update - 2026-05-25T20:58:47.312Z
- Recorded fork run: v2b9aavby81k
- Launched implementation fork v2b9aavby81k in /home/ineersa/projects/agent-core-worktrees/tools-r00-tool-registry-permanent-dynamic.

## Task workflow update - 2026-05-25T21:06:01.470Z
- Moved IN-PROGRESS → CODE-REVIEW.
- Pushed task/tools-r00-tool-registry-permanent-dynamic to origin.
- branch 'task/tools-r00-tool-registry-permanent-dynamic' set up to track 'origin/task/tools-r00-tool-registry-permanent-dynamic'.
- Created PR: https://github.com/ineersa/agent-core/pull/48
- Validation: vendor/bin/phpunit tests/CodingAgent/Tool/ToolRegistryTest.php: OK (19 tests); vendor/bin/phpunit tests/CodingAgent/Tool/CodingAgentToolSetResolverTest.php: OK (4 tests); vendor/bin/phpunit tests/AgentCore/Infrastructure/SymfonyAi/DynamicToolDescriptionProcessorTest.php: OK (9 tests); vendor/bin/phpunit tests/AgentCore/Infrastructure/SymfonyAi/PlatformIntegrationTest.php: OK (3 tests); castor test: OK (838 tests, 0 failures); castor deptrac: OK (0 violations, 330 uncovered, 729 allowed)
- Summary: Implemented TOOLS-R00 in worktree /home/ineersa/projects/agent-core-worktrees/tools-r00-tool-registry-permanent-dynamic and committed ce178c6a on task/tools-r00-tool-registry-permanent-dynamic. Added CodingAgent ToolRegistryInterface/ToolRegistry with permanent and dynamic tool sets, prompt line/guideline dedupe, deterministic ordering, duplicate/collision handling, and active tool names. Added AgentCore ActiveToolSet DTO and ToolSetResolverInterface seam. Wired LlmPlatformAdapter to propagate toolsRef/runId/turnNo into Symfony AI Input options. Updated DynamicToolDescriptionProcessor to resolve toolsRef via optional resolver and reuse existing flat-name filtering. Added CodingAgentToolSetResolver mapping registry snapshots to ActiveToolSet. Updated services and deptrac AppTool layer. Extension API bridge, PHAR, concrete tools, SYSTEM-01 prompt assembly, and ToolExecutor allowlist enforcement remain out of scope.
