# CommandHandlerInterface

**File:** `CommandHandlerInterface.php`
**Namespace:** `Ineersa\AgentCore\Contract\Extension`

Extension seam for handling custom command kinds. Extensions declare which command `kind` they support and map the payload to domain events.

## Methods

| Method | Signature | Description |
|--------|-----------|-------------|
| `supports` | `supports(string $kind): bool` | Does this handler process the given command kind? |
| `supportsCancelSafe` | `supportsCancelSafe(string $kind): bool` | Can this handler safely handle cancellation? |
| `map` | `map(string $runId, string $kind, array $payload, array $options = []): list<object>` | Map a command payload to zero or more events. |

# EventSubscriberInterface

**File:** `EventSubscriberInterface.php`
**Namespace:** `Ineersa\AgentCore\Contract\Extension`

Extension seam for subscribing to domain events. Subscribers declare event types and handle them.

## Methods

| Method | Signature | Description |
|--------|-----------|-------------|
| `subscribedEventTypes` | `static subscribedEventTypes(): list<string>` | Core lifecycle types or extension types prefixed with `ext_`. |
| `onEvent` | `onEvent(RunEvent $event): void` | Handle an incoming event. |

# HookSubscriberInterface

**File:** `HookSubscriberInterface.php`
**Namespace:** `Ineersa\AgentCore\Contract\Extension`

Extension seam for subscribing to boundary hooks. Subscribers declare hook names and handle invocations.

## Methods

| Method | Signature | Description |
|--------|-----------|-------------|
| `subscribedHooks` | `static subscribedHooks(): list<string>` | Hook names from `BoundaryHookName::ALL` or extension hooks. |
| `handle` | `handle(string $hookName, array $context): array` | Handle hook invocation, return modified context. |
