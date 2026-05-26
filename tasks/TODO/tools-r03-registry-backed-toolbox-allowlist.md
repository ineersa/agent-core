# TOOLS-R03 Registry-backed Toolbox and execution allowlist

## Goal
Plan source: `.pi/plans/toolbox-design-plan.md`.

Wire a registry-backed Symfony `ToolboxInterface` adapter and enforce execution allowlists, making all registered tools (built-in, extension, dynamic) actually callable through the Symfony AI execution pipeline.

Dependencies:
- Depends on TOOLS-R00 (`ToolRegistryInterface`, `ToolSetResolverInterface`, `ActiveToolSet`).
- Depends on TOOLS-R02 (`ToolDefinitionDTO`, `HatfieldToolProviderInterface`, `activeToolDefinitions()`, `toolDefinition()`, typed handler).
- Should land before or alongside concrete tool tasks that need callable execution.

Scope:
- Create `RegistryBackedToolbox implements Symfony\AI\Agent\Toolbox\ToolboxInterface`:
  - `getTools()`: reads registry definitions via `activeToolDefinitions()`, converts each `ToolDefinitionDTO` to a Symfony `Tool` DTO with an appropriate `ExecutionReference` and JSON schema. Per-turn filtering is already handled by `DynamicToolDescriptionProcessor` via `ToolSetResolverInterface`.
  - `execute(ToolCall)`: looks up the tool's definition from the registry, invokes the stored handler with the tool call arguments, and wraps the result. Must handle the typed handler contract from TOOLS-R02.
- Wire `Symfony\AI\Agent\Toolbox\ToolboxInterface` to `RegistryBackedToolbox` in `config/services.yaml`, replacing the current null-wired `ToolboxInterface`.
- Extension and dynamic tools must be callable through the same execution path as built-in permanent tools.
- Enforce execution allowlists in `ToolExecutor`:
  - After `ToolSetResolverInterface::resolve()` produces an `ActiveToolSet`, check the incoming tool call name against `allowListNames`.
  - Reject tools outside the allowlist with a structured denied result (`isError: true`, `details: ['denied' => true, 'reason' => 'not_in_active_allowlist']`).
  - Propagate `toolsRef` from `ExecuteLlmStep`/`LlmStepResult` into `ExecuteToolCall`/`ToolCall` context so execution checks the same turn snapshot. If a message shape change is needed, add migration-safe tests.
- Ensure `ToolExecutor` no longer reports toolbox integration as unavailable when the registry-backed Toolbox is wired.
- Registry-only tools (definition present but no execution handler) must not appear in provider schemas. If a definition has a null/missing handler, `RegistryBackedToolbox::getTools()` should skip it or throw during registration.

Out of scope:
- `ToolSettings` / settings hydration (TOOLS-R04).
- Concrete tool implementations.
- Persistent per-turn toolset store.

## Acceptance criteria
- `ToolboxInterface` is wired to `RegistryBackedToolbox` and `ToolExecutor` no longer reports toolbox unavailable.
- `RegistryBackedToolbox::getTools()` converts registry definitions to Symfony `Tool` DTOs.
- `RegistryBackedToolbox::execute()` invokes the stored handler for permanent, dynamic, and extension-registered tools.
- `ToolExecutor` denies execution for tools outside the resolved active allowlist with a structured denied result.
- `toolsRef` propagates from LLM step into tool execution context for allowlist lookup.
- Registry-only tools without handlers do not appear in provider schemas.
- Focused tests cover: definition-to-Tool conversion, handler invocation for all three registration sources, allowlist denial, toolsRef propagation.
- `castor deptrac` passes.

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
- Created: 2026-05-25T20:00:00.000Z — split from monolithic TOOLS-R02.
