# TOOLS-R02 Tool definitions, Hatfield providers, and built-in registrar

## Goal
Plan source: `.pi/plans/toolbox-design-plan.md`.

Introduce Hatfield-owned tool definitions as the primary registration path, replacing Symfony `#[AsTool]` for production built-in tools.

Dependencies:
- Depends on TOOLS-R00 (`ToolRegistryInterface`, permanent/dynamic buckets).
- Should land before TOOLS-R03 (registry-backed Toolbox needs definitions) and before concrete tool tasks (TOOLS-03/04/06/07/08/09, QH-04) so they can use one registration convention.

Scope:
- Create `ToolDefinitionDTO` (readonly): `name`, `description` (provider-schema), `parametersJsonSchema` (explicit JSON schema), `handler` (callable or `ToolHandlerInterface` — see below), `promptLine` (one-line `<available_tools>` text), `promptGuidelines` (string array).
- Create `HatfieldToolProviderInterface` with a single `definition(): ToolDefinitionDTO` method. Tool classes may self-implement this interface (recommended) or a separate provider class can wrap them.
- Pin down the handler contract. Replace `handler: mixed` with an explicit type. Preferred: require `callable` (invokable objects, closures, `[$obj, 'method']`). If more structure is needed, introduce a narrow `ToolHandlerInterface` with `__invoke(ToolCallArguments $args): mixed`. Document the choice in the definition DTO docblock.
- Extend `ToolRegistryInterface` with read-only definition lookup methods needed by downstream adapters:
  - `activeToolDefinitions(): list<ToolDefinitionDTO>` — permanent first, then dynamic, registration order.
  - `toolDefinition(string $name): ?ToolDefinitionDTO` — single lookup by name.
  - These must not expose mutable internals; return copies/snapshots.
- Update `ToolRegistry` implementation to store `ToolDefinitionDTO` internally instead of raw arrays, simplifying the metadata structure.
- Create `BuiltInToolRegistrar` that collects `HatfieldToolProviderInterface` services via tagged autowiring and registers each as a permanent tool in `ToolRegistryInterface` during the `kernel.boot` event (or equivalent Symfony extension point).
- Add a `hatfield.tool_provider` autoconfiguration tag for services implementing `HatfieldToolProviderInterface`.
- Update `config/services.yaml` with the autoconfiguration tag.
- Do NOT wire `ToolboxInterface` yet; that belongs to TOOLS-R03. This task only establishes the definition model, provider contract, registrar, and registry extensions.

Out of scope:
- `RegistryBackedToolbox` (TOOLS-R03).
- Execution allowlist enforcement in `ToolExecutor` (TOOLS-R03).
- `ToolSettings` / settings hydration (TOOLS-R04).
- Concrete tool implementations.
- Public ExtensionApi changes.

## Acceptance criteria
- `ToolDefinitionDTO` is a readonly class with name, description, JSON schema, handler (typed, not `mixed`), promptLine, and promptGuidelines.
- `HatfieldToolProviderInterface` has a single `definition()` method returning `ToolDefinitionDTO`.
- `ToolRegistryInterface` exposes `activeToolDefinitions()` and `toolDefinition($name)` returning definition snapshots.
- `ToolRegistry` stores `ToolDefinitionDTO` objects and the new lookup methods work correctly.
- `BuiltInToolRegistrar` collects tagged providers and registers them as permanent tools during boot.
- A test provider can register a tool, and the definition is retrievable via the registry lookup methods.
- `handler` field has an explicit type (callable or `ToolHandlerInterface`), not `mixed`.
- `castor deptrac` passes.

## Workflow metadata
Status: CODE-REVIEW
Branch: task/tools-r02-tool-definitions-hatfield-providers
Worktree: /home/ineersa/projects/agent-core-worktrees/tools-r02-tool-definitions-hatfield-providers
Fork run: dcyj12i3moqi
PR URL: https://github.com/ineersa/agent-core/pull/57
PR Status: open
Started: 2026-05-26T16:07:55.221Z
Completed:

## Work log
- Created: 2026-05-25T20:00:00.000Z — split from monolithic TOOLS-R02.

## Task workflow update - 2026-05-26T16:07:55.222Z
- Moved TODO → IN-PROGRESS.
- Created branch task/tools-r02-tool-definitions-hatfield-providers.
- Created worktree /home/ineersa/projects/agent-core-worktrees/tools-r02-tool-definitions-hatfield-providers.
- Copied vendor directory into /home/ineersa/projects/agent-core-worktrees/tools-r02-tool-definitions-hatfield-providers.
- Copied .vera index into /home/ineersa/projects/agent-core-worktrees/tools-r02-tool-definitions-hatfield-providers.
- Summary: Started implementation as part of initial tools foundation wave.

## Task workflow update - 2026-05-26T23:05:09.638Z
- Recorded fork run: dcyj12i3moqi
- Summary: Launched implementation fork dcyj12i3moqi. Instructions: merge current origin/main first, implement ToolDefinitionDTO/HatfieldToolProviderInterface/handler contract/registry definition lookups/BuiltInToolRegistrar/autoconfiguration tag, keep Symfony Toolbox wiring for R03, validate with Castor, commit and push branch.

## Task workflow update - 2026-05-26T23:12:04.264Z
- Moved IN-PROGRESS → CODE-REVIEW.
- Pushed task/tools-r02-tool-definitions-hatfield-providers to origin.
- branch 'task/tools-r02-tool-definitions-hatfield-providers' set up to track 'origin/task/tools-r02-tool-definitions-hatfield-providers'.
- Created PR: https://github.com/ineersa/agent-core/pull/57
- Validation: castor cache:clear: ok; castor test --filter="ToolRegistry|ToolDefinition|BuiltInToolRegistrar|ExtensionToolRegistryBridge": ok (44 tests, 97 assertions); castor test: ok (1040 tests, 10136 assertions); castor deptrac: ok (0 violations, 0 errors, uncovered=364, allowed=761); castor cs-check: ok
- Summary: TOOLS-R02 implemented at 84960ddd. Added ToolHandlerInterface, ToolDefinitionDTO, HatfieldToolProviderInterface, BuiltInToolRegistrar, registry definition lookup APIs, and hatfield.tool_provider autoconfiguration. ToolRegistry now stores ToolDefinitionDTO internally while preserving dynamic-tool raw array compatibility. ExtensionToolRegistryBridge validates public ExtensionApi mixed handlers at the boundary and requires ToolHandlerInterface internally. Symfony Toolbox wiring and execution allowlist remain for TOOLS-R03.
