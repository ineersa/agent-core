<?php

declare(strict_types=1);

namespace Ineersa\HatfieldExt\ObservationalMemory\Message;

/**
 * Operational message: observe one conversation boundary.
 *
 * Handlers in this preview persist zero-observation coverage only when no
 * observations are supplied. Observer model calls are OM-03.
 */
final readonly class ObserveBoundaryMessage
{
    /**
     * @param list<array{
     *   observation_id?: string,
     *   content: string,
     *   relevance?: int,
     *   token_count?: int,
     *   source_refs?: list<array<string, mixed>>
     * }> $observations
     */
    public function __construct(
        public string $runId,
        public string $boundaryKey,
        public int $sourceStartSeq,
        public int $sourceEndSeq,
        public string $sourceDigest,
        public string $rendererVersion,
        public string $observerSchemaVersion,
        public string $observerModel,
        public array $observations = [],
    ) {
        if ('' === $this->runId || '' === $this->boundaryKey) {
            throw new \InvalidArgumentException('ObserveBoundaryMessage requires runId and boundaryKey.');
        }
        if ($this->sourceStartSeq < 0 || $this->sourceEndSeq < $this->sourceStartSeq) {
            throw new \InvalidArgumentException('ObserveBoundaryMessage has invalid sequence range.');
        }
    }

    public function coverageKey(): string
    {
        return hash('sha256', implode('|', [
            $this->runId,
            $this->boundaryKey,
            $this->rendererVersion,
            $this->observerSchemaVersion,
        ]));
    }
}
