<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Agent\Execution;

use Ineersa\AgentCore\Contract\EventStoreInterface;
use Ineersa\AgentCore\Domain\Event\RunEventTypeEnum;
use Ineersa\CodingAgent\Extension\ChildRun\Metadata\RunStartedMetadataDTO;
use Ineersa\CodingAgent\Extension\ChildRunExtensionAllowlistReaderInterface;
use Symfony\Component\Serializer\Normalizer\DenormalizerInterface;

/**
 * Reads immutable RunStarted launch metadata from canonical events.
 *
 * Hot child/parent classification belongs on {@see \Ineersa\CodingAgent\Repository\RunRelationshipReader}.
 * This reader keeps only launch policy details that are not in the operational projection:
 * allowed tools/extensions and child model/reasoning.
 *
 * Successfully decoded metadata is immutable, so this reader keeps a process-local
 * bounded positive-only cache keyed by run ID. Missing metadata is never cached.
 */
final class RunStartedMetadataReader implements ChildRunExtensionAllowlistReaderInterface
{
    private const int CACHE_LIMIT = 64;

    /** @var array<string, RunStartedMetadataDTO> */
    private array $resolved = [];

    public function __construct(
        private EventStoreInterface $eventStore,
        private DenormalizerInterface $denormalizer,
    ) {
    }

    /**
     * @return list<string>|null
     */
    public function readAllowedTools(string $runId): ?array
    {
        $metadata = $this->readRunStartedMetadata($runId);
        if (null === $metadata) {
            return null;
        }

        return $metadata->allowedToolsForChild();
    }

    /**
     * @return list<string>|null
     */
    public function readAllowedExtensions(string $runId): ?array
    {
        $metadata = $this->readRunStartedMetadata($runId);
        if (null === $metadata) {
            return null;
        }

        return $metadata->allowedExtensionsForChild();
    }

    public function readRunStartedMetadata(string $runId): ?RunStartedMetadataDTO
    {
        if (isset($this->resolved[$runId])) {
            return $this->resolved[$runId];
        }

        $event = $this->eventStore->firstFor($runId);
        if (null === $event || RunEventTypeEnum::RunStarted->value !== $event->type) {
            return null;
        }

        $metadata = $this->denormalizer->denormalize($event->payload, RunStartedMetadataDTO::class);
        $this->remember($runId, $metadata);

        return $metadata;
    }

    private function remember(string $runId, RunStartedMetadataDTO $metadata): void
    {
        if (\count($this->resolved) >= self::CACHE_LIMIT) {
            array_shift($this->resolved);
        }

        $this->resolved[$runId] = $metadata;
    }
}
