# ToolExecutionPolicyResolver

**File:** `ToolExecutionPolicyResolver.php`
**Namespace:** `Ineersa\AgentCore\Application\Handler`
**Type:** `final readonly class`

Resolves `ToolExecutionPolicy` per tool name by applying per-tool overrides on top of global defaults.

## Constructor

```
__construct(
    string $defaultMode,          // Global default mode (e.g. 'sequential')
    int $defaultTimeoutSeconds,   // Global default timeout
    int $maxParallelism,          // Global max parallel dispatch count
    array $overrides = [],        // Per-tool: ['tool_name' => ['mode' => 'parallel', 'timeout_seconds' => 120]]
)
```

## Key Methods

| Method | Returns | Description |
|--------|---------|-------------|
| `resolve(string $toolName)` | `ToolExecutionPolicy` | Resolve policy for a tool, applying per-tool overrides |

## Behavior

- Falls back to global defaults when no per-tool override exists
- Normalizes mode string to `ToolExecutionMode` enum, defaults to `Sequential`
- Enforces minimum timeout of 1 second
- Enforces minimum maxParallelism of 1
