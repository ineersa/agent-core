# Stage 13 — Refactor: Tool Execution Pipeline & Symfony AI Integration

## Goal

Remove the `SymfonyToolExecutorAdapter` and simplify `ToolExecutor` by adopting Symfony AI's Toolbox natively with event-driven hooks instead of custom hook pipelines. Keep AgentCore-specific concerns (policy, caching, idempotency) as a thin orchestration layer around the Toolbox.

## Problem Statement

### Current architecture (the mess)

```
ExecuteToolCallWorker
  └── SymfonyToolExecutorAdapter  ← has its own before/after hooks + toolbox bridge + stringify + error handling
        └── ToolExecutor          ← has its OWN before/after hooks + toolbox bridge + stringify + error handling
              └── ?object $toolbox (nullable raw object)
```

Two layers of identical orchestration:
- Both `SymfonyToolExecutorAdapter::execute()` and `ToolExecutor::execute()` call `$toolbox->execute()` independently
- Both run before/after hook pipelines
- Both handle errors and format results
- Both have `stringify()`, `toSymfonyToolCall()`, `canUseSymfonyToolbox()` helpers

`ToolExecutor` is the real executor (597 lines, 18 methods). The adapter wraps it but adds nothing — it just duplicates the hook pipeline and toolbox bridge that `ToolExecutor` already has.

### What Symfony AI's Toolbox already provides

From reading the source (`/home/ineersa/projects/mate/ai/src/agent/src/Toolbox/Toolbox.php`):

| Capability | How it works | Our equivalent |
|---|---|---|
| **Tool invocation** | `Toolbox::execute(ToolCall)` → `ToolResult` | `ToolExecutor::executeToolCall()` via raw `$toolbox->execute()` |
| **Before-execution interception** | `ToolCallRequested` event — deny or set custom result to skip execution | `BeforeToolCallHookInterface` pipeline |
| **After-success hook** | `ToolCallSucceeded` event | `AfterToolCallHookInterface` pipeline |
| **After-failure hook** | `ToolCallFailed` event | Custom try/catch in `executeToolCall()` |
| **Argument resolution** | `ToolCallArgumentResolverInterface` resolves args from ToolCall against metadata | `validateArguments()` manual schema check |
| **Fault tolerance** | `FaultTolerantToolbox` decorator catches exceptions, returns error ToolResult | Custom try/catch in adapter + executor |
| **Tool metadata/schemas** | `Toolbox::getTools()` → `Tool[]` with name, description, JSON schema | `ToolCatalogResolver::resolve()` |
| **Sources** | `HasSourcesInterface` on tools, collected by `AgentProcessor` | Manual `getSources()` check in executor |

**Key insight**: `ToolCallRequested` event supports **deny** (like our `BeforeToolCallResult::block`) and **custom result** (like our cache hit / interrupt return). This maps directly to our before-hook use case.

### What Symfony AI does NOT provide (AgentCore-specific)

These are our domain concerns that stay in AgentCore:

1. **Policy resolution** (`ToolExecutionPolicyResolver`) — per-tool mode/timeout/parallelism config
2. **Result caching** (`ToolExecutionResultStore`) — run-scoped + idempotency-key-scoped caching
3. **Idempotency key resolution** (`ToolIdempotencyKeyResolverInterface`) — run+tool specific keys
4. **Execution metadata** (duration, reused flag, policy info on ToolResult) — observability
5. **Interrupt mode** (`ToolExecutionMode::Interrupt` / `ask_user`) — HITL boundary
6. **Cancellation token** (`CancellationTokenInterface`) — run-scoped cancellation
7. **Batch orchestration** (`ToolBatchCollector`) — parallel tool call tracking

## Plan

### Phase 1 — Remove `SymfonyToolExecutorAdapter`

The adapter is a dead layer. It wraps `ToolExecutor` as fallback, but when the toolbox is available, it **bypasses** `ToolExecutor` entirely and does its own execution + hooks. This means:
- When toolbox exists → adapter handles everything, `ToolExecutor` is never called
- When toolbox missing → adapter delegates to `ToolExecutor` which also can't execute (errors out)

So the adapter gains us nothing. Remove it.

**Steps**:
1. Move `toToolCallMessagePayload()` and `toProgressUpdate()` (utility methods) to where they're actually used (likely `RunOrchestrator` or API layer)
2. Delete `SymfonyToolExecutorAdapter`
3. Delete `SymfonyToolExecutorAdapterTest`
4. Wire `ToolExecutor` directly as `ToolExecutorInterface` in `config/services.php`
5. Update `ExecuteToolCallWorker` to depend on `ToolExecutorInterface` → `ToolExecutor` directly

