# Stage 14 — Refactor: Extract Shared Message Hydration

## Goal

Eliminate the 3x duplicated `hydrateMessage()` private method by extracting it into a single shared location.

## Problem

Three classes contain **identical** private methods that convert `array $payload → ?AgentMessage`:

| Class | Location |
|---|---|
| `Application\Orchestrator\RunOrchestrator` | line 1878 |
| `Application\Reducer\RunReducer` | line 153 |
| `Infrastructure\SymfonyAi\Platform` | line 287 |

Each reimplements the same logic: extract role, content parts, timestamp, name from a raw array and construct an `AgentMessage` value object. This is divergent-evolution risk — any fix or enhancement must be applied 3 times.

## Solution

### Option A: Factory method on `AgentMessage` (recommended)

Add a named constructor to `AgentMessage` itself — it's a value object and the natural owner of its own construction logic:

```php
// Domain/Message/AgentMessage.php
readonly final class AgentMessage
{
    public static function fromPayload(array $payload): ?self
    {
        $role = $payload['role'] ?? null;
        $rawContent = $payload['content'] ?? null;

        if (!\is_string($role) || !\is_array($rawContent)) {
            return null;
        }

        $content = [];
        foreach ($rawContent as $contentPart) {
            if (\is_array($contentPart)) {
                $content[] = $contentPart;
            }
        }

        $timestamp = null;
        if (\is_string($payload['timestamp'] ?? null)) {
            try {
                $timestamp = new \DateTimeImmutable($payload['timestamp']);
            } catch (\Throwable) {}
        }

        return new self(
            role: $role,
            content: $content,
            timestamp: $timestamp,
            name: \is_string($payload['name'] ?? null) ? $payload['name'] : null,
        );
    }
}
```

All three call sites become: `AgentMessage::fromPayload($payload)`

### Option B: Dedicated factory class

Create `Domain/Message/AgentMessageFactory` — overkill for a single static method on a value object. Only if `AgentMessage` should stay a pure data holder with no methods.

## Steps

1. Add `AgentMessage::fromPayload()` factory method
2. Add unit test for `AgentMessage::fromPayload()` covering edge cases:
   - null payload fields → null
   - missing role → null
   - content with non-array parts → filtered
   - valid timestamp → parsed
   - invalid timestamp → null
   - optional name → included or null
3. Replace `RunOrchestrator::hydrateMessage()` with `AgentMessage::fromPayload()`
4. Replace `RunReducer::hydrateMessage()` with `AgentMessage::fromPayload()`
5. Replace `Platform::hydrateMessage()` with `AgentMessage::fromPayload()`
6. Delete the 3 private methods
7. Run `castor dev:check`

## Scope

- 3 files changed (call sites)
- 1 file changed (AgentMessage — add factory method)
- 1 new test file (or extend existing `AgentMessage` tests if they exist)
- No contract changes, no new classes, no wiring changes

## Risks

- None. This is a pure extraction with identical behavior.
- The `AgentMessage` value object is in `Domain\Message` which is framework-agnostic — a static factory method is fine here.
