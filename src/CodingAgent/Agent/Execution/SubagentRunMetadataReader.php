<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Agent\Execution;

use Ineersa\AgentCore\Contract\EventStoreInterface;
use Ineersa\AgentCore\Domain\Event\RunEventTypeEnum;
use Ineersa\CodingAgent\Extension\ChildRun\Metadata\RunStartedMetadataDTO;
use Ineersa\CodingAgent\Extension\ChildRunExtensionAllowlistReaderInterface;
use Symfony\Component\Serializer\Normalizer\DenormalizerInterface;

/**
 * Reads agent child metadata from RunStarted events.
 *
 * Root RunEvent.payload is denormalized via Symfony Serializer into
 * {@see RunStartedMetadataDTO} using SerializedPath attributes.
 *
 * Successfully decoded RunStarted metadata is immutable, so this reader
 * keeps a process-local bounded positive-only cache keyed by run ID.
 * Missing metadata is never cached (it may appear later). Malformed
 * payloads still raise Serializer type errors and are not cached.
 */
final class SubagentRunMetadataReader implements ChildRunExtensionAllowlistReaderInterface
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
     * Determine whether the given run is an agent child run.
     */
    public function isAgentChild(string $runId): bool
    {
        $metadata = $this->readRunStartedMetadata($runId);

        return null !== $metadata && $metadata->isAgentChild();
    }

    /**
     * Parent session run id for a child run, or null when not a child.
     */
    public function readParentRunId(string $runId): ?string
    {
        $metadata = $this->readRunStartedMetadata($runId);
        if (null === $metadata || !$metadata->isAgentChild()) {
            return null;
        }

        return $metadata->session->parentRunId;
    }

    /**
     * Read the allowed tool list from the child's RunStarted metadata.
     *
     * Returns null when the run is not a child or the metadata is
     * not yet available.
     *
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
     * Effective child-run extension allowlist from RunStarted metadata.
     *
     * Returns null for parent runs / missing metadata. Returns an empty list
     * when the child intentionally selected zero extensions.
     *
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

    /**
     * Typed RunStarted metadata for the run, or null when no RunStarted event exists.
     * Malformed RunStarted payloads propagate Serializer type errors.
     */
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
            // Minimal FIFO eviction for long-lived Messenger workers.
            array_shift($this->resolved);
        }

        $this->resolved[$runId] = $metadata;
    }
}