### Phase 2 — Adopt Symfony AI Toolbox natively

Replace the raw `?object $toolbox` in `ToolExecutor` with a typed `ToolboxInterface` dependency. Use Symfony AI's event system for before/after hooks.

**New `ToolExecutor` structure**:

```php
readonly final class ToolExecutor implements ToolExecutorInterface
{
    public function __construct(
        private ToolExecutionPolicyResolver $policyResolver,
        private ToolExecutionResultStore $resultStore,
        private ?ToolIdempotencyKeyResolverInterface $idempotencyResolver,
        private ?ToolboxInterface $toolbox,                      // typed, not raw object
        private ?EventDispatcherInterface $eventDispatcher,      // for Symfony AI events
        private ?ToolCallArgumentResolverInterface $argumentResolver,
        string $defaultMode,
        int $defaultTimeoutSeconds,
        int $maxParallelism,
        array $overrides,
    ) {}

    public function execute(ToolCall $toolCall): ToolResult
    {
        $policy = $this->resolvePolicy($toolCall);
        $toolIdempotencyKey = $this->resolveIdempotencyKey($toolCall);

        // 1. Cache check (AgentCore concern)
        $cached = $this->checkCache($toolCall, $policy, $toolIdempotencyKey);
        if ($cached !== null) return $cached;

        // 2. Interrupt mode (AgentCore concern)
        if ($this->isInterrupt($toolCall, $policy)) return $this->interruptResult($toolCall);

        // 3. Cancellation check (AgentCore concern)
        if ($this->isCancelled($toolCall)) return $this->cancelResult($toolCall);

        // 4. Execute via Symfony AI Toolbox
        $result = $this->invoke($toolCall, $policy);

        // 5. Cache write + metadata (AgentCore concern)
        return $this->rememberAndReturn($toolCall, $policy, $toolIdempotencyKey, $result);
    }

    private function invoke(ToolCall $toolCall, ToolExecutionPolicy $policy): ToolResult
    {
        if (!$this->toolbox) {
            return $this->unavailableResult($toolCall, $policy);
        }

        // Use FaultTolerantToolbox to handle errors gracefully
        $toolbox = new FaultTolerantToolbox($this->toolbox);

        $symfonyToolCall = $this->toSymfonyToolCall($toolCall);
        $symfonyResult = $toolbox->execute($symfonyToolCall);

        return $this->toDomainResult($toolCall, $symfonyResult);
    }
}
```

**Hook migration**: Our `BeforeToolCallHookInterface` / `AfterToolCallHookInterface` implementations become Symfony event listeners:

| Our hook | Symfony AI event | Notes |
|---|---|---|
| `BeforeToolCallHookInterface::beforeToolCall()` | Listen to `ToolCallRequested` | Can deny (→ block) or set result (→ cache/interrupt). Map our hook return to event methods |
| `AfterToolCallHookInterface::afterToolCall()` | Listen to `ToolCallSucceeded` / `ToolCallFailed` | Can modify result content/details |

This means our hook interfaces can become thin adapters that register Symfony event listeners, or we bridge them in `ToolExecutor`:

```php
// Option A: Bridge in ToolExecutor
private function invoke(ToolCall $toolCall, ToolExecutionPolicy $policy): ToolResult
{
    // Dispatch ToolCallRequested → our before-hooks run as listeners
    // Toolbox executes → ToolCallSucceeded/Failed dispatched → our after-hooks run as listeners
    $symfonyResult = $this->toolbox->execute($symfonyToolCall);
    return $this->toDomainResult($toolCall, $symfonyResult);
}
```

**No bridge needed** — this is a Symfony bundle with a hard Symfony AI dependency. Extensions register Symfony event listeners directly via `#[AsEventListener]` or service tags. Our `BeforeToolCallHookInterface` / `AfterToolCallHookInterface` contracts are replaced by Symfony AI's `ToolCallRequested` / `ToolCallSucceeded` / `ToolCallFailed` events.

