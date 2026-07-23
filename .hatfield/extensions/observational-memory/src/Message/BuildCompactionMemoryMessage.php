<?php

declare(strict_types=1);

namespace Ineersa\HatfieldExt\ObservationalMemory\Message;

/**
 * Operational message: produce compaction replacement memory.
 *
 * Reflector model calls are OM-04; this preview can persist empty/success scaffolding.
 */
final readonly class BuildCompactionMemoryMessage
{
    /**
     * @param list<array{
     *   reflection_id?: string,
     *   content: string,
     *   compression_level?: string,
     *   token_count?: int,
     *   supporting_observation_ids?: list<string>
     * }> $reflections
     */
    public function __construct(
        public string $requestId,
        public string $runId,
        public int $requiredStartSeq,
        public int $requiredEndSeq,
        public int $requiredWatermark,
        public string $observationSetHash,
        public string $reflectorModel,
        public string $reflectorSchemaVersion,
        public ?string $replacementText = null,
        public array $reflections = [],
        public string $status = 'completed',
        public ?string $failureCode = null,
    ) {
        if ('' === $this->requestId || '' === $this->runId) {
            throw new \InvalidArgumentException('BuildCompactionMemoryMessage requires requestId and runId.');
        }
    }
}
