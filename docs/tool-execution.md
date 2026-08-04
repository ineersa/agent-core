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

### Timeout / cancellation contract (`ToolExecutor`)

`ToolExecutor` builds a `ToolContext` for every invocation containing:

- run/turn/tool identity
- a cooperative `CancellationTokenInterface`
- an optional cooperative `timeoutSeconds` budget when the call has an **explicit per-tool** timeout

There is **no global tool timeout setting**. Ambient `timeoutSeconds` comes only from
explicit/per-tool registration metadata:

`ToolRegistrationDTO` / `ToolDefinitionDTO` → registry → `ActiveToolSet` → `ToolCall::$timeoutSeconds` → `ToolContext` / public extension context.

Null means no ambient deadline. Tool-owned deadlines remain separate and authoritative:

- **Bash:** `tools.bash.default_timeout_seconds`, per-call timeout arg, process supervision, Escape cancellation
- **subagent/fork:** `agents.subagent_tool_timeout_seconds` and durable child-run cancellation
- **MCP:** fixed SDK/transport request timeout (in-flight cooperative cancel is separate work)
- **ToolRuntime:** explicit `timeoutSeconds` argument or ambient `ToolContext` budget when set
- **Extensions:** optional `ToolRegistrationDTO::$timeoutSeconds` plus public cancellation token

**Potentially blocking tools MUST own and enforce a timeout/deadline**, poll cancellation
as applicable, and clean up owned resources/processes/locks before returning cancelled or
timed-out results. Short finite tools need only before/after cancellation checkpoints.

Important limits:

- **`timeoutSeconds` is cooperative metadata**, not a kill guarantee.
- **`ToolExecutor` does not rewrite a successful handler result** into a timeout after the handler returns, even when wall-clock duration exceeds a budget.
- Cancellation is checked before start and after return (stale/cancelled marking). In-flight interruption requires the tool to poll the token or use a tool-owned process/deadline path.
- Arbitrary non-cooperative pure PHP cannot be force-preempted without process isolation; elapsed time alone is never fabricated into a timeout failure.
- Duration is always recorded as telemetry (`duration_ms`).

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
  returns. A successful late return is still success; elapsed time alone does
  not become a fabricated timeout failure.
- **Do not throw `ToolCancelledException` or use `CancellationGuard`.** Return
  structured results with `cancelled`/`timed_out` flags instead.

Key patterns:
- **No `run()`/`mustRun()`** for cancellable commands.
- **No `SIGTERM` as the second argument to `stop()`** — `stop($graceSeconds)` already sends SIGTERM then SIGKILL after the grace period.
- **No shared foreground process registry/runner** — each tool owns its process locally.
- **No `ToolCancelledException` or `CancellationGuard`** — return structured results on cancellation instead.

### Extension authors — public cancellation / deadline contract

Public extension tools should prefer `ContextualExtensionToolHandlerInterface`
when they need ambient identity or cooperative control. The public DTO is:

```php
final readonly class ToolInvocationContextDTO
{
    public function __construct(
        public string $runId,
        public ?ToolCancellationTokenInterface $cancellationToken = null,
        public ?int $timeoutSeconds = null,
    ) {}
}
```

Register optional per-tool budgets with `ToolRegistrationDTO::$timeoutSeconds`.
That value flows through the extension registry bridge into
`ToolDefinitionDTO` / `ActiveToolSet` and then into the ambient context.

#### Poll cancellation and compute a monotonic deadline

```php
use Ineersa\Hatfield\ExtensionApi\Tool\ContextualExtensionToolHandlerInterface;
use Ineersa\Hatfield\ExtensionApi\Tool\ToolInvocationContextDTO;

final class LongPollTool implements ContextualExtensionToolHandlerInterface
{
    public function __invoke(array $arguments, ToolInvocationContextDTO $context): mixed
    {
        $deadline = null;
        if (null !== $context->timeoutSeconds && $context->timeoutSeconds > 0) {
            $deadline = hrtime(true) + ($context->timeoutSeconds * 1_000_000_000);
        }

        while (/* work remaining */) {
            if ($context->cancellationToken?->isCancellationRequested()) {
                // release owned resources first
                return ['cancelled' => true, 'message' => 'Cancelled by user/runtime.'];
            }

            if (null !== $deadline && hrtime(true) > $deadline) {
                // release owned resources first
                return ['timed_out' => true, 'timeout_seconds' => $context->timeoutSeconds];
            }

            // do one unit of work / short sleep / nonblocking poll
        }

        return ['ok' => true];
    }
}
```

#### Interruptible Symfony Process

For subprocesses, use `Process::start()` + poll/stop (or host helpers that do).
Never block on `run()`/`mustRun()` if Escape/timeout must stop the process:

```php
$process->start();
while ($process->isRunning()) {
    if ($context->cancellationToken?->isCancellationRequested()) {
        $process->stop(5.0); // SIGTERM then SIGKILL after grace
        return ['cancelled' => true];
    }
    if (null !== $deadline && hrtime(true) > $deadline) {
        $process->stop(5.0);
        return ['timed_out' => true, 'timeout_seconds' => $context->timeoutSeconds];
    }
    usleep(100_000);
}
```

Prefer the host exec helper when available: `$api->exec()` accepts optional
`ExecOptionsDTO::$cancellationToken` and returns `ExecResultDTO::$cancelled` /
`$timedOut` after stopping the owned child. Effective timeout is the options
`timeout` value; combine with remaining invocation budget in the caller when
both apply.

#### Cancellable / nonblocking lock loops

Prefer nonblocking acquisition with retry rather than unbounded `flock()`:

```php
while (true) {
    if ($context->cancellationToken?->isCancellationRequested()) {
        return ['cancelled' => true];
    }
    if (null !== $deadline && hrtime(true) > $deadline) {
        return ['timed_out' => true];
    }
    if (flock($fh, LOCK_EX | LOCK_NB)) {
        break;
    }
    usleep(50_000);
}
// ... work ...
flock($fh, LOCK_UN);
```

#### Structured outcomes and cleanup

- Return structured maps with `cancelled` and/or `timed_out` (plus optional
  `timeout_seconds` / message). Do not rely on exceptions for normal cancel/timeout.
- Always release owned resources (locks, temp files, child processes) **before**
  returning a cancelled/timed-out result.
- Short finite operations (single small file read, pure DTO mapping) need only
  before/after cancellation checks — do not wrap them in subprocess isolation.
- Blocking pure PHP that never polls remains non-interruptible; Escape can only
  mark the eventual result stale after return.

### Large output

Large text output should flow through `OutputCap` before returning to the model:

```php
$output = $this->outputCap->cap($process->getOutput(), 'tool_output');
return ['output' => $output];
```

## Durable batch state and parallel dispatch (TOOLS-R05)

Tool execution across multiple tool calls from one LLM step is coordinated
by `ToolBatchCollector`, which persists batch state through
`SessionToolBatchStore` as session-scoped JSON snapshot files under
`.hatfield/sessions/<runId>/runtime/tool-batches/` (child agent runs use the
parent artifact tree, not a pseudo-session directory).

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

5. **Out-of-order completion:** Results are stored in the durable batch
   snapshot for the active `(run_id, turn_no, step_id)`. When the batch is
   complete, results are sorted by `orderIndex` before being committed to the
   run state, preserving model-visible order.

### Durable store architecture

```
ToolBatchStoreInterface          ← AgentCore contract (no infrastructure deps)
  └── SessionToolBatchStore      ← Production: atomic JSON snapshots on disk
        └── .hatfield/sessions/<runId>/runtime/tool-batches/<turn>_<stepHash>.json
            (child runs: parent artifact dir …/runtime/tool-batches/)
```

- One JSON envelope per `(run_id, turn_no, step_id)` with embedded identity
  fields and a `batch_state` blob
- Atomic same-filesystem temp write + rename; run-scoped and per-snapshot
  Symfony locks coordinate cross-process read/modify/write
- On cache miss (different consumer process), batch is loaded from the snapshot
  file and `ExecuteToolCall`/`ToolCallResult` objects are reconstructed from
  stored typed ToolBatchStateDTO persistence
- Run-level locking through `RunLockManager` wraps result handling, so the
  collector/store read-modify-write sequence is serialized per run ID
- Transient snapshots are deleted after successful `ToolBatchCommitted` or
  terminal `AgentEnd` via `ToolBatchSnapshotCleanupHookSubscriber` (post-commit)

If a tool worker permanently dies after claiming an `ExecuteToolCall` but
before producing a `ToolCallResult`, that call remains `in_flight` until the
transport retries or failure handling produces a terminal result. TOOLS-R05
does not add a foreground process registry or heartbeat; process-owning tools
still expose cancellation through `ToolContext`/`ToolRuntime`, and stuck-worker
diagnostics/cleanup should be handled with Messenger retry/failure tooling or a
future store GC/diagnostic command.

### Worker count configuration

The number of tool consumer workers launched by `HeadlessController` defaults
to `max_parallelism` from `tools.execution` settings. This can be overridden
with the `$toolWorkerCount` constructor parameter if needed.

The number of **llm** consumer workers is a separate fixed pool
(`runtime.llm_worker_count`, default 4, range 1..8) launched via
`ConsumerSupervisor::launchMultiple('llm', count)`. It is not derived from
tool max_parallelism or agents.max_agents.

See `docs/settings.md` → `tools.execution.max_parallelism` and
`runtime.llm_worker_count`.

## No shared foreground process management

After TOOLS-00, there is no central foreground PID registry, process runner, or cross-process cancellation routing. Each tool handler manages its own process lifecycle. Background tools (future) will own durable background process tracking separately.
