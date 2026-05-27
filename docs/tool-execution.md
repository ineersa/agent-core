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

## ToolRuntime helper

`ToolRuntime` (`Ineersa\CodingAgent\Tool\ToolRuntime`) is an injectable helper
that provides two standard execution paths so tool authors do not need to
reimplement cancellation polling logic:

1. **`run(callable $callback): mixed`** — simple cancellation checkpoint wrapper.  
2. **`runCancellableProcess(Process, ...): CancellableProcessResult`** — process
   polling with cooperative cancellation and monotonic timeout.

The helper is autowired via its `StackToolExecutionContextAccessor` dependency.
All tool handlers may inject it, regardless of registration source (permanent,
dynamic, extension).

### Simple tools — `run()`

For tools that have quick, non-blocking execution but want cancellation
checkpoints before and after the main work:

```php
class MyTool implements ToolHandlerInterface
{
    public function __construct(
        private ToolRuntime $toolRuntime,
    ) {
    }

    public function __invoke(array $arguments): mixed
    {
        return $this->toolRuntime->run(function () use ($arguments) {
            // Fast synchronous work.
            return doSomething($arguments['path']);
        });
    }
}
```

If cancellation is requested before the callback, `run()` throws a
`\RuntimeException` which `ToolExecutor` catches and converts into a
structured error result with `['cancelled' => true]`. If cancellation
is detected after the callback returns, it throws with a stale-result
message.

### Long-running process tools — `runCancellableProcess()`

For tools that own a foreground `Symfony\Component\Process\Process` (bash,
patch, etc.), use `runCancellableProcess()` instead of writing a manual
polling loop:

```php
use Ineersa\CodingAgent\Tool\ToolRuntime;
use Symfony\Component\Process\Process;

class BashTool implements ToolHandlerInterface
{
    public function __construct(
        private ToolRuntime $toolRuntime,
    ) {
    }

    public function __invoke(array $arguments): mixed
    {
        $process = new Process([...]);

        $result = $this->toolRuntime->runCancellableProcess(
            $process,
            graceSeconds: 5,
            timeoutSeconds: null,        // defaults to ToolContext timeout
            pollIntervalMicros: 100_000, // 100ms
        );

        return $result->toArray();
    }
}
```

`runCancellableProcess()`:

1. Disables Symfony's built-in timeout/idle-timeout (the helper manages
   timing itself).
2. Calls `$process->start()`. Never uses `run()`/`mustRun()`.
3. Polls `$process->isRunning()` at `$pollIntervalMicros` intervals.
4. On each iteration, checks the ambient `ToolContext` cancellation token
   and a monotonic deadline computed from the effective timeout.
5. On cancellation or timeout, calls `Process::stop($graceSeconds)` which
   sends SIGTERM then SIGKILL after the grace period — the same reliable
   pattern Symfony uses internally.
6. Returns a `CancellableProcessResult` DTO with `stdout`, `stderr`,
   `exitCode`, `cancelled`, and `timedOut` properties. The handler
   calls `$result->toArray()` to produce a structured LLM response.

**Timeout resolution order:** explicit `$timeoutSeconds` parameter >
`ToolContext::timeoutSeconds()` > no timeout. Pass `null` to inherit
from the ambient context.

### Cancellation contract for tool authors

- **Every tool handler** that may take non-trivial time should cooperate
  with cancellation by using `ToolRuntime::run()` or polling the
  `CancellationTokenInterface` directly from `StackToolExecutionContextAccessor`.
- **Process-owning tools** must always use `Process::start()` + polling, not
  `run()`/`mustRun()`, so they can respond to cancellation and timeout.
- **Arbitrary blocking PHP code cannot be preemptively cancelled** from outside
  without process isolation. If a handler blocks in pure PHP (no subprocess)
  and never checks the cancellation token, `ToolExecutor` can only detect
  cancellation before the handler starts or mark the result stale after it
  returns.
- **Do not throw `ToolCancelledException` or use `CancellationGuard`.** Return
  structured results with `cancelled`/`timed_out` flags instead.`

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

## Durable batch state and parallel dispatch (TOOLS-R05)

Tool execution across multiple tool calls from one LLM step is coordinated
by `ToolBatchCollector`, which persists batch state to a shared SQLite
database (`tool_batch_state` table in the messenger database).

### How it works

1. **LlmStepResultHandler** creates `ExecuteToolCall` messages for each tool
   call and registers them via `ToolBatchCollector::registerExpectedBatch()`.
   This persists all calls with their order, mode, and parallelism settings.

2. **Initial dispatch:** `ToolBatchCollector` returns the first subset of
   calls to dispatch immediately (respecting sequential barriers and
   `max_parallelism`). These are sent to the `tool` transport.

3. **Multiple tool workers:** The controller launches N `messenger:consume tool`
   workers matching `max_parallelism` (default 4). Each worker picks up an
   `ExecuteToolCall` from the transport queue, executes it via
   `ToolExecutor`, and dispatches a `ToolCallResult` on `agent.command.bus`.

4. **Result collection:** `ToolCallResultHandler` calls
   `ToolBatchCollector::collect()` which:
   - Loads batch state from the durable store (finds it even in a different
     consumer process)
   - Records the completed result
   - Unblocks subsequent calls (sequential barriers, parallelism slots)
   - Returns new `ExecuteToolCall` effects to dispatch

5. **Out-of-order completion:** Results are stored in the `tool_batch_state`
   table. When the batch is complete, results are sorted by `orderIndex`
   before being committed to the run state, preserving model-visible order.

### Durable store architecture

```
ToolBatchStoreInterface          ← AgentCore contract (no infrastructure deps)
  ├── InMemoryToolBatchStore     ← Default/fallback, used in tests
  └── DbalToolBatchStore         ← Doctrine DBAL/SQLite production impl
        └── tool_batch_state table in messenger DB
```

- `DbalToolBatchStore` creates its table lazily (`CREATE TABLE IF NOT EXISTS`)
- Batch state is stored as a single JSON blob per run/turn/step (primary key:
  `run_id + turn_no + step_id`)
- On cache miss (different consumer process), batch is loaded from store and
  `ExecuteToolCall`/`ToolCallResult` objects are reconstructed from stored
  serialized call data
- Run-level locking through `RunLockManager` serializes concurrent batch
  state updates per run ID

### Worker count configuration

The number of tool consumer workers launched by `HeadlessController` defaults
to `max_parallelism` from `tools.execution` settings. This can be overridden
with the `$toolWorkerCount` constructor parameter if needed.

See `docs/settings.md` → `tools.execution.max_parallelism`.

## No shared foreground process management

After TOOLS-00, there is no central foreground PID registry, process runner, or cross-process cancellation routing. Each tool handler manages its own process lifecycle. Background tools (future) will own durable background process tracking separately.
