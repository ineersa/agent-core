<?php

declare(strict_types=1);

namespace Ineersa\Hatfield\ExtensionApi\Lifecycle;

/**
 * Immutable public context for a post-commit conversation boundary.
 *
 * Source identity is always (runId, seq). turnNo is diagnostic only and is
 * not part of the opaque boundary identity.
 */
final readonly class ConversationBoundaryDTO
{
    /**
     * @param array<string, scalar|list<mixed>|array<string, mixed>|null> $metadata JSON-safe correlation metadata only
     */
    public function __construct(
        public string $runId,
        public string $sessionId,
        public string $boundaryId,
        public ConversationBoundaryOutcomeEnum $outcome,
        public int $sourceStartSeq,
        public int $sourceEndSeq,
        public int $latestCommittedSeq,
        public \DateTimeImmutable $boundaryAt,
        public array $metadata = [],
    ) {
        if ('' === $this->runId) {
            throw new \InvalidArgumentException('runId must not be empty.');
        }
        if ('' === $this->sessionId) {
            throw new \InvalidArgumentException('sessionId must not be empty.');
        }
        if ('' === $this->boundaryId) {
            throw new \InvalidArgumentException('boundaryId must not be empty.');
        }
        if ($this->sourceStartSeq < 1) {
            throw new \InvalidArgumentException('sourceStartSeq must be >= 1.');
        }
        if ($this->sourceEndSeq < $this->sourceStartSeq) {
            throw new \InvalidArgumentException('sourceEndSeq must be >= sourceStartSeq.');
        }
        if ($this->latestCommittedSeq < $this->sourceEndSeq) {
            throw new \InvalidArgumentException('latestCommittedSeq must be >= sourceEndSeq.');
        }

        foreach ($this->metadata as $key => $value) {
            if (!\is_string($key) || '' === $key) {
                throw new \InvalidArgumentException('metadata keys must be non-empty strings.');
            }
            if (!$this->isJsonSafe($value)) {
                throw new \InvalidArgumentException(\sprintf('metadata[%s] must be JSON-safe scalar/array/null.', $key));
            }
        }
    }

    private function isJsonSafe(mixed $value): bool
    {
        if (null === $value || \is_scalar($value)) {
            return true;
        }
        if (!\is_array($value)) {
            return false;
        }
        foreach ($value as $child) {
            if (!$this->isJsonSafe($child)) {
                return false;
            }
        }

        return true;
    }
}
