<?php

declare(strict_types=1);

namespace Symfony\AI\Platform\Bridge\OpenAICodex;

/**
 * Remembers harness-authored Astra configuration_update items by prompt-cache family.
 *
 * Full-history and SSE requests re-apply previously emitted transitions at their
 * original conversation offsets so later appends do not move them. Cached
 * WebSocket continuation still sends only a newly changed update on the delta.
 */
class CodexReasoningTransitionLedger
{
    /**
     * @var array<string, list<array{after: int, effort: string}>>
     */
    private array $byFamily = [];

    /**
     * @return list<array{after: int, effort: string}>
     */
    public function transitions(string $promptCacheKey): array
    {
        return $this->byFamily[$promptCacheKey] ?? [];
    }

    public function remember(string $promptCacheKey, int $after, string $effort): void
    {
        $existing = $this->transitions($promptCacheKey);
        foreach ($existing as $transition) {
            if ($transition['after'] === $after && $transition['effort'] === $effort) {
                return;
            }
        }

        $existing[] = ['after' => $after, 'effort' => $effort];
        $this->byFamily[$promptCacheKey] = $existing;
    }

    public function forget(string $promptCacheKey): void
    {
        unset($this->byFamily[$promptCacheKey]);
    }
}
