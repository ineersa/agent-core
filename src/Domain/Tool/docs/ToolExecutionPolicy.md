# ToolExecutionPolicy

**File:** `ToolExecutionPolicy.php`
**Namespace:** `Ineersa\AgentCore\Domain\Tool`
**Type:** `final readonly class`

Execution policy value object carrying the resolved execution parameters for a single tool call.

## Constructor

```
__construct(
    public ToolExecutionMode $mode,
    public int $timeoutSeconds,
    public int $maxParallelism,
)
```

## Properties

| Property | Type | Description |
|----------|------|-------------|
| `mode` | `ToolExecutionMode` | Sequential, Parallel, or Interrupt |
| `timeoutSeconds` | `int` | Max wall-clock seconds before timeout error |
| `maxParallelism` | `int` | Max concurrent tool executions in a batch |

## Usage

Created by `ToolExecutionPolicyResolver`, consumed by `ToolExecutor` and `ToolBatchCollector` to gate execution behavior. ToolCall-level overrides take precedence over resolved policy.
