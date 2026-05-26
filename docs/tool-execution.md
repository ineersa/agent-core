# Tool Execution

## Architecture overview

```
LLM step (ExecuteLlmStep)
  → LlmPlatformAdapter / DynamicToolDescriptionProcessor
      → ToolSetResolverInterface resolves toolsRef → ActiveToolSet
      → RegistryBackedToolbox::getTools() provides Tool[] for provider schemas
  → LlmStepResultHandler creates ExecuteToolCall messages
      → toolsRef propagated from LlmStepResult into ExecuteToolCall
  → Messenger tool worker (ExecuteToolCallWorker)
      → ToolExecutor::execute()
          → allowlist check from ToolSetResolverInterface + toolsRef
          → FaultTolerantToolbox wraps RegistryBackedToolbox
              → RegistryBackedToolbox::execute()
                  → looks up ToolDefinitionDTO from ToolRegistryInterface
                  → invokes ToolHandlerInterface::__invoke($arguments)
                  → returns ToolResult
```

## Toolbox interface

`RegistryBackedToolbox` implements `Symfony\AI\Agent\Toolbox\ToolboxInterface`:

- **getTools()**: iterates `ToolRegistryInterface::activeToolDefinitions()`, converts each `ToolDefinitionDTO` to a Symfony `Tool` DTO with an `ExecutionReference` pointing to the handler's `__invoke` method.
- **execute()**: looks up the tool's `ToolDefinitionDTO` by name from the registry and calls the stored `ToolHandlerInterface` handler with the decoded tool call arguments.

All three registration sources are callable through this single path:
- **Permanent tools** registered via `ToolRegistry::registerTool()`
- **Dynamic tools** registered via `ToolRegistry::addDynamicTool()` or `setDynamicTools()`
- **Extension-registered tools** registered through `ExtensionToolRegistryBridge` (which calls `registerTool()` internally)

## Execution allowlist

`ToolExecutor` enforces an execution allowlist by resolving the `toolsRef` from the tool call context through `ToolSetResolverInterface`. Both the provider schemas and the execution allowlist derive from the same `ActiveToolSet` snapshot:

1. `AdvanceRunHandler` generates a `toolsRef` string (`sprintf('toolset:run:%s:turn:%d', $runId, $nextTurnNo)`)
2. `ExecuteLlmStep` carries the `toolsRef` to the LLM worker
3. `LlmPlatformAdapter` passes it through `Input` options for schema filtering
4. `DynamicToolDescriptionProcessor` resolves it via `ToolSetResolverInterface` to filter provider schemas
5. `LlmStepResult` and `ExecuteToolCall` propagate the same `toolsRef`
6. `ExecuteToolCallWorker` places `tools_ref` in the `ToolCall` context
7. `ToolExecutor::executeToolCall()` resolves the `toolsRef` through `ToolSetResolverInterface` and checks the tool name against `ActiveToolSet::allowListNames`

When a tool call arrives for a name not in the allowlist, `ToolExecutor` returns a structured error result:

```php
[
    'isError' => true,
    'details' => [
        'denied' => true,
        'reason' => 'not_in_active_allowlist',
        'tools_ref' => '<the toolsRef>',
        'available_tools' => ['tool1', 'tool2'],
    ],
    'content' => 'Tool "<name>" is not in the active execution allowlist. Available tools: tool1, tool2',
]
```

## Handler contract

Tool handlers implement `ToolHandlerInterface`:

```php
interface ToolHandlerInterface
{
    public function __invoke(array $arguments): mixed;
}
```

Handlers run synchronously inside a Messenger `tool` consumer process. Common concerns:

### Accessing run/tool metadata

Tools that need run ID, turn number, tool call ID, timeout, or cancellation token inject `StackToolExecutionContextAccessor` and call `requireCurrent()`:

```php
class MyTool implements ToolHandlerInterface
{
    public function __construct(
        private StackToolExecutionContextAccessor $contextAccessor,
    ) {
    }

    public function __invoke(array $arguments): mixed
    {
        $context = $this->contextAccessor->requireCurrent();

        if ($context->cancellationToken()->isCancellationRequested()) {
            return ['cancelled' => true, 'message' => 'Cancelled before start.'];
        }

        // Use $context->timeoutSeconds(), $context->turnNo(), etc.
    }
}
```

### Long-running process tools (bash, patch, etc.)

Tool handlers that own a foreground Symfony Process should NOT use `run()` or `mustRun()` because those methods block until the process finishes with no cancellation support. Instead, use `start()` with a polling loop:

```php
public function __invoke(array $arguments): mixed
{
    $context = $this->contextAccessor->requireCurrent();
    $cancelToken = $context->cancellationToken();
    $timeoutSeconds = $context->timeoutSeconds();

    $process = new Process([...]);
    $process->setTimeout(null);         // We manage timeout ourselves.
    $process->setIdleTimeout(null);
    $process->start();

    $deadline = $timeoutSeconds > 0
        ? hrtime(true) + $timeoutSeconds * 1_000_000_000
        : null;

    while ($process->isRunning()) {
        // Cooperative cancellation check.
        if ($cancelToken->isCancellationRequested()) {
            $process->stop(5);   // SIGTERM → SIGKILL after 5s grace
            return ['cancelled' => true, 'stdout' => $process->getOutput()];
        }

        // Monotonic timeout check.
        if (null !== $deadline && hrtime(true) > $deadline) {
            $process->stop(5);
            return ['timed_out' => true, 'stdout' => $process->getOutput()];
        }

        // Small sleep to avoid busy-wait.
        usleep(100_000); // 100ms
    }

    return [
        'stdout' => $process->getOutput(),
        'stderr' => $process->getErrorOutput(),
        'exit_code' => $process->getExitCode(),
    ];
}
```

Key patterns:
- **No `run()`/`mustRun()`** for cancellable commands.
- **No `SIGTERM` as the second argument to `stop()`** — `stop($graceSeconds)` already sends SIGTERM then SIGKILL after the grace period.
- **No shared foreground process registry/runner** — each tool owns its process locally.
- **No `ToolCancelledException` or `CancellationGuard`** — return structured results on cancellation instead.

### Large output

Large text output should flow through `OutputCap` before returning to the model:

```php
$output = $this->outputCap->cap($process->getOutput(), 'tool_output');
return ['output' => $output];
```

## No shared foreground process management

After TOOLS-00, there is no central foreground PID registry, process runner, or cross-process cancellation routing. Each tool handler manages its own process lifecycle. Background tools (future) will own durable background process tracking separately.
