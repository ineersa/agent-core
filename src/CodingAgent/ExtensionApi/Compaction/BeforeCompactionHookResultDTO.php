<?php

declare(strict_types=1);

namespace Ineersa\Hatfield\ExtensionApi\Compaction;

/**
 * Public before-compaction result for extension hooks.
 *
 * Semantics match the internal compaction hook aggregation:
 * - cancelReason: non-null → cancel compaction with actionable reason
 * - replacementSummary: non-empty → skip normal LLM summarization
 * - additionalInstructions: appended to custom instructions
 * - metadata: JSON-safe map only (sanitised by dispatcher before persistence)
 */
final class BeforeCompactionHookResultDTO
{
    /**
     * @param array<string, mixed> $metadata
     */
    public function __construct(
        public ?string $cancelReason = null,
        public ?string $replacementSummary = null,
        public ?string $additionalInstructions = null,
        public array $metadata = [],
    ) {
        if (null !== $this->cancelReason && '' === trim($this->cancelReason)) {
            throw new \InvalidArgumentException('BeforeCompactionHookResultDTO.cancelReason must be null or non-empty.');
        }
        if (null !== $this->replacementSummary && '' === trim($this->replacementSummary)) {
            throw new \InvalidArgumentException('BeforeCompactionHookResultDTO.replacementSummary must be null or non-empty.');
        }
        if (null !== $this->additionalInstructions && '' === trim($this->additionalInstructions)) {
            throw new \InvalidArgumentException('BeforeCompactionHookResultDTO.additionalInstructions must be null or non-empty.');
        }
        $this->assertJsonSafe($this->metadata, 'metadata');
    }

    public static function continue(): self
    {
        return new self();
    }

    public static function cancel(string $reason): self
    {
        return new self(cancelReason: $reason);
    }

    public static function replaceSummary(string $summaryText): self
    {
        return new self(replacementSummary: $summaryText);
    }

    public function cancels(): bool
    {
        return null !== $this->cancelReason;
    }

    public function hasReplacementSummary(): bool
    {
        return null !== $this->replacementSummary && '' !== trim($this->replacementSummary);
    }

    public function hasAdditionalInstructions(): bool
    {
        return null !== $this->additionalInstructions && '' !== trim($this->additionalInstructions);
    }

    private function assertJsonSafe(mixed $value, string $path): void
    {
        if (null === $value || \is_bool($value) || \is_int($value) || \is_string($value)) {
            return;
        }

        if (\is_float($value)) {
            // INF/NAN are not JSON-encodable; reject before they reach lifecycle events.
            if (!is_finite($value)) {
                throw new \InvalidArgumentException(\sprintf('BeforeCompactionHookResultDTO.%s must be a finite float; got non-finite float.', $path));
            }

            return;
        }

        if (!\is_array($value)) {
            throw new \InvalidArgumentException(\sprintf('BeforeCompactionHookResultDTO.%s must be JSON-safe; got %s.', $path, get_debug_type($value)));
        }

        foreach ($value as $key => $child) {
            $this->assertJsonSafe($child, $path.'['.(string) $key.']');
        }
    }
}
