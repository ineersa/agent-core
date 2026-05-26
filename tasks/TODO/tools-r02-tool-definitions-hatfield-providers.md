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
