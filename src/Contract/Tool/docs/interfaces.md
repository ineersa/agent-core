# PlatformInterface

**File:** `PlatformInterface.php`
**Namespace:** `Ineersa\AgentCore\Contract\Tool`

LLM platform adapter. Invokes a model with input/options and returns the raw provider response array.

## Methods

| Method | Signature | Description |
|--------|-----------|-------------|
| `invoke` | `invoke(string $model, array $input, array $options = []): array` | Execute an LLM call. |

# ModelResolverInterface

**File:** `ModelResolverInterface.php`
**Namespace:** `Ineersa\AgentCore\Contract\Tool`

Resolves which model to use based on default model, message context, and options. Returns a `ResolvedModel`.

## Methods

| Method | Signature | Description |
|--------|-----------|-------------|
| `resolve` | `resolve(string $defaultModel, MessageBag $messages, array $context = [], array $options = []): ResolvedModel` | Determine the model for this request. |

# ToolCatalogProviderInterface

**File:** `ToolCatalogProviderInterface.php`
**Namespace:** `Ineersa\AgentCore\Contract\Tool`

Provides the available tool definitions (catalog) for a given context.

## Methods

| Method | Signature | Description |
|--------|-----------|-------------|
| `resolveToolCatalog` | `resolveToolCatalog(array $context = []): list<ToolDefinition>` | Return available tools. |

# ToolExecutorInterface

**File:** `ToolExecutorInterface.php`
**Namespace:** `Ineersa\AgentCore\Contract\Tool`

Executes a single tool call and returns the result.

## Methods

| Method | Signature | Description |
|--------|-----------|-------------|
| `execute` | `execute(ToolCall $toolCall): ToolResult` | Run a tool call. |

# ToolIdempotencyKeyResolverInterface *(Stage 06)*

**File:** `ToolIdempotencyKeyResolverInterface.php`
**Namespace:** `Ineersa\AgentCore\Contract\Tool`

Optional stronger idempotency key contract. When implemented, `ToolExecutor` uses this to resolve a deterministic idempotency key from a `ToolCall` for result deduplication across runs.

## Methods

| Method | Signature | Description |
|--------|-----------|-------------|
| `resolveToolIdempotencyKey` | `resolveToolIdempotencyKey(ToolCall $toolCall): ?string` | Resolve an idempotency key for the given tool call. |
