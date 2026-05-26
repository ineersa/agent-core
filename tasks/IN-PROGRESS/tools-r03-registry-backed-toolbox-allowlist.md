# TOOLS-R03 Registry-backed Toolbox and execution allowlist

## Goal
Plan source: `.pi/plans/toolbox-design-plan.md`.

Wire a registry-backed Symfony `ToolboxInterface` adapter and enforce execution allowlists, making all registered tools (built-in, extension, dynamic) actually callable through the Symfony AI execution pipeline.

Dependencies:
- Depends on TOOLS-R00 (`ToolRegistryInterface`, `ToolSetResolverInterface`, `ActiveToolSet`).
- Depends on TOOLS-R02 (`ToolDefinitionDTO`, `HatfieldToolProviderInterface`, `activeToolDefinitions()`, `toolDefinition()`, typed handler).
- Depends on TOOLS-00 for the final minimal tool execution context (`ToolContext`, `StackToolExecutionContextAccessor`) and settings-backed ToolExecutor baseline.
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
- Start tool authoring documentation (for example `docs/tool-execution.md`) that explains the runtime contract for tool handlers:
  - Handlers run synchronously inside a Messenger `tool` worker through `RegistryBackedToolbox::execute()`.
  - Tools that need run/tool metadata, timeout, or cancellation inject `StackToolExecutionContextAccessor` and call `requireCurrent()`.
  - Long-running process tools own their foreground `Symfony\Component\Process\Process` locally: use `start()`, poll with a small sleep/backoff, check `requireCurrent()->cancellationToken()->isCancellationRequested()`, check a monotonic timeout deadline, then call `Process::stop($graceSeconds)` and collect stdout/stderr. Do not use `run()`/`mustRun()` for cancellable long-running commands.
  - Do not pass `SIGTERM` as the second argument to `Process::stop()` unless intentionally replacing Symfony's default final `SIGKILL`; the normal reliable pattern is `stop($graceSeconds)`.
  - No central foreground PID registry/process runner exists after TOOLS-00; background tools will own durable background process tracking separately.
  - Cancellation should return a normal structured tool result/status from the concrete tool; do not reintroduce `ToolCancelledException` or a generic cancellation guard until a real tool proves it is needed.
  - Large text output should flow through `OutputCap` before returning to the model.

Out of scope:
- Additional `ToolSettings` / settings hydration beyond consuming the TOOLS-00/TOOLS-02 typed config that already exists (remaining settings cleanup belongs to TOOLS-R04).
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
- Tool execution documentation describes the concrete process polling/cancellation pattern for future `bash`/patch/read-like tools and explicitly avoids a shared foreground process registry/runner.
- `castor deptrac` passes.

## Workflow metadata
Status: IN-PROGRESS
Branch: task/tools-r03-registry-backed-toolbox-allowlist
Worktree: /home/ineersa/projects/agent-core-worktrees/tools-r03-registry-backed-toolbox-allowlist
Fork run: vt4v5mwcyrdj
PR URL:
PR Status:
Started: 2026-05-26T23:20:20.053Z
Completed:

## Work log
- Created: 2026-05-25T20:00:00.000Z — split from monolithic TOOLS-R02.

## Task workflow update - 2026-05-26T23:03:15.280Z
- Summary: Updated scope after TOOLS-00 merge: R03 now depends on the final minimal ToolContext/StackToolExecutionContextAccessor baseline and owns initial tool authoring docs for synchronous tool-handler execution, local Symfony Process start+poll loops, cancellation-token checks, monotonic timeout checks, Process::stop($graceSeconds), OutputCap use, and explicitly no shared foreground process registry/runner.

## Task workflow update - 2026-05-26T23:20:20.053Z
- Moved TODO → IN-PROGRESS.
- Created branch task/tools-r03-registry-backed-toolbox-allowlist.
- Created worktree /home/ineersa/projects/agent-core-worktrees/tools-r03-registry-backed-toolbox-allowlist.
- Copied vendor directory into /home/ineersa/projects/agent-core-worktrees/tools-r03-registry-backed-toolbox-allowlist.
- Copied .vera index into /home/ineersa/projects/agent-core-worktrees/tools-r03-registry-backed-toolbox-allowlist.
- Summary: Starting after TOOLS-R02 merged. Dependencies available: TOOLS-R00 ActiveToolSet/ToolSetResolverInterface, TOOLS-00 ToolContext/StackToolExecutionContextAccessor/settings-backed ToolExecutor, TOOLS-R02 ToolDefinitionDTO/HatfieldToolProviderInterface/ToolHandlerInterface. Scope: registry-backed Symfony Toolbox, execution allowlist enforcement, and process/polling tool authoring docs.

## Task workflow update - 2026-05-26T23:20:42.832Z
- Recorded fork run: vt4v5mwcyrdj
- Summary: Launched implementation fork vt4v5mwcyrdj in worktree /home/ineersa/projects/agent-core-worktrees/tools-r03-registry-backed-toolbox-allowlist. Instructions cover RegistryBackedToolbox, ToolboxInterface wiring, execution allowlist enforcement, toolsRef propagation, handler invocation for permanent/dynamic/extension tools, tool execution docs, focused tests, deptrac, cs-check, commit/push.