This means:
- Delete `Contract/Hook/BeforeToolCallHookInterface.php`
- Delete `Contract/Hook/AfterToolCallHookInterface.php`
- Delete `Domain/Tool/BeforeToolCallContext.php`, `BeforeToolCallResult.php`, `AfterToolCallContext.php`, `AfterToolCallResult.php`
- Delete `Application/Handler/HookSubscriberRegistry.php` (hook-specific, event subscribers use Symfony's standard registry)
- Extensions use `#[AsEventListener(event: ToolCallRequested::class)]` to intercept tool calls — same DX as any Symfony event
- The existing `HookDispatcher` / `EventSubscriberRegistry` for **non-tool** hooks (TransformContext, ConvertToLlm, BeforeProviderRequest) stays as-is

### Phase 3 — Simplify argument validation

Symfony AI's `ToolCallArgumentResolverInterface` handles argument resolution from ToolCall to method parameters. Our current `validateArguments()` does manual type checking against JSON schema — this is redundant if Symfony AI resolves args.

**Steps**:
- Remove `validateArguments()` and `matchesType()` from `ToolExecutor`
- If pre-execution argument validation is needed, do it via `ToolCallArgumentsResolved` event listener
- Schema validation is already handled by Symfony AI's JSON Schema generation (`#[With]` attributes, Symfony Validator constraints)

### Phase 4 — Clean up remaining helpers

After the above:
- Remove `stringify()` — use Symfony AI's built-in result conversion (arrays → JSON, Stringable → string)
- Remove `toSymfonyToolCall()` — can be a standalone utility or inlined
- Remove `canUseSymfonyToolbox()` — nullable typed dependency replaces this

## What Changes

| File | Action |
|---|---|
| `Infrastructure/SymfonyAi/SymfonyToolExecutorAdapter.php` | **Delete** |
| `tests/Infrastructure/SymfonyAi/SymfonyToolExecutorAdapterTest.php` | **Delete** |
| `Application/Handler/ToolExecutor.php` | **Simplify** — remove adapter concerns, type the toolbox |
| `config/services.php` | **Update** — remove adapter wiring, wire Toolbox directly |
| `Contract/Tool/ToolExecutorInterface.php` | **Keep** — stable contract |
| `Contract/Hook/BeforeToolCallHookInterface.php` | **Delete** — replaced by `ToolCallRequested` event listener |
| `Contract/Hook/AfterToolCallHookInterface.php` | **Delete** — replaced by `ToolCallSucceeded`/`ToolCallFailed` event listeners |
| `Domain/Tool/BeforeToolCallContext.php` | **Delete** — Symfony AI event carries the data |
| `Domain/Tool/BeforeToolCallResult.php` | **Delete** — `ToolCallRequested::deny()` / `setResult()` replaces it |
| `Domain/Tool/AfterToolCallContext.php` | **Delete** — Symfony AI event carries the data |
| `Domain/Tool/AfterToolCallResult.php` | **Delete** — mutate event result directly |
| `Application/Handler/HookSubscriberRegistry.php` | **Delete** — Symfony's event dispatcher replaces it |
| `Application/Handler/HookDispatcher.php` | **Keep** — still used for non-tool hooks (TransformContext, ConvertToLlm, BeforeProviderRequest) |

## What Does NOT Change

- `ToolBatchCollector` — stays as-is, operates at orchestration level
- `ToolExecutionPolicyResolver` — stays, AgentCore-specific concern
- `ToolCatalogResolver` — stays but may simplify if `Toolbox::getTools()` provides schemas directly
- `ToolExecutionResultStore` — stays, AgentCore-specific caching
- `ExecuteToolCallWorker` — depends on `ToolExecutorInterface`, no change
- Public API surface

## Migration Order

1. **Phase 1** — Remove adapter (immediate, no risk)
2. **Phase 2** — Type the toolbox + wire FaultTolerantToolbox
3. **Phase 2.5** — Bridge before/after hooks to Symfony AI events
4. **Phase 3** — Remove argument validation (verify Symfony AI handles it)
5. **Phase 4** — Clean up helpers

After each phase: `castor dev:check`

## Risks

- **Hook ordering**: Our before-hooks currently run before the toolbox call; after-hooks run after. With Symfony AI events, the lifecycle is `ToolCallRequested` → execute → `ToolCallSucceeded`/`Failed`. The mapping is 1:1.
- **CancellationToken**: Our `CancellationTokenInterface` is not Symfony AI's. We need to check cancellation **before** invoking the toolbox (in `ToolExecutor::execute()`), not during. This is how it works already.
- **FaultTolerantToolbox**: We should wire this as the toolbox implementation so tool errors become `ToolResult` instead of exceptions. Our current code catches exceptions — `FaultTolerantToolbox` does this natively.

## Open Questions

- **Toolbox as typed dependency**: Should we require `ToolboxInterface` (non-nullable) and always have it available, or keep nullable for "no tools configured" scenarios? (Recommend: non-nullable, use empty toolbox when no tools configured)
- **Argument validation**: Confirm that removing `validateArguments()` doesn't lose any safety net that Symfony AI doesn't provide.
- **Hook contract removal scope**: Are `BeforeToolCallHookInterface` / `AfterToolCallHookInterface` used by any extensions or downstream code outside this repo? If yes, we need a deprecation cycle. If no, clean delete.
